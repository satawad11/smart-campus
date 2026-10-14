"""Recognition-event delivery exceptions (CAM-011)."""


class EventDeliveryError(Exception):
    """Raised when delivering a recognition event to the backend fails
    (network, timeout, non-2xx). Never contains the bearer token in its
    message. Callers (e.g. CAM-012's durable offline buffer) catch this to
    decide whether to retry/store the event — this module itself makes a
    single delivery attempt and does not retry or buffer."""


class EventRejectedError(EventDeliveryError):
    """The backend permanently refused the event (HTTP 400/409/422: invalid
    or conflicting content). Retrying can never succeed, so the buffer moves
    it to quarantine instead of retrying forever. Carries the status code and
    the backend's error code only (no payload, no token)."""

    def __init__(self, message: str, status_code: int, error_code: str | None = None):
        super().__init__(message)
        self.status_code = status_code
        self.error_code = error_code
