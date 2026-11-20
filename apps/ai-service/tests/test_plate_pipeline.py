"""VEH-008: node plate pipeline — crop, OCR decoding, province, per-track vote, camera wiring.

Model-free: detectors and recognisers are mocks. tests/test_plate_models.py runs the real
models when scripts/fetch-plate-models.sh has populated models/.
"""

from __future__ import annotations

import csv
import itertools
from datetime import UTC, datetime, timedelta

import cv2
import numpy as np
import pytest

from app.delivery.plate import PlateEventPayload
from app.delivery.schemas import DeliveryOutcome
from app.orchestration.orchestrator import (
    PLATE_MODEL_CODE,
    CameraPipelineRunner,
    NodeOrchestrator,
)
from app.plates import thai
from app.plates.detector import (
    LpdYuNetPlateDetector,
    MockPlateDetector,
    UnavailablePlateDetector,
    detection_from_corners,
)
from app.plates.evaluate import run as run_evaluation
from app.plates.factory import get_plate_reader
from app.plates.ocr import (
    PLATE_HEIGHT,
    PLATE_WIDTH,
    MockTextLineRecognizer,
    PaddleTextLineRecognizer,
    ctc_decode,
    load_dictionary,
    order_corners,
    rectify,
    split_rows,
)
from app.plates.pipeline import (
    REASON_PROVINCE_UNRESOLVED,
    REASON_TEXT_INVALID,
    REASON_VOTE_DISAGREEMENT,
    CameraPlateSession,
    PlateReader,
    vote,
)
from app.plates.schemas import PlateRead, TextLine
from app.roi.schemas import RegionOfInterest, RoiPoint
from app.sampling.schemas import SampledFrame
from app.streams.schemas import Frame

T0 = datetime(2026, 11, 20, 8, 0, tzinfo=UTC)
FRAME = np.zeros((480, 640, 3), dtype=np.uint8)


def plate_at(x: float, y: float, score: float = 0.9, w: float = 120, h: float = 50):
    det = detection_from_corners([(x, y), (x + w, y), (x + w, y + h), (x, y + h)], score, 640, 480)
    assert det is not None
    return det


class CyclingRecognizer(MockTextLineRecognizer):
    """Alternates registration / province lines forever."""

    def __init__(self, top: TextLine, bottom: TextLine):
        super().__init__()
        self._lines = itertools.cycle([top, bottom])

    def read(self, line_bgr: np.ndarray) -> TextLine:
        return next(self._lines)


def reader_for(detector, top="1กข1234", bottom="กรุงเทพมหานคร", score=0.9) -> PlateReader:
    return PlateReader(detector, CyclingRecognizer(TextLine(top, score), TextLine(bottom, 0.8)))


def make_read(text, score=0.9, province="TH-10", detection=0.9, province_text="กรุงเทพมหานคร"):
    return PlateRead(
        plate_text=text,
        plate_score=score,
        province_text=province_text,
        province_score=0.8,
        detection_score=detection,
        canonical=thai.try_normalize(text),
        province_code=province,
    )


# --- province resolution -------------------------------------------------------------------


@pytest.mark.parametrize(
    ("text", "code"),
    [
        ("กรุงเทพมหานคร", "TH-10"),  # exact
        ("กรุงเทพมหานศร", "TH-10"),  # one wrong letter
        ("เชียงใหม", "TH-50"),  # tone mark dropped
        ("นนทบรี", "TH-12"),  # vowel dropped
        ("จังหวัดภูเก็ต", "TH-83"),
        ("TH-50", "TH-50"),
    ],
)
def test_closest_province_accepts_small_ocr_errors(text: str, code: str) -> None:
    assert thai.closest_province(text) == code


@pytest.mark.parametrize("text", [None, "", "xx", "ABCDEFG", "กขคงจฉชซ"])
def test_closest_province_refuses_to_guess(text) -> None:
    assert thai.closest_province(text) is None


# --- OCR decoding and crop -----------------------------------------------------------------


def test_load_dictionary_adds_blank_and_space(tmp_path) -> None:
    path = tmp_path / "dict.txt"
    path.write_text("ก\nข\n1\n", encoding="utf-8")
    assert load_dictionary(str(path)) == ["", "ก", "ข", "1", " "]


def test_ctc_decode_merges_repeats_and_drops_blanks() -> None:
    classes = ["", "ก", "ข", "1"]
    steps = [1, 1, 0, 1, 2, 2, 0, 3]
    probs = np.full((1, len(steps), 4), 0.0, dtype=np.float32)
    for t, c in enumerate(steps):
        probs[0, t, c] = 0.8 if c else 0.9
    line = ctc_decode(probs, classes)
    assert line.text == "กกข1"
    assert line.score == pytest.approx(0.8)


def test_ctc_decode_of_nothing_is_empty_with_zero_score() -> None:
    probs = np.zeros((1, 5, 3), dtype=np.float32)
    probs[0, :, 0] = 1.0
    assert ctc_decode(probs, ["", "a", "b"]) == TextLine("", 0.0)


def test_order_corners_handles_any_detector_order() -> None:
    tl, tr, br, bl = (10, 10), (110, 12), (108, 60), (12, 58)
    ordered = order_corners([br, tl, bl, tr])
    assert ordered.tolist() == [list(tl), list(tr), list(br), list(bl)]


def test_rectify_returns_flat_plate() -> None:
    image = np.zeros((200, 300, 3), dtype=np.uint8)
    image[50:100, 100:200] = 255
    plate = rectify(image, [(100, 50), (199, 50), (199, 99), (100, 99)])
    assert plate.shape == (PLATE_HEIGHT, PLATE_WIDTH, 3)
    assert plate.mean() > 250


def test_split_rows_cuts_at_the_gap_between_the_two_rows() -> None:
    plate = np.full((PLATE_HEIGHT, PLATE_WIDTH, 3), 255, dtype=np.uint8)
    plate[10:80] = 0  # registration row
    plate[100:140] = 0  # province row
    top, bottom = split_rows(plate)
    assert 80 <= top.shape[0] <= 100
    assert top.shape[0] + bottom.shape[0] == PLATE_HEIGHT


def test_detection_from_corners_clamps_to_the_frame() -> None:
    det = detection_from_corners([(-5, 10), (50, 10), (50, 30), (-5, 30)], 1.4, 40, 40)
    assert det is not None
    assert (det.bbox.x1, det.bbox.x2) == (0.0, 40.0)
    assert det.confidence == 1.0
    assert detection_from_corners([(50, 50), (60, 50), (60, 60), (50, 60)], 0.9, 40, 40) is None


# --- missing models: fail closed -----------------------------------------------------------


def test_missing_models_mean_not_ready_and_no_reads(tmp_path) -> None:
    detector = LpdYuNetPlateDetector(str(tmp_path / "missing.onnx"))
    recognizer = PaddleTextLineRecognizer(str(tmp_path / "missing.onnx"), None)
    assert detector.is_ready is False
    assert detector.detect(FRAME) == []
    assert recognizer.is_ready is False
    assert recognizer.read(FRAME[:48, :160]) == TextLine("", 0.0)
    assert PlateReader(detector, recognizer).is_ready is False
    assert UnavailablePlateDetector().detect(FRAME) == []


def test_mock_mode_never_invents_plates() -> None:
    reader = get_plate_reader("mock")
    assert reader.detector.detect(FRAME) == []


# --- reading one plate ---------------------------------------------------------------------


def test_reader_cleans_text_and_resolves_province() -> None:
    reader = reader_for(MockPlateDetector(), top="1กข-1234.", bottom="จ.เชียงใหม")
    read = reader.read(FRAME, plate_at(100, 100))
    assert read.plate_text == "1กข1234"
    assert read.canonical == "1กข1234"
    assert read.province_code == "TH-50"
    assert read.province_text == "จ.เชียงใหม"
    assert read.detection_score == pytest.approx(0.9)


def test_reader_drops_vowels_ocr_adds_to_the_registration() -> None:
    reader = reader_for(MockPlateDetector(), top="กิข 12๓๔")
    read = reader.read(FRAME, plate_at(100, 100))
    assert read.canonical == "กข1234"


# --- vote ----------------------------------------------------------------------------------


def test_vote_picks_the_plate_with_most_score_and_its_best_frame_time() -> None:
    reads = [
        (make_read("1กข1234", 0.9), T0),
        (make_read("1กข1284", 0.5), T0 + timedelta(milliseconds=200)),
        (make_read("1กข1234", 0.95), T0 + timedelta(milliseconds=400)),
    ]
    voted = vote("t-1", reads)
    assert voted is not None
    assert voted.plate_text == "1กข1234"
    assert voted.frames_voted == 2
    assert voted.ocr_score == pytest.approx(0.925)
    assert voted.occurred_at == T0 + timedelta(milliseconds=400)
    assert voted.province_text == "TH-10"
    assert voted.reasons == []


def test_vote_flags_disagreement() -> None:
    reads = [(make_read("1กข1234", 0.6), T0), (make_read("1กข1284", 0.5), T0)]
    voted = vote("t-1", reads)
    assert voted is not None and REASON_VOTE_DISAGREEMENT in voted.reasons


def test_vote_sends_best_raw_text_when_no_frame_gave_a_valid_plate() -> None:
    reads = [(make_read("กขคง12345", 0.4), T0), (make_read("ขค", 0.7), T0)]
    voted = vote("t-1", reads)
    assert voted is not None
    assert voted.plate_text == "ขค"
    assert voted.reasons[0] == REASON_TEXT_INVALID


def test_vote_keeps_raw_province_when_unresolved() -> None:
    reads = [(make_read("1กข1234", province=None, province_text="ขขขข"), T0)]
    voted = vote("t-1", reads)
    assert voted is not None
    assert voted.province_text == "ขขขข"
    assert voted.reasons == [REASON_PROVINCE_UNRESOLVED]


def test_vote_of_empty_reads_is_nothing() -> None:
    assert vote("t-1", []) is None
    assert vote("t-1", [(make_read(""), T0)]) is None


# --- per-camera session --------------------------------------------------------------------


def ids():
    counter = itertools.count(1)
    return lambda: f"track-{next(counter)}"


def test_session_reports_once_after_max_reads_and_not_again_while_parked() -> None:
    session = CameraPlateSession(
        reader_for(MockPlateDetector([plate_at(100, 100)])), max_reads=3, id_factory=ids()
    )
    out = [session.process(FRAME, T0 + timedelta(seconds=i)) for i in range(10)]
    assert [len(o) for o in out] == [0, 0, 1, 0, 0, 0, 0, 0, 0, 0]
    assert out[2][0].frames_voted == 3
    assert out[2][0].track_id == "track-1"


def test_session_reports_when_the_plate_leaves_the_frame() -> None:
    detector = MockPlateDetector([plate_at(100, 100)])
    session = CameraPlateSession(
        reader_for(detector), max_reads=8, max_missed_frames=1, id_factory=ids()
    )
    assert session.process(FRAME, T0) == []
    assert session.process(FRAME, T0) == []
    detector.detections = []
    assert session.process(FRAME, T0) == []  # missed once: still tracked
    done = session.process(FRAME, T0)
    assert len(done) == 1 and done[0].frames_voted == 2


def test_session_reports_a_car_that_returns_as_a_new_pass() -> None:
    detector = MockPlateDetector([plate_at(100, 100)])
    session = CameraPlateSession(
        reader_for(detector), max_reads=1, max_missed_frames=0, id_factory=ids()
    )
    assert len(session.process(FRAME, T0)) == 1
    detector.detections = []
    session.process(FRAME, T0)
    detector.detections = [plate_at(100, 100)]
    second = session.process(FRAME, T0)
    assert [p.track_id for p in second] == ["track-2"]


def test_session_ignores_plates_outside_the_roi() -> None:
    roi = RegionOfInterest(
        points=[RoiPoint(x=0, y=0), RoiPoint(x=0.3, y=0), RoiPoint(x=0.3, y=0.3)]
    )
    session = CameraPlateSession(
        reader_for(MockPlateDetector([plate_at(400, 300)])), max_reads=1, id_factory=ids()
    )
    assert session.process(FRAME, T0, roi) == []


def test_session_reset_drops_unreported_reads() -> None:
    session = CameraPlateSession(
        reader_for(MockPlateDetector([plate_at(100, 100)])), max_reads=5, id_factory=ids()
    )
    session.process(FRAME, T0)
    session.reset()
    assert session.flush() == []


# --- camera runner wiring ------------------------------------------------------------------


class RecordingPlateDelivery:
    def __init__(self) -> None:
        self.payloads: list[PlateEventPayload] = []

    def deliver(self, payload: PlateEventPayload) -> DeliveryOutcome:
        self.payloads.append(payload)
        return DeliveryOutcome(delivered=True)


def runner_with(config: dict, reader: PlateReader, delivery) -> CameraPipelineRunner:
    runner = CameraPipelineRunner.__new__(CameraPipelineRunner)
    runner.config = config
    runner.camera_uuid = "6f1c1a1e-0000-4000-8000-000000000001"
    runner.camera_code = "GATE-PLATE"
    runner.ai_node_uuid = "6f1c1a1e-0000-4000-8000-000000000002"
    runner.roi = None
    runner._plate_delivery = delivery
    runner.plate_session = CameraPlateSession(reader, max_reads=2, id_factory=ids())
    runner._plate_active = False
    return runner


def sampled(at: datetime) -> SampledFrame:
    frame = Frame(data=FRAME, width=640, height=480, sequence_number=1, source_name="t")
    frame = Frame(**{**frame.__dict__, "captured_at": at})
    return SampledFrame(frame=frame, camera_uuid="c", sampled_sequence=1)


def test_runner_delivers_one_plate_payload_per_pass() -> None:
    delivery = RecordingPlateDelivery()
    runner = runner_with(
        {"plate_recognition_enabled": True},
        reader_for(MockPlateDetector([plate_at(100, 100)])),
        delivery,
    )
    runner._process_plates(sampled(T0))
    runner._process_plates(sampled(T0 + timedelta(milliseconds=200)))
    assert len(delivery.payloads) == 1
    body = delivery.payloads[0].to_json()
    assert body["plate_text"] == "1กข1234"
    assert body["province_text"] == "TH-10"
    assert body["frames_voted"] == 2
    assert body["model_code"] == PLATE_MODEL_CODE
    assert body["camera_uuid"] == runner.camera_uuid
    assert body["occurred_at"].startswith("2026-11-20T08:00:00")
    # The node reports what it read, never a type, vehicle, person or decision.
    assert not {"event_type", "vehicle_uuid", "person_uuid", "decision"} & body.keys()


@pytest.mark.parametrize("flag", [False, None, "true"])
def test_runner_reads_nothing_unless_the_backend_enables_plates(flag) -> None:
    delivery = RecordingPlateDelivery()
    detector = MockPlateDetector([plate_at(100, 100)])
    runner = runner_with({"plate_recognition_enabled": flag}, reader_for(detector), delivery)
    for _ in range(4):
        runner._process_plates(sampled(T0))
    assert delivery.payloads == []
    assert runner.plate_session.tracker.active_track_count == 0


def test_runner_reads_nothing_without_models() -> None:
    delivery = RecordingPlateDelivery()
    reader = PlateReader(UnavailablePlateDetector(), MockTextLineRecognizer())
    runner = runner_with({"plate_recognition_enabled": True}, reader, delivery)
    assert runner.reads_plates is False
    runner._process_plates(sampled(T0))
    assert delivery.payloads == []


def test_switching_plates_off_drops_reads_in_progress() -> None:
    delivery = RecordingPlateDelivery()
    runner = runner_with(
        {"plate_recognition_enabled": True},
        reader_for(MockPlateDetector([plate_at(100, 100)])),
        delivery,
    )
    runner._process_plates(sampled(T0))  # one read of two
    runner.config = {"plate_recognition_enabled": False}
    runner._process_plates(sampled(T0))
    runner.config = {"plate_recognition_enabled": True}
    runner._process_plates(sampled(T0))
    assert delivery.payloads == []  # the read before the switch-off was dropped


def test_face_detection_flag_defaults_on_and_respects_explicit_off() -> None:
    runner = CameraPipelineRunner.__new__(CameraPipelineRunner)
    runner.config = {}
    assert runner.face_detection_enabled is True
    runner.config = {"face_detection_enabled": False}
    assert runner.face_detection_enabled is False


# --- orchestrator --------------------------------------------------------------------------


class FakeNodeClient:
    def __init__(self, cameras):
        self.cameras = cameras
        self.capabilities = None

    def check_in(self, **kwargs):
        self.capabilities = kwargs.get("capabilities")
        return {}

    def get_config(self, ai_node_uuid):
        return {"cameras": self.cameras}


class FailingThenOkPlateClient:
    def __init__(self):
        self.fail = True
        self.sent = []

    def deliver(self, payload):
        from app.delivery.exceptions import EventDeliveryError

        if self.fail:
            raise EventDeliveryError("offline")
        self.sent.append(payload)


def camera(uuid, face, plate):
    return {
        "camera_uuid": uuid,
        "camera_code": uuid,
        "rtsp_url": "mock://x",
        "status": "ACTIVE",
        "face_detection_enabled": face,
        "plate_recognition_enabled": plate,
    }


def make_orchestrator(cameras, reader=None, plate_client=None):
    return NodeOrchestrator(
        base_url="http://fake",
        node_uuid="node-1",
        node_token="t",
        ai_mode="mock",
        event_buffer_path=":memory:",
        node_client=FakeNodeClient(cameras),
        plate_reader=reader,
        plate_delivery_client=plate_client or FailingThenOkPlateClient(),
        buffer_initial_backoff_seconds=0.0,
    )


def test_plate_only_gate_camera_runs_and_camera_with_neither_does_not() -> None:
    orch = make_orchestrator(
        [
            camera("plate-only", False, True),
            camera("off", False, False),
            camera("face", True, False),
        ]
    )
    try:
        assert orch.sync_config() is True
        assert sorted(orch._cameras) == ["face", "plate-only"]
        assert orch._cameras["plate-only"].face_detection_enabled is False
    finally:
        for runner in orch._cameras.values():
            runner.stop(timeout=1.0)


def test_capability_is_announced_only_with_loaded_models() -> None:
    orch = make_orchestrator([])
    orch._register_node()
    assert "PLATE_RECOGNITION" in orch.node_client.capabilities  # mock reader is "ready"

    orch = make_orchestrator(
        [], reader=PlateReader(UnavailablePlateDetector(), MockTextLineRecognizer())
    )
    orch._register_node()
    assert "PLATE_RECOGNITION" not in orch.node_client.capabilities
    assert orch.get_status_summary()["plate_reader_ready"] is False


def test_offline_plate_reads_are_buffered_and_retried() -> None:
    client = FailingThenOkPlateClient()
    orch = make_orchestrator([], plate_client=client)
    from app.delivery.plate import new_plate_payload

    payload = new_plate_payload(
        camera_uuid="c",
        ai_node_uuid="n",
        track_id="t",
        occurred_at=T0,
        plate_text="1กข1234",
        province_text="TH-10",
        ocr_score=0.9,
        model_code=PLATE_MODEL_CODE,
        model_version="v",
    )
    orch.plate_delivery_service.deliver(payload)
    assert orch.get_status_summary()["plate_buffer_pending_count"] == 1

    client.fail = False
    orch.plate_delivery_service.retry_pending()
    assert [p.event_uuid for p in client.sent] == [payload.event_uuid]
    assert orch.plate_buffer.size == 0


# --- evaluation CLI ------------------------------------------------------------------------


def test_evaluation_scores_labelled_photos(tmp_path) -> None:
    for name in ("a.jpg", "b.jpg"):
        cv2.imwrite(str(tmp_path / name), np.zeros((120, 160, 3), dtype=np.uint8))
    labels = tmp_path / "labels.csv"
    with labels.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(["file", "plate", "province", "condition"])
        writer.writerow(["a.jpg", "1กข 1234", "กรุงเทพมหานคร", "day"])
        writer.writerow(["b.jpg", "9กต 789", "เชียงใหม่", "night"])
        writer.writerow(["missing.jpg", "1กข 1234", "กรุงเทพมหานคร", "day"])

    det = detection_from_corners([(10, 10), (100, 10), (100, 50), (10, 50)], 0.9, 160, 120)
    reader = reader_for(MockPlateDetector([det]))
    summary, results = run_evaluation(reader, tmp_path, labels)

    assert summary["overall"]["photos"] == 3
    assert summary["overall"]["plate_exact_match_rate"] == pytest.approx(1 / 3, abs=1e-3)
    assert summary["by_condition"]["day"]["plate_exact_match_rate"] == 0.5
    assert summary["by_condition"]["night"]["province_match_rate"] == 0.0
    assert results[2].error == "cannot read image"
