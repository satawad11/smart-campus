"""Tests for Face Liveness / PAD API (FACE-015)."""

import io

from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def test_liveness_successful_evaluation():
    fake_jpeg = b"\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00" + b"\x00" * 100

    response = client.post(
        "/internal/v1/face/liveness",
        files={"file": ("face.jpg", io.BytesIO(fake_jpeg), "image/jpeg")},
    )

    assert response.status_code == 200
    data = response.json()
    assert data["success"] is True
    assert "liveness_score" in data
    assert data["status"] in ["REAL", "SPOOF", "UNCERTAIN"]
    assert "is_live" in data
    assert "method" in data


def test_liveness_empty_file_rejected():
    response = client.post(
        "/internal/v1/face/liveness",
        files={"file": ("empty.jpg", io.BytesIO(b""), "image/jpeg")},
    )

    assert response.status_code == 422
