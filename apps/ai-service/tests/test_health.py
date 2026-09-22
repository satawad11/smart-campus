"""Tests for camera health collection (CAM-013).

No real network dependency — httpx.post is monkeypatched, matching
test_backend_client.py's convention.
"""

from datetime import UTC, datetime, timedelta

import numpy as np
import pytest

from app.health.client import CameraHealthClient, HttpCameraHealthClient
from app.health.collector import CameraHealthCollector
from app.health.exceptions import CameraHealthDeliveryError
from app.health.frame_metrics import compute_frame_metrics
from app.streams.schemas import Frame


def make_frame(sequence: int = 0, captured_at: datetime | None = None, data=None) -> Frame:
    return Frame(
        data=data if data is not None else np.full((48, 64, 3), 128, dtype=np.uint8),
        width=64,
        height=48,
        sequence_number=sequence,
        source_name="cam-1",
        captured_at=captured_at or datetime.now(UTC),
    )


class TestComputeFrameMetrics:
    def test_uniform_mid_gray_frame_scores_high_brightness(self) -> None:
        frame_data = np.full((48, 64, 3), 128, dtype=np.uint8)
        metrics = compute_frame_metrics(frame_data)
        assert 0.9 <= metrics.brightness_score <= 1.0

    def test_pure_black_frame_scores_low_brightness(self) -> None:
        frame_data = np.zeros((48, 64, 3), dtype=np.uint8)
        metrics = compute_frame_metrics(frame_data)
        assert metrics.brightness_score < 0.1

    def test_uniform_frame_has_low_blur_score(self) -> None:
        # A perfectly flat frame has zero Laplacian variance -> minimal sharpness signal.
        frame_data = np.full((48, 64, 3), 128, dtype=np.uint8)
        metrics = compute_frame_metrics(frame_data)
        assert metrics.blur_score < 0.1

    def test_high_frequency_noise_frame_has_high_blur_score(self) -> None:
        rng = np.random.default_rng(42)
        frame_data = rng.integers(0, 256, size=(48, 64, 3), dtype=np.uint8)
        metrics = compute_frame_metrics(frame_data)
        assert metrics.blur_score > 0.5

    def test_scores_stay_within_bounds(self) -> None:
        frame_data = np.full((48, 64, 3), 255, dtype=np.uint8)
        metrics = compute_frame_metrics(frame_data)
        assert 0.0 <= metrics.brightness_score <= 1.0
        assert 0.0 <= metrics.blur_score <= 1.0


class TestCameraHealthCollector:
    def test_no_frames_yields_no_fps_and_none_metrics(self) -> None:
        collector = CameraHealthCollector(camera_uuid="camera-1")
        sample = collector.collect()

        assert sample.fps is None
        assert sample.brightness_score is None
        assert sample.blur_score is None
        assert sample.dropped_frames == 0
        assert sample.reconnect_count == 0

    def test_fps_computed_from_frame_arrival_span(self) -> None:
        collector = CameraHealthCollector(camera_uuid="camera-1")
        base = datetime.now(UTC)
        for i in range(6):
            frame = make_frame(sequence=i, captured_at=base + timedelta(seconds=i * 0.5))
            collector.record_frame(frame)

        sample = collector.collect()
        # 6 frames spanning 2.5s -> 5 intervals / 2.5s = 2.0 fps
        assert sample.fps == pytest.approx(2.0, abs=0.01)

    def test_resolution_and_metrics_come_from_latest_frame(self) -> None:
        collector = CameraHealthCollector(camera_uuid="camera-1")
        collector.record_frame(make_frame())
        sample = collector.collect()

        assert sample.resolution == "64x48"
        assert sample.brightness_score is not None
        assert sample.blur_score is not None

    def test_dropped_frames_accumulate_within_the_window(self) -> None:
        collector = CameraHealthCollector(camera_uuid="camera-1")
        collector.record_dropped_frames(3)
        collector.record_dropped_frames(2)
        sample = collector.collect()
        assert sample.dropped_frames == 5

    def test_collect_resets_the_window(self) -> None:
        collector = CameraHealthCollector(camera_uuid="camera-1")
        collector.record_frame(make_frame())
        collector.record_dropped_frames(4)
        collector.collect()

        empty_sample = collector.collect()
        assert empty_sample.dropped_frames == 0
        assert empty_sample.fps is None

    def test_reconnect_count_reports_only_the_delta_since_last_collect(self) -> None:
        collector = CameraHealthCollector(camera_uuid="camera-1")

        first = collector.collect(source_reconnect_count=3)
        assert first.reconnect_count == 3  # first-ever observation: all 3 are new

        second = collector.collect(source_reconnect_count=3)
        assert second.reconnect_count == 0  # no new reconnects since last collect

        third = collector.collect(source_reconnect_count=5)
        assert third.reconnect_count == 2  # 2 new reconnects this window

    def test_latency_is_recorded_when_provided(self) -> None:
        collector = CameraHealthCollector(camera_uuid="camera-1")
        collector.record_frame(make_frame(), latency_ms=42.7)
        sample = collector.collect()
        assert sample.latency_ms == 42


class FakeResponse:
    def __init__(self, status_code: int):
        self.status_code = status_code


class FakeHealthClient(CameraHealthClient):
    def __init__(self, error: Exception | None = None):
        self.error = error
        self.reported = []

    def report(self, sample) -> None:
        if self.error is not None:
            raise self.error
        self.reported.append(sample)


class TestHttpCameraHealthClient:
    def test_successful_report_sends_no_status_field(self, monkeypatch) -> None:
        captured = {}

        def fake_post(url, json, headers, timeout):
            assert url.endswith("/internal/v1/cameras/camera-1/health")
            assert headers["Authorization"] == "Bearer test-token"
            captured.update(json)
            return FakeResponse(200)

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        collector = CameraHealthCollector(camera_uuid="camera-1")
        collector.record_frame(make_frame())
        sample = collector.collect()

        client = HttpCameraHealthClient(base_url="http://backend", node_token="test-token")
        client.report(sample)

        assert "status" not in captured
        assert "recorded_at" in captured

    def test_non_2xx_status_raises_delivery_error(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            return FakeResponse(422)

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpCameraHealthClient(base_url="http://backend", node_token="test-token")
        collector = CameraHealthCollector(camera_uuid="camera-1")
        with pytest.raises(CameraHealthDeliveryError):
            client.report(collector.collect())

    def test_error_message_never_contains_bearer_token(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            raise ConnectionError("boom")

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpCameraHealthClient(base_url="http://backend", node_token="super-secret-token")
        collector = CameraHealthCollector(camera_uuid="camera-1")
        with pytest.raises(CameraHealthDeliveryError) as exc_info:
            client.report(collector.collect())
        assert "super-secret-token" not in str(exc_info.value)
