"""Durable offline event buffer (CAM-012).

If the backend is unavailable, a recognition event must not be lost: it is
stored durably on the AI node (a local SQLite file — survives process
restart, no extra dependency beyond the stdlib) and retried with bounded
exponential backoff + jitter (same shape as CAM-006's RTSP reconnect
backoff) until it is delivered or an operator intervenes. event_uuid and
occurred_at are preserved exactly as first observed — replaying the buffer
never re-stamps them with retry time.
"""

import json
import random
import sqlite3
import threading
import time
from collections.abc import Callable
from dataclasses import asdict, dataclass
from datetime import datetime

from app.delivery.schemas import RecognitionEventPayload

_SCHEMA = """
CREATE TABLE IF NOT EXISTS pending_events (
    event_uuid TEXT PRIMARY KEY,
    payload_json TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    next_retry_at REAL NOT NULL,
    enqueued_at REAL NOT NULL
);
CREATE TABLE IF NOT EXISTS rejected_events (
    event_uuid TEXT PRIMARY KEY,
    payload_json TEXT NOT NULL,
    status_code INTEGER NOT NULL,
    error_code TEXT,
    rejected_at REAL NOT NULL
)
"""


@dataclass(frozen=True)
class BufferedEvent:
    payload: RecognitionEventPayload
    attempts: int


def _serialize(payload: RecognitionEventPayload) -> str:
    data = asdict(payload)
    data["occurred_at"] = payload.occurred_at.isoformat()
    return json.dumps(data)


def _deserialize(raw: str) -> RecognitionEventPayload:
    data = json.loads(raw)
    data["occurred_at"] = datetime.fromisoformat(data["occurred_at"])
    return RecognitionEventPayload(**data)


class OfflineEventBuffer:
    """Bounded-retry durable store for events that failed immediate delivery.

    Not bounded in row count (unlike RecognitionCache/the sampler queue):
    a recognition event is a durable record that must eventually reach the
    backend, so it is never dropped for capacity — only ever removed once
    delivered. Callers wanting a hard cap can still enforce one externally
    via `size`.

    Thread-safe: camera worker threads enqueue, the retry thread reads and
    updates, and status endpoints read `size`, all on one SQLite connection.
    Every use of the connection holds `_lock`; unsynchronised sharing
    returned corrupt reads and could make `enqueue` raise, losing the event.
    """

    def __init__(
        self,
        db_path: str = ":memory:",
        initial_backoff_seconds: float = 5.0,
        max_backoff_seconds: float = 300.0,
        backoff_multiplier: float = 2.0,
        jitter_ratio: float = 0.1,
        clock_fn: Callable[[], float] | None = None,
        random_fn: Callable[[], float] | None = None,
    ):
        self._initial_backoff_seconds = initial_backoff_seconds
        self._max_backoff_seconds = max_backoff_seconds
        self._backoff_multiplier = backoff_multiplier
        self._jitter_ratio = jitter_ratio
        self._clock_fn = clock_fn or time.time
        self._random_fn = random_fn or random.random

        self._lock = threading.RLock()
        self._conn = sqlite3.connect(db_path, check_same_thread=False)
        with self._lock:
            self._conn.executescript(_SCHEMA)
            self._conn.commit()

    @property
    def size(self) -> int:
        with self._lock:
            row = self._conn.execute("SELECT COUNT(*) FROM pending_events").fetchone()
        return row[0]

    def enqueue(self, payload: RecognitionEventPayload) -> None:
        """Idempotent on event_uuid: re-enqueuing an already-buffered event
        (e.g. the caller retried before the buffer confirmed the insert)
        does not create a duplicate or reset its retry backoff."""
        now = self._clock_fn()
        with self._lock:
            self._conn.execute(
                """
                INSERT INTO pending_events
                    (event_uuid, payload_json, attempts, next_retry_at, enqueued_at)
                VALUES (?, ?, 0, ?, ?)
                ON CONFLICT(event_uuid) DO NOTHING
                """,
                (payload.event_uuid, _serialize(payload), now, now),
            )
            self._conn.commit()

    def due(self, limit: int = 50) -> list[BufferedEvent]:
        """Events whose next retry time has arrived, oldest-enqueued first."""
        now = self._clock_fn()
        with self._lock:
            rows = self._conn.execute(
                """
                SELECT payload_json, attempts FROM pending_events
                WHERE next_retry_at <= ?
                ORDER BY enqueued_at ASC
                LIMIT ?
                """,
                (now, limit),
            ).fetchall()
        return [
            BufferedEvent(payload=_deserialize(raw), attempts=attempts) for raw, attempts in rows
        ]

    def mark_delivered(self, event_uuid: str) -> None:
        with self._lock:
            self._conn.execute("DELETE FROM pending_events WHERE event_uuid = ?", (event_uuid,))
            self._conn.commit()

    def mark_failed(self, event_uuid: str) -> None:
        with self._lock:
            self._mark_failed_locked(event_uuid)

    def _mark_failed_locked(self, event_uuid: str) -> None:
        row = self._conn.execute(
            "SELECT attempts FROM pending_events WHERE event_uuid = ?", (event_uuid,)
        ).fetchone()
        if row is None:
            return
        attempts = row[0] + 1
        backoff = min(
            self._initial_backoff_seconds * (self._backoff_multiplier ** (attempts - 1)),
            self._max_backoff_seconds,
        )
        jitter = 1.0 + (self._random_fn() * 2 - 1) * self._jitter_ratio
        backoff = min(backoff * jitter, self._max_backoff_seconds)
        next_retry_at = self._clock_fn() + backoff

        self._conn.execute(
            "UPDATE pending_events SET attempts = ?, next_retry_at = ? WHERE event_uuid = ?",
            (attempts, next_retry_at, event_uuid),
        )
        self._conn.commit()

    @property
    def rejected_count(self) -> int:
        with self._lock:
            row = self._conn.execute("SELECT COUNT(*) FROM rejected_events").fetchone()
        return row[0]

    def reject(
        self, payload: RecognitionEventPayload, status_code: int, error_code: str | None
    ) -> None:
        """Quarantine an event the backend permanently refused: kept for
        inspection (never silently dropped) and removed from the retry queue."""
        with self._lock:
            self._conn.execute(
                """
                INSERT INTO rejected_events
                    (event_uuid, payload_json, status_code, error_code, rejected_at)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(event_uuid) DO NOTHING
                """,
                (
                    payload.event_uuid,
                    _serialize(payload),
                    status_code,
                    error_code,
                    self._clock_fn(),
                ),
            )
            self._conn.execute(
                "DELETE FROM pending_events WHERE event_uuid = ?", (payload.event_uuid,)
            )
            self._conn.commit()

    def close(self) -> None:
        with self._lock:
            self._conn.close()
