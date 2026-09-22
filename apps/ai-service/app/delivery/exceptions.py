"""Recognition-event delivery exceptions (CAM-011)."""


class EventDeliveryError(Exception):
    """Raised when delivering a recognition event to the backend fails
    (network, timeout, non-2xx). Never contains the bearer token in its
    message. Callers (e.g. CAM-012's durable offline buffer) catch this to
    decide whether to retry/store the event — this module itself makes a
    single delivery attempt and does not retry or buffer."""
