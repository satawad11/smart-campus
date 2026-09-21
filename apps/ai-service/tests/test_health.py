"""Tests for AI Service Liveness and Readiness Probes (FACE-003)."""

from fastapi.testclient import TestClient

from app.core.config import Settings
from app.core.health import ReadinessChecker, readiness_checker


def test_liveness_endpoints_return_ok(client: TestClient):
    """Liveness probes (/health and /health/live) return 200 and safe contract."""
    for path in ["/health", "/health/live"]:
        response = client.get(path)
        assert response.status_code == 200
        data = response.json()
        assert data["status"] == "ok"
        assert data["service"] == "ai-service"
        # Invariant: No internal paths or secrets
        assert "password" not in data
        assert "secret" not in data


def test_readiness_endpoints_return_ready_in_mock_mode(client: TestClient):
    """Readiness probes (/ready and /health/ready) return 200 and checks in mock mode."""
    readiness_checker.is_started = True

    for path in ["/ready", "/health/ready"]:
        response = client.get(path)
        assert response.status_code == 200
        data = response.json()
        assert data["status"] == "ready"
        assert data["service"] == "ai-service"
        assert "version" in data
        assert "checks" in data
        assert data["checks"]["config"] == "ok"
        assert data["checks"]["startup"] == "ok"
        assert data["checks"]["runtime"] == "ok"
        assert data["checks"]["models"] == "ok"


def test_readiness_fails_503_when_uninitialized(client: TestClient):
    """Readiness probe returns 503 when startup is uninitialized."""
    try:
        readiness_checker.is_started = False

        response = client.get("/ready")
        assert response.status_code == 503
        data = response.json()
        assert data["status"] == "not_ready"
        assert data["checks"]["startup"] == "startup_uninitialized"

        # Liveness must STILL return 200 (process is alive even if not ready)
        liveness_resp = client.get("/health")
        assert liveness_resp.status_code == 200
        assert liveness_resp.json()["status"] == "ok"
    finally:
        readiness_checker.is_started = True


def test_readiness_fails_503_when_config_invalid(client: TestClient):
    """Readiness probe returns 503 when configuration is invalid."""
    invalid_settings = Settings(app_name="Test", version="0.1.0", ai_mode="invalid_mode")
    checker = ReadinessChecker(current_settings=invalid_settings)

    is_ready, checks = checker.evaluate_readiness()
    assert not is_ready
    assert checks["config"] == "invalid_ai_mode_invalid_mode"


def test_readiness_fails_when_onnx_runtime_missing(client: TestClient, monkeypatch):
    """Readiness probe returns 503 when in ONNX mode and onnxruntime is absent."""
    onnx_settings = Settings(app_name="Test", version="0.1.0", ai_mode="onnx")
    checker = ReadinessChecker(current_settings=onnx_settings)

    # Simulate onnxruntime not installed
    monkeypatch.setattr(
        "importlib.util.find_spec",
        lambda name: None if name == "onnxruntime" else True,
    )

    is_ready, checks = checker.evaluate_readiness()
    assert not is_ready
    assert checks["runtime"] == "onnxruntime_unavailable"


def test_version_reports_safe_metadata(client: TestClient):
    """Version endpoint returns non-secret application and runtime metadata."""
    response = client.get("/version")
    assert response.status_code == 200
    body = response.json()
    assert body["ai_mode"] == "mock"
    assert "version" in body
    assert body["service"]
