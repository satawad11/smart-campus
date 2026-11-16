"""The installation report's RTSP check opens real streams (a reachable host alone
is not "ready"), and never prints camera credentials."""

import socket

import pytest

from app.health_check import _probe_rtsp_stream, check_camera_reachability_and_rtsp


class _FakeSocket:
    def close(self) -> None:
        pass


@pytest.fixture
def reachable_hosts(monkeypatch):
    monkeypatch.setattr(socket, "create_connection", lambda *a, **k: _FakeSocket())


def _cam(code="CAM-0001", url="rtsp://10.10.30.101:554/", **extra):
    return {"camera_code": code, "rtsp_url": url, **extra}


def test_real_stream_that_delivers_a_frame_is_ready(reachable_hosts):
    reach, rtsp = check_camera_reachability_and_rtsp([_cam()], rtsp_probe=lambda u, n: None)

    assert reach.status == "PASS"
    assert rtsp.status == "PASS"
    assert rtsp.message == "1/1 RTSP sources ready"


def test_probe_gets_the_authenticated_url(reachable_hosts):
    seen = []
    check_camera_reachability_and_rtsp(
        [_cam(username="admin", password="p@ss")],
        rtsp_probe=lambda u, n: seen.append((u, n)),
    )

    assert seen == [("rtsp://admin:p%40ss@10.10.30.101:554/", "CAM-0001")]


def test_stream_that_fails_is_not_ready(reachable_hosts):
    _, rtsp = check_camera_reachability_and_rtsp(
        [_cam()], rtsp_probe=lambda u, n: "CAM-0001: did not open"
    )

    assert rtsp.status == "FAIL"
    assert rtsp.message.startswith("0/1 RTSP sources ready")
    assert "did not open" in rtsp.message


def test_mixed_cameras_warn(reachable_hosts):
    cams = [_cam(), _cam(code="CAM-0002", url="mock://rehearsal")]
    _, rtsp = check_camera_reachability_and_rtsp(cams, rtsp_probe=lambda u, n: "broken")

    assert rtsp.status == "WARNING"
    assert rtsp.message.startswith("1/2 RTSP sources ready")


def test_unreachable_host_is_not_probed(monkeypatch):
    def refuse(*a, **k):
        raise OSError("refused")

    monkeypatch.setattr(socket, "create_connection", refuse)
    probed = []
    reach, rtsp = check_camera_reachability_and_rtsp(
        [_cam()], rtsp_probe=lambda u, n: probed.append(u)
    )

    assert probed == []
    assert reach.status == "FAIL"
    assert rtsp.status == "FAIL"
    assert "unreachable" in rtsp.message


def test_mock_cameras_stay_ready_without_probing():
    _, rtsp = check_camera_reachability_and_rtsp(
        [{"rtsp_url": "mock://a"}], rtsp_probe=lambda u, n: pytest.fail("probed a mock")
    )

    assert rtsp.status == "PASS"


def test_probe_failure_message_masks_credentials(monkeypatch):
    class Closed:
        def isOpened(self):
            return False

        def release(self):
            pass

    monkeypatch.setattr("app.streams.rtsp._default_capture_factory", lambda *a: Closed())

    reason = _probe_rtsp_stream("rtsp://admin:secret@10.10.30.101:554/", "CAM-0001")

    assert reason is not None
    assert "secret" not in reason and "admin" not in reason
    assert "***:***@10.10.30.101:554" in reason


def test_probe_returns_none_when_a_frame_arrives(monkeypatch):
    import numpy as np

    class Open:
        def isOpened(self):
            return True

        def read(self):
            return True, np.zeros((4, 4, 3), dtype=np.uint8)

        def release(self):
            pass

    monkeypatch.setattr("app.streams.rtsp._default_capture_factory", lambda *a: Open())

    assert _probe_rtsp_stream("rtsp://10.10.30.101:554/", "CAM-0001") is None
