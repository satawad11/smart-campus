"""Abstract VideoSource interface (CAM-005).

Provider-independent frame acquisition contract. Concrete providers: a
deterministic mock (this task) and a real RTSP source (CAM-006). No
detection/recognition happens here — this module only ever produces frames.
"""

from abc import ABC, abstractmethod
from types import TracebackType

from app.streams.schemas import Frame, SourceState


class VideoSource(ABC):
    """Abstract interface for a frame-producing video source.

    Lifecycle: open() -> read() (zero or more times) -> close(). close() is
    always safe to call, including on an already-closed or never-opened
    source (idempotent, no resource leak). open() on an already-open source
    is a programming error (VideoSourceAlreadyOpenError) — callers that want
    "ensure open" semantics should check is_open first.

    Also usable as a context manager (`with source: ...`), which guarantees
    close() runs even if an exception propagates from inside the block.
    """

    @property
    @abstractmethod
    def name(self) -> str:
        """Provider name identifier."""
        ...

    @property
    @abstractmethod
    def is_open(self) -> bool:
        """Whether the source is currently open and able to produce frames."""
        ...

    @property
    @abstractmethod
    def state(self) -> SourceState:
        """Current lifecycle state (CLOSED / OPEN / ERROR)."""
        ...

    @abstractmethod
    def open(self) -> None:
        """Open the source.

        Raises:
            VideoSourceAlreadyOpenError: If already open.
            VideoSourceConnectionError: If the source cannot be reached/opened.
        """
        ...

    @abstractmethod
    def read(self) -> Frame:
        """Read the next available frame.

        Raises:
            VideoSourceNotOpenError: If the source has not been opened (or
                was already closed).
            VideoSourceEOFError: If the source has no more frames (finite
                sources only — a live source never raises this).
            VideoSourceReadError: On a genuine read/decode failure.
        """
        ...

    @abstractmethod
    def close(self) -> None:
        """Close the source and release any underlying resources.

        Always safe to call — including when already closed or never
        opened. Never raises for "already closed".
        """
        ...

    def __enter__(self) -> "VideoSource":
        self.open()
        return self

    def __exit__(
        self,
        exc_type: type[BaseException] | None,
        exc_val: BaseException | None,
        exc_tb: TracebackType | None,
    ) -> None:
        self.close()
