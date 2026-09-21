"""Face alignment exceptions (FACE-005)."""


class AlignmentError(Exception):
    """Base exception for all face alignment domain errors."""

    pass


class AlignerNotReadyError(AlignmentError):
    """Raised when the face aligner provider or model is uninitialized or unready."""

    pass


class InvalidLandmarksError(AlignmentError):
    """Raised when facial landmarks are missing, malformed, non-finite, or degenerate."""

    pass


class AlignmentRuntimeError(AlignmentError):
    """Raised when runtime geometric transformation or interpolation fails."""

    pass
