"""Tests for the internal face quality check route (FACE-022)."""

import pytest
from fastapi.testclient import TestClient

from app.detection.mock import MockFaceDetector
from app.detection.schemas import BoundingBox, FaceDetection, LandmarkPoint
from app.main import app
from app.quality.mock import MockFaceQualityEvaluator

URL = "/internal/v1/face/check-quality"


@pytest.fixture
def client() -> TestClient:
    return TestClient(app)


def _jpeg() -> bytes:
    return b"\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00" + b"\x00" * 200


def _det(x: float) -> FaceDetection:
    return FaceDetection(
        bbox=BoundingBox(x1=x, y1=10, x2=x + 70, y2=80),
        confidence=0.97,
        landmarks=[
            LandmarkPoint(x=x + 15, y=30),
            LandmarkPoint(x=x + 55, y=30),
            LandmarkPoint(x=x + 35, y=50),
            LandmarkPoint(x=x + 20, y=70),
            LandmarkPoint(x=x + 50, y=70),
        ],
        detector_name="mock-detector",
        detector_version="1.0.0",
    )


def _post(client: TestClient):
    return client.post(URL, files={"file": ("frame.jpg", _jpeg(), "image/jpeg")})


def test_single_good_face_is_accepted_without_embedding(client: TestClient):
    response = _post(client)

    assert response.status_code == 200
    data = response.json()
    assert data["success"] is True
    assert data["face_count"] == 1
    assert len(data["faces"]) == 1
    assert data["accepted"] is True
    assert data["rejection_reasons"] == []
    assert data["quality"]["accepted"] is True
    assert data["image_width"] >= 1 and data["image_height"] >= 1
    # Biometric boundary: a quality check never returns a vector.
    assert "embedding" not in data


def test_no_face_returns_200_with_no_face_reason(
    client: TestClient, monkeypatch: pytest.MonkeyPatch
):
    monkeypatch.setattr(
        "app.api.routes.quality.get_face_detector",
        lambda: MockFaceDetector(custom_detections=[]),
    )

    data = _post(client).json()

    assert data["face_count"] == 0
    assert data["faces"] == []
    assert data["accepted"] is False
    assert data["rejection_reasons"] == ["NO_FACE"]
    assert data["quality"] is None


def test_multiple_faces_return_every_box(client: TestClient, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setattr(
        "app.api.routes.quality.get_face_detector",
        lambda: MockFaceDetector(custom_detections=[_det(10), _det(100)]),
    )

    data = _post(client).json()

    assert data["face_count"] == 2
    assert [f["x1"] for f in data["faces"]] == [10, 100]
    assert data["accepted"] is False
    assert data["rejection_reasons"] == ["MULTIPLE_FACES"]
    assert data["quality"] is None


def test_poor_quality_reports_reasons(client: TestClient, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setattr(
        "app.api.routes.quality.get_face_quality_evaluator",
        lambda: MockFaceQualityEvaluator(force_accepted=False),
    )

    data = _post(client).json()

    assert data["face_count"] == 1
    assert data["accepted"] is False
    assert "IMAGE_BLUR" in data["rejection_reasons"]
    assert data["quality"]["accepted"] is False


def test_empty_upload_is_invalid_image(client: TestClient):
    response = client.post(URL, files={"file": ("frame.jpg", b"", "image/jpeg")})

    assert response.status_code == 422
    detail = response.json()["detail"]
    assert detail["error_code"] == "INVALID_IMAGE"


def test_detector_failure_is_500(client: TestClient, monkeypatch: pytest.MonkeyPatch):
    class Broken:
        def detect(self, image):
            raise RuntimeError("boom")

    monkeypatch.setattr("app.api.routes.quality.get_face_detector", lambda: Broken())

    response = _post(client)

    assert response.status_code == 500
    assert response.json()["detail"]["error_code"] == "DETECTION_ERROR"
