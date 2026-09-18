def test_health_returns_ok(client):
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok", "service": "ai-service"}


def test_version_reports_mock_mode(client):
    response = client.get("/version")
    assert response.status_code == 200
    body = response.json()
    assert body["ai_mode"] == "mock"
    assert "version" in body
    assert body["service"]
