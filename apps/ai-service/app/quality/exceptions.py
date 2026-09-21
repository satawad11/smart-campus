"""Face quality evaluation domain exceptions (FACE-006)."""


class QualityError(Exception):
    """Base exception for all face quality evaluation domain errors."""

    pass


class QualityEvaluatorNotReadyError(QualityError):
    """Raised when the face quality evaluator is uninitialized or unready."""

    pass


class InvalidQualityInputError(QualityError):
    """Raised when input image or detection data is invalid, empty, or non-finite."""

    pass


class QualityEvaluationRuntimeError(QualityError):
    """Raised when runtime quality metric computation fails."""

    pass
