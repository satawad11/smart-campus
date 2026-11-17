"""Readiness checks for the configured AI execution provider (FACE-003)."""

from app.core.config import Settings
from app.core.health import ReadinessChecker


def test_onnx_mode_uses_opencv_runtime_not_onnxruntime(monkeypatch) -> None:
    monkeypatch.setattr(
        "app.core.health.importlib.util.find_spec",
        lambda name: object() if name == "cv2" else None,
    )
    checker = ReadinessChecker(Settings(ai_mode="onnx", _env_file=None))

    assert checker.check_runtime() == (True, "ok")


def test_onnx_mode_fails_when_opencv_runtime_is_unavailable(monkeypatch) -> None:
    monkeypatch.setattr("app.core.health.importlib.util.find_spec", lambda _name: None)
    checker = ReadinessChecker(Settings(ai_mode="onnx", _env_file=None))

    assert checker.check_runtime() == (False, "opencv_unavailable")


def test_mock_mode_does_not_require_opencv(monkeypatch) -> None:
    monkeypatch.setattr("app.core.health.importlib.util.find_spec", lambda _name: None)
    checker = ReadinessChecker(Settings(ai_mode="mock", _env_file=None))

    assert checker.check_runtime() == (True, "ok")
