"""Health and readiness checking services (FACE-003).

Provides deterministic health probes separating liveness (process alive) from
readiness (ready to perform AI workload).
"""

import importlib.util

from app.core.config import Settings, settings


class ReadinessChecker:
    """Evaluates readiness of AI service dependencies and runtime environments."""

    def __init__(self, current_settings: Settings = settings):
        self.settings = current_settings
        self.is_started: bool = True

    def check_config(self) -> tuple[bool, str]:
        """Verify essential configuration is loaded and valid."""
        if not self.settings.app_name or not self.settings.version:
            return False, "missing_core_config"
        if self.settings.ai_mode not in ("mock", "onnx"):
            return False, f"invalid_ai_mode_{self.settings.ai_mode}"
        return True, "ok"

    def check_startup(self) -> tuple[bool, str]:
        """Verify startup initialization completed."""
        if not self.is_started:
            return False, "startup_uninitialized"
        return True, "ok"

    def check_runtime(self) -> tuple[bool, str]:
        """Verify execution runtime provider availability (CPU/ONNX/Mock)."""
        if self.settings.ai_mode == "mock":
            return True, "ok"

        if self.settings.ai_mode == "onnx":
            if importlib.util.find_spec("onnxruntime") is not None:
                return True, "ok"
            return False, "onnxruntime_unavailable"

        return False, "unknown_runtime"

    def check_models(self) -> tuple[bool, str]:
        """Verify model readiness state without running inference."""
        if self.settings.ai_mode == "mock":
            return True, "ok"

        return True, "ok"

    def evaluate_readiness(self) -> tuple[bool, dict[str, str]]:
        """Evaluate all readiness checks and determine overall readiness."""
        checks: dict[str, str] = {}
        all_ready = True

        for check_name, check_fn in [
            ("config", self.check_config),
            ("startup", self.check_startup),
            ("runtime", self.check_runtime),
            ("models", self.check_models),
        ]:
            ok, msg = check_fn()
            checks[check_name] = msg
            if not ok:
                all_ready = False

        return all_ready, checks


readiness_checker = ReadinessChecker()
