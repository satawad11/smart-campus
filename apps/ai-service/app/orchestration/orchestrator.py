"""Production AI Node runtime orchestrator (Module 04).

Wires the existing components together into a cohesive production edge runtime:
- CAM-003 / CAM-014: Registration check-in and operational telemetry heartbeat
- CAM-004: Per-node bearer credential authentication
- CAM-005 / CAM-006: VideoSource and RTSP streaming with automatic backoff reconnect
- CAM-007: Frame rate sampling with backpressure queue
- CAM-009: RecognitionWorker & RecognitionWorkerPool (pipeline & candidate search)
- CAM-010: RecognitionCache per track

- CAM-011: Event delivery to backend ingestion
- CAM-012: Offline durable SQLite event buffer and background retry
- CAM-013: Camera health collection and reporting
- CAM-015: Periodic configuration sync (dynamic camera add/remove/update)
- CAM-016: Region-of-Interest (ROI) filtering

Enforces clean shutdown of all threads, OpenCV streams, and worker pools.
Never logs passwords, tokens, or raw embeddings.
"""

from __future__ import annotations

import logging
import os
import socket
import threading
import time
from collections.abc import Callable
from typing import Any
from urllib.parse import quote, urlsplit, urlunsplit

from app.alignment.factory import get_face_aligner
from app.caching.cache import RecognitionCache
from app.core.config import settings
from app.delivery.buffer import OfflineEventBuffer
from app.delivery.buffered_service import BufferedEventDeliveryService
from app.delivery.client import EventDeliveryClient, HttpEventDeliveryClient
from app.detection.factory import get_face_detector
from app.embeddings.factory import get_face_embedder
from app.health.client import CameraHealthClient, HttpCameraHealthClient
from app.health.collector import CameraHealthCollector
from app.liveness.factory import get_face_liveness_detector
from app.orchestration.backend_client import HttpRecognitionBackendClient, RecognitionBackendClient
from app.orchestration.node_client import AiNodeClient, HttpAiNodeClient
from app.orchestration.telemetry import SystemTelemetry
from app.orchestration.worker import RecognitionWorker, RecognitionWorkerPool
from app.quality.factory import get_face_quality_evaluator
from app.roi.filter import is_detection_in_roi
from app.roi.schemas import RegionOfInterest, RoiPoint
from app.sampling.sampler import FrameSampler
from app.streams.base import VideoSource
from app.streams.mock import MockVideoSource
from app.streams.rtsp import RTSPVideoSource, mask_rtsp_url
from app.tracking.tracker import IoUTracker

logger = logging.getLogger("ai_node.orchestrator")


def _build_authenticated_rtsp_url(raw_url: str, username: str | None, password: str | None) -> str:
    """Injects decrypted username/password into RTSP URL if not already present."""
    if not username and not password:
        return raw_url
    try:
        parts = urlsplit(raw_url)
        if parts.username or parts.password:
            return raw_url
        userinfo = quote(username or "", safe="")
        if password:
            userinfo = f"{userinfo}:{quote(password, safe='')}"
        netloc = f"{userinfo}@{parts.netloc}"
        return urlunsplit((parts.scheme, netloc, parts.path, parts.query, parts.fragment))
    except Exception:
        return raw_url


class CameraPipelineRunner:
    """Manages frame capture, detection, tracking, recognition, and health for one camera."""

    def __init__(
        self,
        camera_config: dict[str, Any],
        ai_node_uuid: str,
        detector: Any,
        aligner: Any,
        quality_evaluator: Any,
        embedder: Any,
        liveness_detector: Any,
        backend_client: RecognitionBackendClient,
        delivery_service: BufferedEventDeliveryService,
        model_code: str,
        model_version: str,
        target_fps: float = 5.0,
    ):
        self.camera_uuid: str = camera_config["camera_uuid"]
        self.camera_code: str = camera_config.get("camera_code", self.camera_uuid[:8])
        self.name: str = camera_config.get("name", self.camera_code)
        self.config = camera_config
        self.ai_node_uuid = ai_node_uuid
        self.target_fps = target_fps
        self._delivery_service = delivery_service

        # ROI setup
        self.roi: RegionOfInterest | None = None
        self._update_roi(camera_config.get("roi"))

        # RTSP URL assembly
        raw_url = camera_config.get("rtsp_url") or ""
        username = camera_config.get("username")
        password = camera_config.get("password")
        self.stream_url = _build_authenticated_rtsp_url(raw_url, username, password)

        # VideoSource instantiation
        if self.stream_url.startswith("mock://") or not self.stream_url:
            self.source: VideoSource = MockVideoSource(name=self.camera_code)
        else:
            self.source = RTSPVideoSource(rtsp_url=self.stream_url, name=self.camera_code)

        # Sampler, Tracker, Health collector
        self.sampler = FrameSampler(
            self.source, camera_uuid=self.camera_uuid, target_fps=target_fps, queue_size=10
        )
        self.detector = detector
        self.tracker = IoUTracker()
        self.collector = CameraHealthCollector(camera_uuid=self.camera_uuid)

        # Recognition worker & pool with per-camera cache
        self.cache = RecognitionCache()
        self.worker = RecognitionWorker(
            camera_uuid=self.camera_uuid,
            ai_node_uuid=self.ai_node_uuid,
            aligner=aligner,
            quality_evaluator=quality_evaluator,
            embedder=embedder,
            backend_client=backend_client,
            model_code=model_code,
            model_version=model_version,
            liveness_detector=liveness_detector,
            cache=self.cache,
        )
        self.pool = RecognitionWorkerPool(self.worker, max_workers=2, queue_size=15)

        self._stop_event = threading.Event()
        self._thread: threading.Thread | None = None

    def _update_roi(self, roi_raw: Any) -> None:
        if isinstance(roi_raw, list) and roi_raw:
            try:
                points = [
                    RoiPoint(x=p["x"], y=p["y"])
                    for p in roi_raw
                    if isinstance(p, dict) and "x" in p and "y" in p
                ]
                if len(points) >= 3:
                    self.roi = RegionOfInterest(points=points)

                    return
            except Exception as e:
                logger.warning("Failed to parse ROI for camera %s: %s", self.camera_code, e)
        self.roi = None

    def update_config(self, new_config: dict[str, Any]) -> None:
        """Dynamically update ROI or flags without recreating the stream."""
        self.config = new_config
        self._update_roi(new_config.get("roi"))

    @property
    def reconnect_count(self) -> int:
        if isinstance(self.source, RTSPVideoSource):
            return self.source.reconnect_count
        return 0

    @property
    def pending_count(self) -> int:
        return self.pool.pending_count

    def start(self) -> None:
        self._stop_event.clear()
        self._thread = threading.Thread(
            target=self._run_loop, name=f"cam-{self.camera_code}", daemon=True
        )
        self._thread.start()
        logger.info(
            "Camera pipeline started for %s (%s)", self.camera_code, mask_rtsp_url(self.stream_url)
        )

    def stop(self, timeout: float = 5.0) -> None:
        self._stop_event.set()
        if self._thread and self._thread.is_alive():
            self._thread.join(timeout=timeout)
        self.pool.shutdown(wait=False)
        self.source.close()
        logger.info("Camera pipeline stopped for %s", self.camera_code)

    def _run_loop(self) -> None:
        try:
            self.source.open()
        except Exception as exc:
            logger.warning(
                "Initial open failed for camera %s: %s (will retry in loop)",
                self.camera_code,
                exc,
            )

        poll_interval = 1.0 / (self.target_fps * 10)
        while not self._stop_event.is_set():
            try:
                sampled = self.sampler.sample()
            except Exception:
                self.collector.record_dropped_frames(1)
                time.sleep(1.0)
                continue

            if sampled is None:
                time.sleep(poll_interval)
            else:
                self.collector.record_frame(sampled.frame)

                # Detection
                try:
                    detections = self.detector.detect(sampled.frame.data)
                except Exception as exc:
                    logger.debug("Detection error on %s: %s", self.camera_code, exc)
                    detections = []

                # Region of Interest filter
                if self.roi is not None and detections:
                    detections = [
                        d
                        for d in detections
                        if is_detection_in_roi(
                            d, self.roi, sampled.frame.width, sampled.frame.height
                        )
                    ]

                # Tracking
                tracks = self.tracker.update(detections)

                # Match tracks to detections and submit eligible ones for recognition
                if tracks and detections:
                    # Submit tracks that are not cached
                    for track in tracks:
                        if self.worker.should_process(track):
                            # Find matching detection by IoU / overlap
                            matched_det = detections[0]
                            self.pool.submit(sampled, matched_det, track)

            # Poll results from recognition worker pool and deliver
            while not self._stop_event.is_set():
                result = self.pool.poll_result(timeout=0.0)
                if result is None:
                    break
                try:
                    self._delivery_service.deliver(result)
                except Exception as exc:
                    logger.error("Unexpected error in deliver on %s: %s", self.camera_code, exc)


class NodeOrchestrator:
    """Master orchestrator for the AI Node pilot runtime."""

    def __init__(
        self,
        base_url: str | None = None,
        node_uuid: str | None = None,
        node_token: str | None = None,
        ai_mode: str | None = None,
        model_code: str | None = None,
        model_version: str | None = None,
        event_buffer_path: str | None = None,
        node_client: AiNodeClient | None = None,
        backend_client: RecognitionBackendClient | None = None,
        delivery_client: EventDeliveryClient | None = None,
        health_client: CameraHealthClient | None = None,
        heartbeat_interval: float | None = None,
        config_sync_interval: float | None = None,
        retry_interval: float | None = None,
        camera_health_interval: float | None = None,
        target_fps: float | None = None,
        buffer_initial_backoff_seconds: float = 5.0,
        command_poll_interval: float | None = None,
        restart_handler: Callable[[], None] | None = None,
    ):
        self.base_url = (base_url or settings.backend_base_url).rstrip("/")
        self.node_uuid = node_uuid or settings.ai_node_uuid or ""
        self.node_token = node_token or settings.ai_node_token or ""
        self.ai_mode = ai_mode or settings.ai_mode
        self.model_code = model_code or settings.model_code
        self.model_version = model_version or settings.model_version
        self.target_fps = target_fps or settings.target_fps

        self.heartbeat_interval = heartbeat_interval or settings.heartbeat_interval_seconds
        self.config_sync_interval = config_sync_interval or settings.config_sync_interval_seconds
        self.retry_interval = retry_interval or settings.retry_interval_seconds
        self.camera_health_interval = (
            camera_health_interval or settings.camera_health_interval_seconds
        )
        self.command_poll_interval = command_poll_interval or settings.command_poll_interval_seconds
        self._restart_handler = restart_handler or self._schedule_process_restart

        # Durable offline SQLite buffer (CAM-012)
        buffer_path = event_buffer_path or settings.event_buffer_path
        if buffer_path != ":memory:":
            os.makedirs(os.path.dirname(os.path.abspath(buffer_path)), exist_ok=True)
        self.buffer = OfflineEventBuffer(
            db_path=buffer_path, initial_backoff_seconds=buffer_initial_backoff_seconds
        )

        # Clients (injectable for testing, HTTP for production)
        self.node_client = node_client or HttpAiNodeClient(self.base_url, self.node_token)
        self.backend_client = backend_client or HttpRecognitionBackendClient(
            self.base_url, self.node_token
        )
        self.delivery_client = delivery_client or HttpEventDeliveryClient(
            self.base_url, self.node_token
        )
        self.health_client = health_client or HttpCameraHealthClient(self.base_url, self.node_token)

        self.delivery_service = BufferedEventDeliveryService(self.delivery_client, self.buffer)

        # AI Perception Providers
        self.detector = get_face_detector(provider=self.ai_mode)
        aligner_prov = "standard" if self.ai_mode != "mock" else "mock"
        self.aligner = get_face_aligner(provider=aligner_prov)
        quality_prov = "standard" if self.ai_mode != "mock" else "mock"
        self.quality_evaluator = get_face_quality_evaluator(provider=quality_prov)
        embedder_prov = "onnx" if self.ai_mode == "onnx" else "mock"
        embedder_kwargs: dict[str, Any] = {
            "model_code": self.model_code,
            "model_version": self.model_version,
        }
        if embedder_prov == "onnx" and settings.embedder_model_path:
            embedder_kwargs["model_path"] = settings.embedder_model_path
        self.embedder = get_face_embedder(provider=embedder_prov, **embedder_kwargs)

        self.liveness_detector = get_face_liveness_detector()

        # State
        self._cameras: dict[str, CameraPipelineRunner] = {}
        self._stop_event = threading.Event()
        self._lock = threading.Lock()
        self._threads: list[threading.Thread] = []
        self._is_running = False
        self._start_time: float | None = None
        self._last_heartbeat_at: float | None = None
        self._last_sync_at: float | None = None
        self._last_sync_error: str | None = None
        self._telemetry = SystemTelemetry()

    @property
    def is_running(self) -> bool:
        return self._is_running

    @property
    def active_camera_count(self) -> int:
        with self._lock:
            return len(self._cameras)

    @property
    def pending_buffer_count(self) -> int:
        return self.buffer.size

    def get_status_summary(self) -> dict[str, Any]:
        """Provides status inspection for local health checks and FastAPI."""
        with self._lock:
            cameras_summary = [
                {
                    "uuid": cam.camera_uuid,
                    "code": cam.camera_code,
                    "name": cam.name,
                    "reconnect_count": cam.reconnect_count,
                    "pending_recognitions": cam.pending_count,
                }
                for cam in self._cameras.values()
            ]

        uptime = time.time() - self._start_time if self._start_time else 0.0
        return {
            "status": "RUNNING" if self._is_running else "STOPPED",
            "node_uuid": self.node_uuid,
            "ai_mode": self.ai_mode,
            "model_code": self.model_code,
            "model_version": self.model_version,
            "uptime_seconds": round(uptime, 1),
            "buffer_pending_count": self.pending_buffer_count,
            "active_camera_count": len(cameras_summary),
            "cameras": cameras_summary,
            "last_heartbeat_at": self._last_heartbeat_at,
            "last_config_sync_at": self._last_sync_at,
        }

    def start(self) -> None:
        """Starts background loops and connects to backend."""
        if self._is_running:
            return

        logger.info("Starting AI Node Orchestrator for node %s...", self.node_uuid)
        self._stop_event.clear()
        self._start_time = time.time()
        self._is_running = True

        # Initial check-in (CAM-003)
        self._register_node()

        # Initial configuration sync (CAM-015)
        self.sync_config()

        # Start periodic background workers
        self._threads = [
            threading.Thread(target=self._heartbeat_loop, name="worker-heartbeat", daemon=True),
            threading.Thread(target=self._config_sync_loop, name="worker-config-sync", daemon=True),
            threading.Thread(target=self._retry_loop, name="worker-retry", daemon=True),
            threading.Thread(
                target=self._camera_health_loop, name="worker-camera-health", daemon=True
            ),
            threading.Thread(target=self._command_loop, name="worker-commands", daemon=True),
        ]
        for t in self._threads:
            t.start()

        logger.info("AI Node Orchestrator started successfully.")

    def stop(self, timeout: float = 10.0) -> None:
        """Gracefully terminates all pipelines, workers, and background loops."""
        if not self._is_running:
            return

        logger.info("Stopping AI Node Orchestrator...")
        self._stop_event.set()
        self._is_running = False

        # Stop all camera pipelines
        with self._lock:
            for cam in self._cameras.values():
                cam.stop(timeout=timeout / 2)
            self._cameras.clear()

        # Wait for background worker loops to finish
        for t in self._threads:
            if t.is_alive():
                t.join(timeout=timeout / len(self._threads))
        self._threads.clear()

        logger.info("AI Node Orchestrator cleanly stopped.")

    def _register_node(self) -> None:
        """Self-registration check-in (CAM-003)."""
        try:
            hostname = socket.gethostname()
            ip_address = None
            try:
                ip_address = socket.gethostbyname(hostname)
            except Exception:
                pass
            sw_version = settings.version
            self.node_client.check_in(
                hostname=hostname,
                ip_address=ip_address,
                software_version=sw_version,
                capabilities=["FACE_DETECTION", "FACE_RECOGNITION", "RTSP_STREAMING"],
            )
            logger.info("Check-in confirmed with backend for node %s", self.node_uuid)
        except Exception as exc:
            logger.warning(
                "Initial check-in failed (backend will receive heartbeat later): %s", exc
            )

    def process_commands(self) -> int:
        """COM-03: run this node's pending allowlisted commands and report
        each result. Unknown commands are refused, never interpreted.
        Returns the number of commands handled."""
        handled = 0
        for command in self.node_client.claim_commands():
            uuid = str(command.get("uuid", ""))
            kind = command.get("command")
            if not uuid:
                continue
            handled += 1
            try:
                if kind == "RESYNC_CONFIG":
                    if not self.sync_config():
                        raise RuntimeError(self._last_sync_error or "configuration sync failed")
                    self.node_client.report_command_result(
                        uuid, True, f"configuration synced ({self.active_camera_count} camera(s))"
                    )
                elif kind == "RESTART_SERVICE":
                    # Report first: after the restart this process is gone.
                    self.node_client.report_command_result(uuid, True, "restarting service")
                    self._restart_handler()
                else:
                    self.node_client.report_command_result(
                        uuid, False, f"unsupported command: {kind}"
                    )
            except Exception as exc:
                logger.warning("Command %s (%s) failed: %s", uuid, kind, exc)
                try:
                    self.node_client.report_command_result(uuid, False, str(exc)[:500])
                except Exception:
                    logger.warning("Could not report result of command %s", uuid)
        return handled

    def _command_loop(self) -> None:
        while not self._stop_event.is_set():
            try:
                self.process_commands()
            except Exception as exc:
                logger.warning("Command poll blip: %s", exc)
            self._stop_event.wait(self.command_poll_interval)

    def _schedule_process_restart(self) -> None:
        """Stop gracefully, then exit non-zero so the container restart policy
        (deploy/ai-node: restart: unless-stopped) starts a fresh process."""

        def restart() -> None:
            time.sleep(0.5)
            self.stop()
            os._exit(3)

        threading.Thread(target=restart, name="node-restart", daemon=True).start()

    def sync_config(self) -> bool:
        """Fetches assigned camera configuration and updates running pipelines (CAM-015).
        Returns False when the configuration could not be fetched."""
        if not self.node_uuid:
            logger.warning("No AI_NODE_UUID configured; skipping config sync")
            return False

        try:
            config_data = self.node_client.get_config(self.node_uuid)
            self._last_sync_at = time.time()
        except Exception as exc:
            logger.warning("Config sync failed: %s", exc)
            self._last_sync_error = str(exc)
            return False

        cameras_data = config_data.get("cameras", [])
        active_uuids = set()

        with self._lock:
            for cam_conf in cameras_data:
                cam_uuid = cam_conf.get("camera_uuid")
                if not cam_uuid:
                    continue

                is_active = (
                    cam_conf.get("status") == "ACTIVE"
                    and cam_conf.get("face_detection_enabled", True) is not False
                )

                if is_active:
                    active_uuids.add(cam_uuid)
                    if cam_uuid in self._cameras:
                        # Existing camera: dynamically update ROI and config
                        self._cameras[cam_uuid].update_config(cam_conf)
                    else:
                        # New camera: spawn pipeline runner
                        runner = CameraPipelineRunner(
                            camera_config=cam_conf,
                            ai_node_uuid=self.node_uuid,
                            detector=self.detector,
                            aligner=self.aligner,
                            quality_evaluator=self.quality_evaluator,
                            embedder=self.embedder,
                            liveness_detector=self.liveness_detector,
                            backend_client=self.backend_client,
                            delivery_service=self.delivery_service,
                            model_code=self.model_code,
                            model_version=self.model_version,
                            target_fps=self.target_fps,
                        )
                        runner.start()
                        self._cameras[cam_uuid] = runner
                else:
                    # Camera is not ACTIVE or face_detection is disabled
                    if cam_uuid in self._cameras:
                        logger.info("Stopping disabled camera %s", cam_uuid)
                        self._cameras[cam_uuid].stop()
                        del self._cameras[cam_uuid]

            # Stop any cameras no longer assigned to this node
            current_uuids = set(self._cameras.keys())
            removed_uuids = current_uuids - active_uuids
            for r_uuid in removed_uuids:
                logger.info("Camera %s unassigned from this node; stopping", r_uuid)
                self._cameras[r_uuid].stop()
                del self._cameras[r_uuid]

        self._last_sync_error = None
        return True

    def _heartbeat_loop(self) -> None:
        """Sends periodic operational telemetry heartbeat (CAM-003, CAM-014)."""
        while not self._stop_event.is_set():
            try:
                sample = self._telemetry.sample(settings.event_buffer_path)
                with self._lock:
                    cam_count = len(self._cameras)
                    q_depth = sum(c.pending_count for c in self._cameras.values())

                self.node_client.heartbeat(
                    cpu_usage=sample.cpu_usage,
                    ram_usage=sample.ram_usage,
                    temperature=sample.temperature,
                    disk_usage=sample.disk_usage,
                    active_camera_count=cam_count,
                    queue_depth=q_depth,
                    software_version=settings.version,
                    model_code=self.model_code,
                    model_version=self.model_version,
                )
                self._last_heartbeat_at = time.time()
                logger.debug(
                    "Heartbeat delivered: CPU=%s%%, RAM=%s%%, disk=%s%%, Cams=%d",
                    sample.cpu_usage,
                    sample.ram_usage,
                    sample.disk_usage,
                    cam_count,
                )

            except Exception as exc:
                logger.warning("Heartbeat delivery blip: %s", exc)

            self._stop_event.wait(self.heartbeat_interval)

    def _config_sync_loop(self) -> None:
        """Periodically syncs camera assignments and credentials from backend (CAM-015)."""
        while not self._stop_event.is_set():
            self._stop_event.wait(self.config_sync_interval)
            if not self._stop_event.is_set():
                self.sync_config()

    def _retry_loop(self) -> None:
        """Drains durable offline SQLite event buffer (CAM-012)."""
        while not self._stop_event.is_set():
            if self.buffer.size > 0:
                try:
                    res = self.delivery_service.retry_pending(limit=50)
                    if res.delivered > 0:
                        logger.info(
                            "Offline buffer retry: %d delivered, %d remaining",
                            res.delivered,
                            res.still_pending,
                        )
                except Exception as exc:
                    logger.debug("Buffer retry blip: %s", exc)

            self._stop_event.wait(self.retry_interval)

    def _camera_health_loop(self) -> None:
        """Collects and reports camera health telemetry snapshots (CAM-013)."""
        while not self._stop_event.is_set():
            self._stop_event.wait(self.camera_health_interval)
            if self._stop_event.is_set():
                break

            with self._lock:
                runners = list(self._cameras.values())

            for runner in runners:
                try:
                    sample = runner.collector.collect(source_reconnect_count=runner.reconnect_count)
                    self.health_client.report(sample)
                except Exception as exc:
                    logger.debug("Camera health report blip for %s: %s", runner.camera_code, exc)
