import pytest


def test_recognize_defaults_to_match(client):
    response = client.post("/recognize", json={})
    assert response.status_code == 200
    body = response.json()
    assert body["mode"] == "mock"
    assert body["outcome"] == "MATCH"
    assert body["classification"] == "MATCH"
    assert body["person_uuid"] is not None
    # Model name/version are always reported (PROJECT_RULES §10).
    assert body["model_name"] == "mock-face"
    assert body["model_version"]


@pytest.mark.parametrize(
    ("outcome", "classification", "quality_ok", "liveness_ok"),
    [
        ("UNKNOWN", "UNKNOWN", True, True),
        ("LOW_CONFIDENCE", "REVIEW", True, True),
        ("LOW_QUALITY", "UNKNOWN", False, True),
        ("LIVENESS_FAIL", "REVIEW", True, False),
    ],
)
def test_recognize_forced_mock_outcomes(client, outcome, classification, quality_ok, liveness_ok):
    response = client.post("/recognize", json={"mock_outcome": outcome})
    assert response.status_code == 200
    body = response.json()
    assert body["outcome"] == outcome
    assert body["classification"] == classification
    assert body["quality_ok"] is quality_ok
    assert body["liveness_ok"] is liveness_ok
    # Mock recognition never fabricates a Person for non-MATCH outcomes.
    assert body["person_uuid"] is None


def test_recognize_rejects_invalid_outcome(client):
    response = client.post("/recognize", json={"mock_outcome": "NOPE"})
    assert response.status_code == 422
