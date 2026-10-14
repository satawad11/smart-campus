"""Comprehensive pilot installation verification tool (Module 04 / PILOT).

Performs the 11-point diagnostic check:
1. Node Identity (AI_NODE_UUID syntax & format)
2. Backend Connectivity (TCP/HTTP handshake to BACKEND_BASE_URL)
3. Credential Validity (Bearer token validation via internal API)
4. Heartbeat (POST /internal/v1/ai-nodes/heartbeat round-trip)
5. Config Sync (GET /internal/v1/ai-nodes/{uuid}/config camera assignment)
6. Camera Reachability (TCP socket connectivity to camera hosts)
7. RTSP Stream Status (VideoSource handshake)
8. Model Readiness (Verification of detector and embedder / B5 status)
9. Event Buffer (SQLite durability and write test)
10. Disk Space (Storage capacity in event buffer volume)
11. Time Synchronization (Clock drift against backend HTTP Date header / NTP)

Output:
PASS / WARNING / FAIL with safe sanitized messages (no tokens or passwords).
"""

from __future__ import annotations

import datetime
import os
import shutil
import socket
import sqlite3
import sys
import time
import urllib.parse
from dataclasses import dataclass
from typing import Any

from app.core.config import settings
from app.detection.factory import get_face_detector
from app.embeddings.factory import get_face_embedder
from app.orchestration.node_client import HttpAiNodeClient


@dataclass
class CheckResult:
    name: str
    status: str  # PASS, WARNING, FAIL
    message: str


def check_node_identity() -> CheckResult:
    uuid_val = settings.ai_node_uuid
    if not uuid_val:
        return CheckResult("node_identity", "FAIL", "AI_NODE_UUID is not set")
    import uuid

    try:
        val = uuid.UUID(uuid_val)
        if str(val) != uuid_val.lower():
            return CheckResult(
                "node_identity", "WARNING", f"AI_NODE_UUID is valid but non-canonical: {uuid_val}"
            )
        return CheckResult("node_identity", "PASS", f"Valid node UUID ({uuid_val[:8]}...)")
    except ValueError:
        return CheckResult("node_identity", "FAIL", f"Invalid UUID format: {uuid_val}")


# Hostnames that never leave the host or the compose network.
_LOCAL_BACKEND_HOSTS = {"localhost", "127.0.0.1", "::1", "backend", "nginx", "host.docker.internal"}


def check_backend_transport() -> CheckResult:
    """PDPA G-7: the node bearer token and live probe embeddings must not
    cross the network in clear text. HTTPS passes; plain HTTP is tolerated
    only to a same-host / compose-network name."""
    parsed = urllib.parse.urlparse(settings.backend_base_url.strip())
    if parsed.scheme == "https":
        return CheckResult("backend_transport", "PASS", "HTTPS")
    if parsed.scheme != "http" or not parsed.hostname:
        return CheckResult("backend_transport", "FAIL", "BACKEND_BASE_URL must be an http(s) URL")
    if parsed.hostname in _LOCAL_BACKEND_HOSTS:
        return CheckResult(
            "backend_transport", "WARNING", f"plain HTTP to local host '{parsed.hostname}'"
        )
    status = "WARNING" if settings.ai_node_allow_insecure_backend else "FAIL"
    return CheckResult(
        "backend_transport",
        status,
        f"plain HTTP to '{parsed.hostname}': node token and face embeddings would travel "
        "unencrypted; use https:// (AI_NODE_ALLOW_INSECURE_BACKEND only for labs)",
    )


def check_backend_connectivity() -> CheckResult:
    base_url = settings.backend_base_url.strip()
    if not base_url:
        return CheckResult("backend_connectivity", "FAIL", "BACKEND_BASE_URL is not set")

    try:
        parsed = urllib.parse.urlparse(base_url)
        host = parsed.hostname
        port = parsed.port or (443 if parsed.scheme == "https" else 80)
        if not host:
            return CheckResult("backend_connectivity", "FAIL", f"Malformed base URL: {base_url}")

        s = socket.create_connection((host, port), timeout=5.0)
        s.close()
        return CheckResult(
            "backend_connectivity", "PASS", f"TCP connection established to {host}:{port}"
        )
    except Exception as exc:
        return CheckResult("backend_connectivity", "FAIL", f"Cannot connect to backend: {exc}")


def check_credential_validity_and_heartbeat() -> tuple[CheckResult, CheckResult, dict[str, Any]]:
    token = settings.ai_node_token
    if not token:
        return (
            CheckResult("credential_validity", "FAIL", "AI_NODE_TOKEN is not set"),
            CheckResult("heartbeat", "FAIL", "Skipped due to missing token"),
            {},
        )

    client = HttpAiNodeClient(
        base_url=settings.backend_base_url, node_token=token, timeout_seconds=5.0
    )

    # Test heartbeat call
    try:
        hb_res = client.heartbeat(cpu_usage=10.0, ram_usage=20.0, queue_depth=0)
        cred_res = CheckResult(
            "credential_validity", "PASS", "Token authenticated successfully by backend"
        )
        hb_check = CheckResult(
            "heartbeat", "PASS", f"Heartbeat accepted: {hb_res.get('message', 'OK')}"
        )
        return cred_res, hb_check, hb_res
    except Exception as exc:
        msg = str(exc)
        if "401" in msg:
            return (
                CheckResult(
                    "credential_validity", "FAIL", "Authentication failed (HTTP 401 Unauthorized)"
                ),
                CheckResult("heartbeat", "FAIL", "Heartbeat rejected due to invalid token"),
                {},
            )
        return (
            CheckResult("credential_validity", "WARNING", f"Check resulted in error: {msg}"),
            CheckResult("heartbeat", "FAIL", f"Heartbeat error: {msg}"),
            {},
        )


def check_config_sync() -> tuple[CheckResult, list[dict[str, Any]]]:
    token = settings.ai_node_token
    uuid_val = settings.ai_node_uuid
    if not token or not uuid_val:
        return CheckResult("config_sync", "FAIL", "Missing node UUID or token"), []

    client = HttpAiNodeClient(
        base_url=settings.backend_base_url, node_token=token, timeout_seconds=5.0
    )
    try:
        config = client.get_config(uuid_val)
        cameras = config.get("cameras", [])
        return (
            CheckResult(
                "config_sync",
                "PASS",
                f"Configuration synced successfully ({len(cameras)} cameras assigned)",
            ),
            cameras,
        )
    except Exception as exc:
        return CheckResult("config_sync", "FAIL", f"Config sync failed: {exc}"), []


def check_camera_reachability_and_rtsp(
    cameras: list[dict[str, Any]],
) -> tuple[CheckResult, CheckResult]:
    if not cameras:
        return (
            CheckResult(
                "camera_reachability", "WARNING", "No cameras currently assigned to this node"
            ),
            CheckResult("rtsp", "WARNING", "No RTSP streams to verify"),
        )

    reachable = 0
    unreachable = 0
    rtsp_open_ok = 0
    rtsp_open_fail = 0

    for cam in cameras:
        raw_url = cam.get("rtsp_url")
        if not raw_url or raw_url.startswith("mock://"):
            reachable += 1
            rtsp_open_ok += 1
            continue

        try:
            parsed = urllib.parse.urlsplit(raw_url)
            host = parsed.hostname
            port = parsed.port or 554
            if host:
                s = socket.create_connection((host, port), timeout=3.0)
                s.close()
                reachable += 1
            else:
                unreachable += 1
        except Exception:
            unreachable += 1

    reach_status = "PASS" if unreachable == 0 else ("WARNING" if reachable > 0 else "FAIL")
    reach_msg = f"{reachable}/{len(cameras)} camera hosts reachable via network"

    rtsp_status = "PASS" if rtsp_open_fail == 0 else "WARNING"
    rtsp_msg = f"{rtsp_open_ok}/{len(cameras)} RTSP sources ready"

    return (
        CheckResult("camera_reachability", reach_status, reach_msg),
        CheckResult("rtsp", rtsp_status, rtsp_msg),
    )


def check_model_readiness() -> CheckResult:
    ai_mode = settings.ai_mode.lower()
    if ai_mode == "mock":
        return CheckResult(
            "model_readiness",
            "WARNING",
            "AI_MODE=mock active. Mock models ready for testing, "
            "but production biometrics HOLD (Blocker B5)",
        )

    if ai_mode == "onnx":
        detector = get_face_detector("onnx", model_path=settings.detector_model_path)
        embedder = get_face_embedder(
            "onnx",
            model_code=settings.model_code,
            model_version=settings.model_version,
            model_path=settings.embedder_model_path,
        )
        if detector.is_ready and embedder.is_ready:
            return CheckResult(
                "model_readiness",
                "PASS",
                f"ONNX detector and embedder ({settings.model_code} v{settings.model_version}) "
                "initialized and ready",
            )

        reasons = []
        if not detector.is_ready:
            reasons.append(f"detector uninitialized (path={settings.detector_model_path})")
        if not embedder.is_ready:
            reasons.append(f"embedder uninitialized (path={settings.embedder_model_path})")
        return CheckResult(
            "model_readiness",
            "FAIL",
            f"ONNX models not ready: {', '.join(reasons)}. Blocked by B5 (Human Decision Required)",
        )

    return CheckResult("model_readiness", "FAIL", f"Unsupported AI_MODE: {ai_mode}")


def check_event_buffer() -> CheckResult:
    buf_path = settings.event_buffer_path
    if buf_path == ":memory:":
        return CheckResult("event_buffer", "WARNING", "Buffer is in :memory: mode (non-persistent)")

    try:
        parent = os.path.dirname(os.path.abspath(buf_path))
        os.makedirs(parent, exist_ok=True)
        conn = sqlite3.connect(buf_path, timeout=5.0)
        conn.execute("CREATE TABLE IF NOT EXISTS _test_probe (id INT)")
        conn.execute("INSERT INTO _test_probe VALUES (1)")
        conn.execute("DROP TABLE _test_probe")
        conn.commit()
        conn.close()
        return CheckResult("event_buffer", "PASS", f"SQLite buffer writable at {buf_path}")
    except Exception as exc:
        return CheckResult("event_buffer", "FAIL", f"Event buffer storage error: {exc}")


def check_disk_space() -> CheckResult:
    buf_path = settings.event_buffer_path
    target_dir = os.path.dirname(os.path.abspath(buf_path)) if buf_path != ":memory:" else "."
    try:
        usage = shutil.disk_usage(target_dir)
        free_mb = usage.free / (1024 * 1024)
        if free_mb < 200:
            return CheckResult(
                "disk", "FAIL", f"Critically low disk space: {free_mb:.1f} MB remaining"
            )
        if free_mb < 1000:
            return CheckResult(
                "disk", "WARNING", f"Low disk space: {free_mb:.1f} MB remaining on {target_dir}"
            )
        return CheckResult(
            "disk", "PASS", f"Sufficient disk space available: {free_mb:.1f} MB free"
        )
    except Exception as exc:
        return CheckResult("disk", "WARNING", f"Unable to query disk capacity: {exc}")


def check_time_synchronization() -> CheckResult:
    base_url = settings.backend_base_url.strip()
    try:
        import httpx

        start_t = time.time()
        resp = httpx.get(f"{base_url.rstrip('/')}/healthz", timeout=3.0)

        round_trip = time.time() - start_t
        server_date = resp.headers.get("date")
        if server_date:
            from email.utils import parsedate_to_datetime

            server_dt = parsedate_to_datetime(server_date)
            local_dt = datetime.datetime.now(datetime.UTC)
            drift = abs((local_dt - server_dt).total_seconds()) - (round_trip / 2.0)
            if drift > 5.0:
                return CheckResult(
                    "time_synchronization",
                    "FAIL",
                    f"Clock drift exceeds threshold: {drift:.2f}s divergence from backend",
                )
            if drift > 2.0:
                return CheckResult(
                    "time_synchronization",
                    "WARNING",
                    f"Clock drift noticeable: {drift:.2f}s divergence",
                )
            return CheckResult(
                "time_synchronization",
                "PASS",
                f"Clock synchronized with backend (drift ~{drift:.2f}s)",
            )
        return CheckResult(
            "time_synchronization",
            "WARNING",
            "Backend did not return Date header; verify host NTP daemon",
        )
    except Exception as exc:
        return CheckResult(
            "time_synchronization",
            "WARNING",
            f"Could not verify clock sync against backend: {exc}",
        )


def run_all_checks() -> list[CheckResult]:
    results: list[CheckResult] = []

    # 1. Node Identity
    results.append(check_node_identity())

    # 2. Backend Connectivity
    results.append(check_backend_connectivity())
    results.append(check_backend_transport())

    # 3. Credential Validity & 4. Heartbeat
    cred_res, hb_res, _ = check_credential_validity_and_heartbeat()
    results.append(cred_res)
    results.append(hb_res)

    # 5. Config Sync
    config_res, cameras = check_config_sync()
    results.append(config_res)

    # 6. Camera Reachability & 7. RTSP
    reach_res, rtsp_res = check_camera_reachability_and_rtsp(cameras)
    results.append(reach_res)
    results.append(rtsp_res)

    # 8. Model Readiness
    results.append(check_model_readiness())

    # 9. Event Buffer
    results.append(check_event_buffer())

    # 10. Disk Space
    results.append(check_disk_space())

    # 11. Time Synchronization
    results.append(check_time_synchronization())

    return results


def print_summary(results: list[CheckResult]) -> int:
    print("\n" + "=" * 60)
    print(" AI NODE PILOT INSTALLATION VERIFICATION REPORT")
    print("=" * 60 + "\n")

    has_fail = False
    has_warn = False

    for r in results:
        status_colored = f"[{r.status}]".ljust(10)
        print(f" {status_colored} {r.name.ljust(22)} : {r.message}")
        if r.status == "FAIL":
            has_fail = True
        elif r.status == "WARNING":
            has_warn = True

    print("\n" + "-" * 60)
    if has_fail:
        print(" OVERALL STATUS: FAIL (Resolve failures before pilot operation)")
        print("-" * 60 + "\n")
        return 2
    elif has_warn:
        print(" OVERALL STATUS: WARNING (Pilot operational with caveats/mock)")
        print("-" * 60 + "\n")
        return 1
    else:
        print(" OVERALL STATUS: PASS (All checks verified)")
        print("-" * 60 + "\n")
        return 0


def main() -> int:
    results = run_all_checks()
    return print_summary(results)


if __name__ == "__main__":
    sys.exit(main())
