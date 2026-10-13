"""COM-03: node resource telemetry without psutil, and the heartbeat payload."""

from pathlib import Path

import pytest

from app.orchestration.node_client import HttpAiNodeClient
from app.orchestration.telemetry import SystemTelemetry


def _write(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text)


@pytest.fixture
def fake_host(tmp_path: Path) -> tuple[Path, Path]:
    proc, sys_root = tmp_path / "proc", tmp_path / "sys"
    _write(proc / "stat", "cpu  100 0 100 800 0 0 0 0 0 0\ncpu0 1 1 1 1\n")
    _write(proc / "meminfo", "MemTotal:  1000 kB\nMemFree: 100 kB\nMemAvailable: 250 kB\n")
    _write(sys_root / "class/thermal/thermal_zone0/temp", "48500\n")
    _write(sys_root / "class/thermal/thermal_zone1/temp", "61250\n")
    return proc, sys_root


def _stdlib_telemetry(proc: Path, sys_root: Path) -> SystemTelemetry:
    telemetry = SystemTelemetry(proc_root=str(proc), sys_root=str(sys_root))
    telemetry._psutil = None  # exercise the stdlib path even where psutil exists
    return telemetry


def test_ram_temperature_and_disk_from_kernel_interfaces(fake_host, tmp_path):
    telemetry = _stdlib_telemetry(*fake_host)

    sample = telemetry.sample(str(tmp_path))

    assert sample.ram_usage == 75.0  # 1 - 250/1000
    assert sample.temperature == 61.2  # hottest zone, millidegrees -> °C
    assert sample.disk_usage is not None and 0.0 <= sample.disk_usage <= 100.0


def test_cpu_is_a_delta_between_samples(fake_host, tmp_path):
    proc, sys_root = fake_host
    telemetry = _stdlib_telemetry(proc, sys_root)

    assert telemetry.sample(str(tmp_path)).cpu_usage is None  # first sample: no delta yet

    # +300 busy jiffies, +100 idle jiffies -> 75% busy
    _write(proc / "stat", "cpu  250 0 250 900 0 0 0 0 0 0\n")
    assert telemetry.sample(str(tmp_path)).cpu_usage == 75.0


def test_missing_sources_yield_none_never_an_exception(tmp_path):
    telemetry = _stdlib_telemetry(tmp_path / "no-proc", tmp_path / "no-sys")

    sample = telemetry.sample(str(tmp_path / "missing" / "buffer.db"))

    assert sample.cpu_usage is None
    assert sample.ram_usage is None
    assert sample.temperature is None


def test_implausible_thermal_readings_are_ignored(tmp_path):
    _write(tmp_path / "sys/class/thermal/thermal_zone0/temp", "-273000\n")
    telemetry = _stdlib_telemetry(tmp_path / "proc", tmp_path / "sys")

    assert telemetry.sample(str(tmp_path)).temperature is None


def test_heartbeat_sends_disk_versions_and_model(monkeypatch):
    sent = {}

    class Response:
        status_code = 200

        @staticmethod
        def json():
            return {"success": True}

    def fake_post(url, json, headers, timeout):
        sent.update(json)
        return Response()

    import httpx

    monkeypatch.setattr(httpx, "post", fake_post)

    HttpAiNodeClient("http://backend:8000", "test-token").heartbeat(
        disk_usage=123.0,
        software_version="0.1.0",
        model_code="mock-face",
        model_version="x" * 100,
        temperature=55.5,
    )

    assert sent["disk_usage"] == 100.0  # clamped
    assert sent["software_version"] == "0.1.0"
    assert sent["model_code"] == "mock-face"
    assert len(sent["model_version"]) == 64  # truncated
    assert sent["temperature"] == 55.5
    assert "cpu_usage" not in sent  # unknown metrics are omitted, not sent as null
