"""Detection domain exceptions (FACE-004)."""


class DetectorError(Exception):
    """Base class for all face detection domain exceptions."""

    pass


class DetectorNotReadyError(DetectorError):
    """Raised when the detector provider or model is not initialized or ready."""

    pass


class InvalidImageError(DetectorError):
    """Raised when input image is None, empty, zero-sized, or has invalid shape/channels."""

    pass


class DetectionRuntimeError(DetectorError):
    """Raised when runtime detection execution fails."""

    pass
