"""Tests for HttpRecognitionBackendClient (CAM-009).

No real network dependency — httpx.post is monkeypatched.
"""

import pytest

from app.orchestration.backend_client import HttpRecognitionBackendClient, RecognitionBackendError


class FakeResponse:
    def __init__(self, status_code: int, payload: dict):
        self.status_code = status_code
        self._payload = payload

    def json(self) -> dict:
        return self._payload


class TestHttpRecognitionBackendClient:
    def test_successful_match_response_is_parsed(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            assert url.endswith("/internal/v1/face/recognize")
            assert headers["Authorization"] == "Bearer test-token"
            return FakeResponse(
                200,
                {
                    "data": {
                        "classification": "MATCH",
                        "matched_candidate": {
                            "candidate_person_uuid": "person-uuid-1",
                            "similarity": 0.95,
                            "distance": 0.05,
                        },
                        "reasons": ["HIGH_SIMILARITY_MATCH"],
                    }
                },
            )

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpRecognitionBackendClient(base_url="http://backend", node_token="test-token")
        result = client.recognize([0.1] * 512, "arcface-r50-v1", "1.0.0")

        assert result.classification == "MATCH"
        assert result.candidate_person_uuid == "person-uuid-1"
        assert result.similarity == pytest.approx(0.95)

    def test_unknown_response_has_no_matched_candidate(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            return FakeResponse(
                200,
                {"data": {"classification": "UNKNOWN", "matched_candidate": None, "reasons": []}},
            )

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpRecognitionBackendClient(base_url="http://backend", node_token="test-token")
        result = client.recognize([0.1] * 512, "arcface-r50-v1", "1.0.0")

        assert result.classification == "UNKNOWN"
        assert result.candidate_person_uuid is None

    def test_review_preserves_the_real_top_candidate(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            return FakeResponse(
                200,
                {
                    "data": {
                        "classification": "REVIEW",
                        "matched_candidate": None,
                        "candidates": [
                            {
                                "candidate_person_uuid": "person-review-1",
                                "similarity": 0.72,
                                "distance": 0.28,
                            }
                        ],
                        "reasons": ["AMBIGUOUS_REVIEW"],
                    }
                },
            )

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)
        result = HttpRecognitionBackendClient("http://backend", "test-token").recognize(
            [0.1] * 512, "arcface-r50-v1", "1.0.0"
        )

        assert result.classification == "REVIEW"
        assert result.candidate_person_uuid == "person-review-1"
        assert result.similarity == pytest.approx(0.72)
        assert result.distance == pytest.approx(0.28)

    def test_unknown_preserves_only_real_score_not_candidate_identity(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            return FakeResponse(
                200,
                {
                    "data": {
                        "classification": "UNKNOWN",
                        "matched_candidate": None,
                        "candidates": [
                            {
                                "candidate_person_uuid": "must-not-be-assigned",
                                "similarity": 0.41,
                                "distance": 0.59,
                            }
                        ],
                        "reasons": ["BELOW_REVIEW_THRESHOLD"],
                    }
                },
            )

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)
        result = HttpRecognitionBackendClient("http://backend", "test-token").recognize(
            [0.1] * 512, "arcface-r50-v1", "1.0.0"
        )

        assert result.candidate_person_uuid is None
        assert result.similarity == pytest.approx(0.41)
        assert result.distance == pytest.approx(0.59)

    def test_malformed_candidates_do_not_fabricate_evidence(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            return FakeResponse(
                200,
                {"data": {"classification": "REVIEW", "candidates": ["invalid"], "reasons": []}},
            )

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)
        result = HttpRecognitionBackendClient("http://backend", "test-token").recognize(
            [0.1] * 512, "arcface-r50-v1", "1.0.0"
        )

        assert result.candidate_person_uuid is None
        assert result.similarity is None
        assert result.distance is None

    def test_non_200_status_raises_backend_error(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            return FakeResponse(422, {"error": {"code": "EMBEDDING_SEARCH_FAILED"}})

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpRecognitionBackendClient(base_url="http://backend", node_token="test-token")
        with pytest.raises(RecognitionBackendError):
            client.recognize([0.1] * 512, "arcface-r50-v1", "1.0.0")

    def test_transport_failure_raises_backend_error(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            raise ConnectionError("network unreachable")

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpRecognitionBackendClient(base_url="http://backend", node_token="test-token")
        with pytest.raises(RecognitionBackendError):
            client.recognize([0.1] * 512, "arcface-r50-v1", "1.0.0")

    def test_malformed_response_raises_backend_error(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            return FakeResponse(200, {"unexpected": "shape"})

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpRecognitionBackendClient(base_url="http://backend", node_token="test-token")
        with pytest.raises(RecognitionBackendError):
            client.recognize([0.1] * 512, "arcface-r50-v1", "1.0.0")

    def test_error_message_never_contains_bearer_token(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            raise ConnectionError("boom")

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpRecognitionBackendClient(
            base_url="http://backend", node_token="super-secret-token"
        )
        with pytest.raises(RecognitionBackendError) as exc_info:
            client.recognize([0.1] * 512, "arcface-r50-v1", "1.0.0")
        assert "super-secret-token" not in str(exc_info.value)
