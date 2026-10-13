"""Tests for the durable offline event buffer and its retry-aware delivery
service (CAM-012). Uses an in-memory SQLite DB (no filesystem dependency)
and injectable clock/random functions for deterministic backoff timing.
"""

from datetime import UTC, datetime

from app.delivery.buffer import OfflineEventBuffer
from app.delivery.buffered_service import BufferedEventDeliveryService
from app.delivery.client import EventDeliveryClient
from app.delivery.exceptions import EventDeliveryError
from app.delivery.schemas import RecognitionEventPayload
from app.delivery.service import to_event_payload
from app.orchestration.schemas import RecognitionWorkerResult


class FakeClock:
    def __init__(self, start: float = 1000.0):
        self._now = start

    def __call__(self) -> float:
        return self._now

    def advance(self, seconds: float) -> None:
        self._now += seconds


def zero_jitter() -> float:
    return 0.5  # midpoint -> jitter multiplier of exactly 1.0


def make_result(event_uuid: str = "event-1") -> RecognitionWorkerResult:
    return RecognitionWorkerResult(
        event_uuid=event_uuid,
        camera_uuid="camera-uuid-1",
        ai_node_uuid="node-uuid-1",
        track_id="track-1",
        observed_at=datetime(2026, 1, 1, 12, 0, 0, tzinfo=UTC),
        classification="MATCH",
        candidate_person_uuid="person-uuid-1",
        similarity=0.95,
        distance=0.05,
        model_code="arcface-r50-v1",
        model_version="1.0.0",
        quality_score=0.9,
        liveness_status="REAL",
        rejected_locally=False,
    )


def make_payload(event_uuid: str = "event-1") -> RecognitionEventPayload:
    return to_event_payload(make_result(event_uuid))


class TestOfflineEventBufferBasics:
    def test_empty_buffer_has_no_due_events(self) -> None:
        buffer = OfflineEventBuffer(clock_fn=FakeClock())
        assert buffer.due() == []
        assert buffer.size == 0

    def test_enqueued_event_is_immediately_due(self) -> None:
        clock = FakeClock()
        buffer = OfflineEventBuffer(clock_fn=clock)
        buffer.enqueue(make_payload())

        due = buffer.due()
        assert len(due) == 1
        assert due[0].payload.event_uuid == "event-1"
        assert due[0].attempts == 0
        assert buffer.size == 1

    def test_event_uuid_and_occurred_at_survive_round_trip(self) -> None:
        buffer = OfflineEventBuffer(clock_fn=FakeClock())
        original = make_payload()
        buffer.enqueue(original)

        [due] = buffer.due()
        assert due.payload.event_uuid == original.event_uuid
        assert due.payload.occurred_at == original.occurred_at

    def test_reenqueue_of_same_event_uuid_is_idempotent(self) -> None:
        buffer = OfflineEventBuffer(clock_fn=FakeClock())
        buffer.enqueue(make_payload("event-1"))
        buffer.enqueue(make_payload("event-1"))
        assert buffer.size == 1

    def test_mark_delivered_removes_the_event(self) -> None:
        buffer = OfflineEventBuffer(clock_fn=FakeClock())
        buffer.enqueue(make_payload())
        buffer.mark_delivered("event-1")
        assert buffer.size == 0
        assert buffer.due() == []

    def test_mark_delivered_on_unknown_event_uuid_is_a_no_op(self) -> None:
        buffer = OfflineEventBuffer(clock_fn=FakeClock())
        buffer.mark_delivered("does-not-exist")  # must not raise
        assert buffer.size == 0


class TestBackoff:
    def test_failed_event_is_not_due_until_backoff_elapses(self) -> None:
        clock = FakeClock()
        buffer = OfflineEventBuffer(
            initial_backoff_seconds=10,
            clock_fn=clock,
            random_fn=zero_jitter,
        )
        buffer.enqueue(make_payload())
        buffer.mark_failed("event-1")

        assert buffer.due() == []  # not yet due
        clock.advance(10)
        assert len(buffer.due()) == 1

    def test_backoff_grows_exponentially_and_never_exceeds_the_cap(self) -> None:
        clock = FakeClock()
        buffer = OfflineEventBuffer(
            initial_backoff_seconds=10,
            max_backoff_seconds=25,
            backoff_multiplier=2.0,
            clock_fn=clock,
            random_fn=zero_jitter,
        )
        buffer.enqueue(make_payload())

        buffer.mark_failed("event-1")  # attempt 1 -> backoff 10
        clock.advance(9.999)
        assert buffer.due() == []
        clock.advance(0.002)
        assert len(buffer.due()) == 1

        buffer.mark_failed("event-1")  # attempt 2 -> backoff 20
        clock.advance(19.999)
        assert buffer.due() == []
        clock.advance(0.002)
        assert len(buffer.due()) == 1

        buffer.mark_failed("event-1")  # attempt 3 -> backoff would be 40, capped at 25
        clock.advance(24.999)
        assert buffer.due() == []
        clock.advance(0.002)
        assert len(buffer.due()) == 1

    def test_repeated_failure_never_drops_the_event(self) -> None:
        clock = FakeClock()
        buffer = OfflineEventBuffer(
            initial_backoff_seconds=1, clock_fn=clock, random_fn=zero_jitter
        )
        buffer.enqueue(make_payload())
        for _ in range(20):
            buffer.mark_failed("event-1")
            clock.advance(1000)
        assert buffer.size == 1


class FakeDeliveryClient(EventDeliveryClient):
    def __init__(self, fail_uuids: set[str] | None = None):
        self.fail_uuids = fail_uuids or set()
        self.delivered: list[str] = []

    def deliver(self, payload: RecognitionEventPayload) -> None:
        if payload.event_uuid in self.fail_uuids:
            raise EventDeliveryError("backend unavailable")
        self.delivered.append(payload.event_uuid)


class TestBufferedEventDeliveryService:
    def test_successful_immediate_delivery_never_touches_the_buffer(self) -> None:
        client = FakeDeliveryClient()
        buffer = OfflineEventBuffer(clock_fn=FakeClock())
        service = BufferedEventDeliveryService(client, buffer)

        outcome = service.deliver(make_result())

        assert outcome.delivered is True
        assert client.delivered == ["event-1"]
        assert buffer.size == 0

    def test_cached_result_is_skipped_and_never_buffered(self) -> None:
        client = FakeDeliveryClient()
        buffer = OfflineEventBuffer(clock_fn=FakeClock())
        service = BufferedEventDeliveryService(client, buffer)
        result = make_result()
        cached = RecognitionWorkerResult(
            **{**result.__dict__, "from_cache": True},
        )

        outcome = service.deliver(cached)

        assert outcome.skipped is True
        assert buffer.size == 0

    def test_failed_immediate_delivery_is_buffered_not_raised(self) -> None:
        client = FakeDeliveryClient(fail_uuids={"event-1"})
        buffer = OfflineEventBuffer(clock_fn=FakeClock())
        service = BufferedEventDeliveryService(client, buffer)

        outcome = service.deliver(make_result())

        assert outcome.delivered is False
        assert outcome.reason == "buffered_offline"
        assert buffer.size == 1

    def test_retry_pending_delivers_a_now_reachable_backend(self) -> None:
        client = FakeDeliveryClient(fail_uuids={"event-1"})
        buffer = OfflineEventBuffer(clock_fn=FakeClock())
        service = BufferedEventDeliveryService(client, buffer)
        service.deliver(make_result())
        assert buffer.size == 1

        client.fail_uuids.clear()  # backend is back up
        result = service.retry_pending()

        assert result.delivered == 1
        assert result.still_pending == 0
        assert client.delivered == ["event-1"]

    def test_retry_pending_reschedules_still_failing_events(self) -> None:
        clock = FakeClock()
        client = FakeDeliveryClient(fail_uuids={"event-1"})
        buffer = OfflineEventBuffer(clock_fn=clock, random_fn=zero_jitter)
        service = BufferedEventDeliveryService(client, buffer)
        service.deliver(make_result())

        result = service.retry_pending()
        assert result.delivered == 0
        assert result.still_pending == 1

        # Still within backoff -> not due yet.
        assert buffer.due() == []

    def test_retry_pending_preserves_event_uuid_and_occurred_at(self) -> None:
        client = FakeDeliveryClient(fail_uuids={"event-1"})
        buffer = OfflineEventBuffer(clock_fn=FakeClock())
        service = BufferedEventDeliveryService(client, buffer)
        original = make_result()
        service.deliver(original)

        client.fail_uuids.clear()
        [due] = buffer.due()
        assert due.payload.event_uuid == original.event_uuid
        assert due.payload.occurred_at == original.observed_at


class TestOfflineEventBufferConcurrency:
    """Regression: one SQLite connection is shared by camera worker threads
    (enqueue), the retry thread (due / mark_*) and status reads (size).
    Unsynchronised, size() intermittently read no row (TypeError) and calls
    could raise, which in enqueue would lose the event."""

    def test_concurrent_enqueue_retry_and_size_are_safe_and_lose_nothing(self) -> None:
        import threading

        buffer = OfflineEventBuffer(initial_backoff_seconds=0.0)
        errors: list[BaseException] = []
        writers, per_writer = 8, 50
        stop = threading.Event()

        def guard(fn):
            def run():
                try:
                    fn()
                except BaseException as exc:  # noqa: BLE001 - collected for the assertion
                    errors.append(exc)

            return run

        def writer(n: int):
            for i in range(per_writer):
                buffer.enqueue(make_payload(f"w{n}-e{i}"))

        def reader():
            while not stop.is_set():
                assert isinstance(buffer.size, int)

        def retrier():
            while not stop.is_set():
                for event in buffer.due(limit=10):
                    buffer.mark_failed(event.payload.event_uuid)

        background = [threading.Thread(target=guard(reader)) for _ in range(3)]
        background.append(threading.Thread(target=guard(retrier)))
        workers = [threading.Thread(target=guard(lambda n=n: writer(n))) for n in range(writers)]
        for t in background + workers:
            t.start()
        for t in workers:
            t.join()
        stop.set()
        for t in background:
            t.join()

        assert errors == []
        assert buffer.size == writers * per_writer
