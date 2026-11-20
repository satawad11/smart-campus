"""Client for the backend's internal candidate-search/classification endpoint
(CAM-009). Calls POST /internal/v1/face/recognize (CAM-004 ai_node.token
auth) with the embedding this node already computed locally — never sends a
raw frame/image to the backend for this path, and never logs the bearer
token or the embedding values.
"""

from abc import ABC, abstractmethod
from typing import Any

from app.orchestration.schemas import BackendRecognitionResponse


class RecognitionBackendError(Exception):
    """Raised when the backend call fails (network, timeout, non-2xx, or a
    malformed response) — never contains the bearer token in its message."""


class RecognitionBackendClient(ABC):
    """Abstract interface — production uses HttpRecognitionBackendClient;
    tests inject a fake, matching the project's "no real network dependency
    in tests" convention (same pattern as CAM-006's capture_factory)."""

    @abstractmethod
    def recognize(
        self, embedding: list[float], model_code: str, model_version: str
    ) -> BackendRecognitionResponse: ...


class HttpRecognitionBackendClient(RecognitionBackendClient):
    """Real backend client. Imports httpx lazily (module load must not fail
    if it isn't installed in a given deployment — same convention as
    app.streams.rtsp's lazy cv2 import)."""

    def __init__(self, base_url: str, node_token: str, timeout_seconds: float = 5.0):
        self._base_url = base_url.rstrip("/")
        self._node_token = node_token
        self._timeout_seconds = timeout_seconds

    def recognize(
        self, embedding: list[float], model_code: str, model_version: str
    ) -> BackendRecognitionResponse:
        try:
            import httpx  # noqa: PLC0415 (intentionally lazy)
        except ImportError as exc:
            raise RecognitionBackendError("httpx is not installed") from exc

        try:
            response = httpx.post(
                f"{self._base_url}/internal/v1/face/recognize",
                json={
                    "embedding": embedding,
                    "model_code": model_code,
                    "model_version": model_version,
                },
                headers={"Authorization": f"Bearer {self._node_token}"},
                timeout=self._timeout_seconds,
            )
        except Exception as exc:  # noqa: BLE001 — normalize any transport failure
            raise RecognitionBackendError(f"Backend recognition call failed: {exc}") from exc

        if response.status_code != 200:
            raise RecognitionBackendError(
                f"Backend recognition call returned HTTP {response.status_code}"
            )

        return self._parse(response.json())

    @staticmethod
    def _parse(payload: dict[str, Any]) -> BackendRecognitionResponse:
        try:
            data = payload["data"]
            classification = data["classification"]
            candidate = data.get("matched_candidate") if classification == "MATCH" else None

            # The backend returns ranked `candidates` for REVIEW/UNKNOWN and a
            # separate `matched_candidate` only for MATCH. Preserve genuine
            # nearest-neighbour evidence so operators can diagnose recognition
            # without inventing a result. UNKNOWN deliberately drops identity:
            # its score is diagnostic evidence, never a person assignment.
            if classification in {"REVIEW", "UNKNOWN"}:
                candidates = data.get("candidates")
                if isinstance(candidates, list) and candidates and isinstance(candidates[0], dict):
                    candidate = candidates[0]

            candidate_uuid = None
            if classification in {"MATCH", "REVIEW"} and isinstance(candidate, dict):
                candidate_uuid = candidate.get("candidate_person_uuid")

            return BackendRecognitionResponse(
                classification=classification,
                candidate_person_uuid=candidate_uuid,
                similarity=candidate.get("similarity") if isinstance(candidate, dict) else None,
                distance=candidate.get("distance") if isinstance(candidate, dict) else None,
                reasons=data.get("reasons", []),
            )
        except (KeyError, TypeError) as exc:
            raise RecognitionBackendError("Malformed backend recognition response") from exc
