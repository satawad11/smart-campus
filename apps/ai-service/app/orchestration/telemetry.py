"""
Node resource telemetry for the heartbeat (CAM-014, COM-03).

`psutil` is optional and isn't a dependency of the node image, so CPU and
RAM were never reported in practice. This module uses psutil when it is
installed and otherwise reads the Linux kernel interfaces directly
(/proc/stat, /proc/meminfo, /sys/class/thermal) with the standard library.

Every metric is independently optional: a missing source yields None,
never an exception, so a heartbeat is always sent.
"""

from __future__ import annotations

import os
import shutil
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class TelemetrySample:
    cpu_usage: float | None
    ram_usage: float | None
    disk_usage: float | None
    temperature: float | None


def _clamp_pct(value: float) -> float:
    return round(max(0.0, min(100.0, value)), 1)


class SystemTelemetry:
    """Samples CPU, RAM, disk and temperature.

    CPU usage from /proc/stat is a delta between two samples, so the first
    sample without psutil reports None; later samples cover the time since
    the previous one (the heartbeat interval).
    """

    def __init__(self, proc_root: str = "/proc", sys_root: str = "/sys") -> None:
        self._proc = Path(proc_root)
        self._sys = Path(sys_root)
        self._prev_cpu: tuple[int, int] | None = None
        try:
            import psutil  # noqa: F401

            self._psutil = psutil
        except Exception:
            self._psutil = None

    def sample(self, disk_path: str = ".") -> TelemetrySample:
        return TelemetrySample(
            cpu_usage=self._safe(self._cpu),
            ram_usage=self._safe(self._ram),
            disk_usage=self._safe(lambda: self._disk(disk_path)),
            temperature=self._safe(self._temperature),
        )

    @staticmethod
    def _safe(fn) -> float | None:
        try:
            return fn()
        except Exception:
            return None

    def _cpu(self) -> float | None:
        if self._psutil is not None:
            return _clamp_pct(float(self._psutil.cpu_percent(interval=None)))

        fields = (self._proc / "stat").read_text().splitlines()[0].split()
        if fields[0] != "cpu":
            return None
        values = [int(v) for v in fields[1:]]
        idle = values[3] + (values[4] if len(values) > 4 else 0)  # idle + iowait
        total = sum(values)

        prev, self._prev_cpu = self._prev_cpu, (idle, total)
        if prev is None or total <= prev[1]:
            return None
        busy = 1.0 - (idle - prev[0]) / (total - prev[1])
        return _clamp_pct(busy * 100.0)

    def _ram(self) -> float | None:
        if self._psutil is not None:
            return _clamp_pct(float(self._psutil.virtual_memory().percent))

        info: dict[str, int] = {}
        for line in (self._proc / "meminfo").read_text().splitlines():
            key, _, rest = line.partition(":")
            parts = rest.split()
            if parts:
                info[key] = int(parts[0])
        total = info.get("MemTotal")
        available = info.get("MemAvailable")
        if not total or available is None:
            return None
        return _clamp_pct((1.0 - available / total) * 100.0)

    @staticmethod
    def _disk(path: str) -> float | None:
        target = path if os.path.isdir(path) else (os.path.dirname(os.path.abspath(path)) or ".")
        usage = shutil.disk_usage(target)
        if usage.total <= 0:
            return None
        return _clamp_pct(usage.used / usage.total * 100.0)

    def _temperature(self) -> float | None:
        """Highest thermal-zone reading in °C (kernel reports millidegrees)."""
        readings: list[float] = []
        for zone in sorted((self._sys / "class" / "thermal").glob("thermal_zone*")):
            try:
                raw = int((zone / "temp").read_text().strip())
            except Exception:
                continue
            celsius = raw / 1000.0 if abs(raw) > 1000 else float(raw)
            if -40.0 <= celsius <= 150.0:
                readings.append(celsius)
        return round(max(readings), 1) if readings else None
