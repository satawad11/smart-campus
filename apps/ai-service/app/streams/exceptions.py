"""VideoSource domain exceptions (CAM-005)."""


class VideoSourceError(Exception):
    """Base class for all video-source domain exceptions."""

    pass


class VideoSourceAlreadyOpenError(VideoSourceError):
    """Raised by open() when the source is already open."""

    pass


class VideoSourceNotOpenError(VideoSourceError):
    """Raised by read() (or any operation requiring an open source) when the
    source has not been opened, or was already closed."""

    pass


class VideoSourceConnectionError(VideoSourceError):
    """Raised by open() when the underlying source cannot be reached/opened."""

    pass


class VideoSourceReadError(VideoSourceError):
    """Raised by read() on a genuine I/O/decode failure, distinct from a
    clean end-of-stream (see VideoSourceEOFError)."""

    pass


class VideoSourceEOFError(VideoSourceError):
    """Raised by read() when the source has no more frames to produce (e.g. a
    finite recorded/mock source is exhausted). Not an error condition for a
    live, continuously-producing source — those never raise this."""

    pass
