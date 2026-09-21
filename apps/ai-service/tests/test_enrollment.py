"""Unit and integration tests for internal face enrollment perception route (FACE-009)."""

import pytest
from fastapi.testclient import TestClient

from app.detection.mock import MockFaceDetector
from app.detection.schemas import BoundingBox, FaceDetection, LandmarkPoint
from app.main import app
from app.quality.mock import MockFaceQualityEvaluator


@pytest.fixture
def client() -> TestClient:
    return TestClient(app)


def create_dummy_jpeg_bytes() -> bytes:
    """Create a simple deterministic JPEG or binary byte stream for testing."""
    return b"\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00" + b"\x00" * 200


def test_enrollment_pipeline_success(client: TestClient):
    """Test successful enrollment pipeline with exactly one detected face and good quality."""
    image_bytes = create_dummy_jpeg_bytes()

    response = client.post(
        "/internal/v1/face/enroll",
        files={"file": ("sample.jpg", image_bytes, "image/jpeg")},
        data={"pose": "FRONT", "source": "UPLOAD"},
    )

    assert response.status_code == 200
    data = response.json()

    assert data["success"] is True
    assert data["face_count"] == 1
    assert data["model_type"] == "FACE_EMBEDDING"
    assert data["embedding_dimension"] == 512
    assert len(data["embedding"]) == 512
    assert data["quality"]["accepted"] is True
    assert data["quality"]["rejection_reasons"] == []
    assert data["bounding_box"] is not None
    assert data["bounding_box"]["confidence"] >= 0.0


def test_enrollment_pipeline_rejects_zero_faces(
    client: TestClient, monkeypatch: pytest.MonkeyPatch
):
    """Test enrollment failure when zero faces are detected."""
    monkeypatch.setattr(
        "app.api.routes.enrollment.get_face_detector",
        lambda: MockFaceDetector(custom_detections=[]),
    )

    image_bytes = create_dummy_jpeg_bytes()
    response = client.post(
        "/internal/v1/face/enroll",
        files={"file": ("sample.jpg", image_bytes, "image/jpeg")},
    )

    assert response.status_code == 422
    data = response.json()
    detail = data.get("detail", data)
    assert detail["success"] is False
    assert detail["error_code"] == "NO_FACE"
    assert "NO_FACE" in detail["rejection_reasons"]


def test_enrollment_pipeline_rejects_multiple_faces(
    client: TestClient, monkeypatch: pytest.MonkeyPatch
):
    """Test enrollment failure when multiple faces are detected."""
    det1 = FaceDetection(
        bbox=BoundingBox(x1=10, y1=10, x2=80, y2=80),
        confidence=0.98,
        landmarks=[
            LandmarkPoint(x=25, y=30),
            LandmarkPoint(x=65, y=30),
            LandmarkPoint(x=45, y=50),
            LandmarkPoint(x=30, y=70),
            LandmarkPoint(x=60, y=70),
        ],
        detector_name="mock-detector",
        detector_version="1.0.0",
    )
    det2 = FaceDetection(
        bbox=BoundingBox(x1=100, y1=10, x2=170, y2=80),
        confidence=0.95,
        landmarks=[
            LandmarkPoint(x=115, y=30),
            LandmarkPoint(x=155, y=30),
            LandmarkPoint(x=135, y=50),
            LandmarkPoint(x=120, y=70),
            LandmarkPoint(x=150, y=70),
        ],
        detector_name="mock-detector",
        detector_version="1.0.0",
    )

    monkeypatch.setattr(
        "app.api.routes.enrollment.get_face_detector",
        lambda: MockFaceDetector(custom_detections=[det1, det2]),
    )

    image_bytes = create_dummy_jpeg_bytes()
    response = client.post(
        "/internal/v1/face/enroll",
        files={"file": ("sample.jpg", image_bytes, "image/jpeg")},
    )

    assert response.status_code == 422
    data = response.json()
    detail = data.get("detail", data)
    assert detail["success"] is False
    assert detail["error_code"] == "MULTIPLE_FACES"
    assert detail["face_count"] == 2


def test_enrollment_pipeline_rejects_poor_quality(
    client: TestClient, monkeypatch: pytest.MonkeyPatch
):
    """Test enrollment failure when technical quality criteria are not met."""
    monkeypatch.setattr(
        "app.api.routes.enrollment.get_face_quality_evaluator",
        lambda: MockFaceQualityEvaluator(force_accepted=False),
    )

    image_bytes = create_dummy_jpeg_bytes()
    response = client.post(
        "/internal/v1/face/enroll",
        files={"file": ("sample.jpg", image_bytes, "image/jpeg")},
    )

    assert response.status_code == 422
    data = response.json()
    detail = data.get("detail", data)
    assert detail["success"] is False
    assert detail["error_code"] == "FACE_QUALITY_REJECTED"
    assert "IMAGE_BLUR" in detail["rejection_reasons"]
    assert "BAD_POSE" in detail["rejection_reasons"]
    assert detail["quality"]["accepted"] is False
