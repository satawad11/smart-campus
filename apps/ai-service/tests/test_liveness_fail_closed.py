"""PAD must fail closed outside mock mode (pilot pre-deployment review)."""

import io

import numpy as np
import pytest
from fastapi.testclient import TestClient

from app.core.config import settings
from app.liveness import factory
from app.liveness.mock import MockFaceLivenessDetector
from app.liveness.unavailable import (
    LivenessModelUnavailableError,
    UnavailableFaceLivenessDetector,
)
from app.main import app

client = TestClient(app)


def _png_bytes() -> bytes:
    import cv2

    ok, buf = cv2.imencode(".png", np.full((64, 64, 3), 128, dtype=np.uint8))
    assert ok
    return buf.tobytes()


@pytest.fixture
def onnx_mode(monkeypatch, tmp_path):
    monkeypatch.setattr(settings, "ai_mode", "onnx")
    # No PAD model: an empty model directory (the real models may exist in models/).
    monkeypatch.setattr(settings, "liveness_model_dir", str(tmp_path))
    monkeypatch.setattr(factory, "_detector_instance", None)
    yield
    monkeypatch.setattr(factory, "_detector_instance", None)


def test_non_mock_mode_never_uses_the_always_real_mock(onnx_mode):
    detector = factory.get_face_liveness_detector()

    assert isinstance(detector, UnavailableFaceLivenessDetector)
    assert not isinstance(detector, MockFaceLivenessDetector)
    with pytest.raises(LivenessModelUnavailableError):
        detector.evaluate(np.full((8, 8, 3), 128, dtype=np.uint8))


def test_liveness_route_returns_503_instead_of_real_without_pad_model(onnx_mode):
    response = client.post(
        "/internal/v1/face/liveness",
        files={"file": ("face.png", io.BytesIO(_png_bytes()), "image/png")},
    )

    assert response.status_code == 503
    detail = response.json()["detail"]
    assert detail["error_code"] == "PAD_MODEL_UNAVAILABLE"
    assert "REAL" not in response.text
