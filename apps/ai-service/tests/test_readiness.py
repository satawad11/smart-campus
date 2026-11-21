"""Readiness checks for the configured AI execution provider (FACE-003)."""

from pathlib import Path

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


def test_onnx_mode_fails_readiness_when_detector_model_is_missing(tmp_path: Path) -> None:
    checker = ReadinessChecker(
        Settings(
            ai_mode="onnx",
            detector_model_path=str(tmp_path / "missing-detector.onnx"),
            embedder_model_path=str(tmp_path / "missing-embedder.onnx"),
            liveness_model_dir=str(tmp_path),
            _env_file=None,
        )
    )

    assert checker.check_models() == (False, "detector_model_unavailable")


def test_onnx_mode_requires_detector_embedder_and_liveness(monkeypatch, tmp_path: Path) -> None:
    ready = type("ReadyProvider", (), {"is_ready": True})
    not_ready = type("UnavailableProvider", (), {"is_ready": False})
    settings = Settings(
        ai_mode="onnx",
        detector_model_path=str(tmp_path / "detector.onnx"),
        embedder_model_path=str(tmp_path / "embedder.onnx"),
        liveness_model_dir=str(tmp_path),
        _env_file=None,
    )
    checker = ReadinessChecker(settings)

    monkeypatch.setattr("app.core.health.YuNetFaceDetector", lambda _path: ready())
    monkeypatch.setattr("app.core.health.SFaceEmbedder", lambda _path, **_kwargs: not_ready())
    monkeypatch.setattr(
        "app.core.health.MiniFASNetLivenessDetector", lambda *_args, **_kwargs: ready()
    )

    assert checker.check_models() == (False, "embedding_model_unavailable")

    monkeypatch.setattr("app.core.health.SFaceEmbedder", lambda _path, **_kwargs: ready())
    monkeypatch.setattr(
        "app.core.health.MiniFASNetLivenessDetector", lambda *_args, **_kwargs: not_ready()
    )

    assert checker.check_models() == (False, "liveness_model_unavailable")

    monkeypatch.setattr(
        "app.core.health.MiniFASNetLivenessDetector", lambda *_args, **_kwargs: ready()
    )

    assert checker.check_models() == (True, "ok")
