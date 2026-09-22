"""Camera health delivery exceptions (CAM-013)."""


class CameraHealthDeliveryError(Exception):
    """Raised when delivering a camera health sample to the backend fails
    (network, timeout, non-2xx). Never contains the bearer token."""
