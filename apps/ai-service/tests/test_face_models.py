"""ACR-AI-01 production face models: YuNet, SFace (zero-padded to 512) and MiniFASNet PAD.

Tests that need the model files skip when apps/ai-service/models/ has not been populated
(scripts/fetch-face-models.sh). Set FACE_SAMPLE_DIR to the upstream Silent-Face sample
images (images/sample) to also run the real/fake face checks; those images are not
committed.
"""

import os

import cv2
import numpy as np
import pytest

from app.core.config import Settings, settings
from app.detection.schemas import BoundingBox, FaceDetection
from app.detection.yunet import YuNetFaceDetector, to_bgr
from app.embeddings.exceptions import InvalidEmbeddingVectorError
from app.embeddings.sface import STORAGE_DIMENSION, SFaceEmbedder, pad_embedding
from app.liveness import factory as liveness_factory
from app.liveness.base import FaceLivenessDetector, LivenessResult
from app.liveness.minifasnet import MiniFASNetLivenessDetector, crop_box
from app.liveness.unavailable import LivenessModelUnavailableError
from app.orchestration.orchestrator import detection_for_track
from app.streams.rtsp import RTSPVideoSource
from app.tracking.schemas import Track
from tests.test_orchestration import make_detection, make_sampled_frame, make_track, make_worker
from tests.test_rtsp import FakeCapture, factory_from_queue

MODELS = os.path.join(os.path.dirname(__file__), "..", "models")
YUNET = os.path.join(MODELS, "face_detection_yunet_2023mar.onnx")
SFACE = os.path.join(MODELS, "face_recognition_sface_2021dec.onnx")
HAS_MODELS = all(
    os.path.isfile(os.path.join(MODELS, f))
    for f in (
        "face_detection_yunet_2023mar.onnx",
        "face_recognition_sface_2021dec.onnx",
        "minifasnet_v2_2.7_80x80.onnx",
        "minifasnet_v1se_4.0_80x80.onnx",
    )
)
needs_models = pytest.mark.skipif(not HAS_MODELS, reason="run scripts/fetch-face-models.sh")
SAMPLES = os.environ.get("FACE_SAMPLE_DIR", "")
needs_samples = pytest.mark.skipif(
    not (HAS_MODELS and os.path.isfile(os.path.join(SAMPLES, "image_T1.jpg"))),
    reason="set FACE_SAMPLE_DIR to Silent-Face-Anti-Spoofing images/sample",
)


def _unit(rng: np.random.Generator, n: int) -> np.ndarray:
    v = rng.normal(size=n)
    return v / np.linalg.norm(v)


class TestZeroPadding:
    def test_padding_keeps_norm_and_every_cosine_similarity(self) -> None:
        rng = np.random.default_rng(1)
        for _ in range(20):
            a, b = _unit(rng, 128), _unit(rng, 128)
            pa, pb = pad_embedding(a), pad_embedding(b)
            assert pa.shape == (STORAGE_DIMENSION,)
            assert not pa[128:].any()
            assert np.linalg.norm(pa) == pytest.approx(1.0)
            assert float(pa @ pb) == pytest.approx(float(a @ b), abs=1e-12)

    def test_vector_longer_than_storage_is_rejected(self) -> None:
        with pytest.raises(InvalidEmbeddingVectorError):
            pad_embedding(np.ones(513))


class TestCropBox:
    def test_box_is_scaled_around_the_face_centre(self) -> None:
        left, top, right, bottom = crop_box(1000, 1000, (450, 450, 100, 100), 2.7)
        assert (right - left, bottom - top) == (270, 270)
        assert ((left + right) / 2, (top + bottom) / 2) == pytest.approx((500, 500), abs=1)

    def test_box_is_shifted_inside_the_image_at_the_edges(self) -> None:
        for bbox in ((0, 0, 100, 100), (900, 900, 99, 99), (0, 900, 100, 99)):
            left, top, right, bottom = crop_box(1000, 1000, bbox, 4.0)
            assert 0 <= left < right <= 999 and 0 <= top < bottom <= 999

    def test_scale_shrinks_when_the_face_fills_the_image(self) -> None:
        left, top, right, bottom = crop_box(200, 200, (10, 10, 180, 180), 4.0)
        assert right - left <= 199 and bottom - top <= 199


class TestFailClosed:
    def test_missing_models_mean_not_ready_and_no_score(self, tmp_path) -> None:
        pad = MiniFASNetLivenessDetector(model_dir=str(tmp_path))
        assert not pad.is_ready
        with pytest.raises(LivenessModelUnavailableError):
            pad.evaluate(np.zeros((100, 100, 3), np.uint8), (10, 10, 50, 50))
        assert not YuNetFaceDetector(str(tmp_path / "x.onnx")).is_ready
        assert not SFaceEmbedder(str(tmp_path / "x.onnx")).is_ready

    def test_onnx_mode_never_reports_the_mock_model_identity(self, monkeypatch) -> None:
        monkeypatch.setenv("AI_MODE", "onnx")
        onnx = Settings()
        assert (onnx.model_code, onnx.model_version) == ("yunet-sface", "2021dec")
        monkeypatch.setenv("MODEL_CODE", "custom")
        assert Settings().model_code == "custom"


class _RecordingLiveness(FaceLivenessDetector):
    def __init__(self) -> None:
        self.boxes: list[tuple[float, float, float, float] | None] = []

    def evaluate(self, image, bounding_box=None) -> LivenessResult:
        self.boxes.append(bounding_box)
        return LivenessResult(score=0.9, is_live=True, method="TEST", status="REAL")


class TestPipelineWiring:
    def test_worker_gives_liveness_the_detected_face_box(self) -> None:
        liveness = _RecordingLiveness()
        worker, _ = make_worker(liveness_detector=liveness)
        detection = make_detection()
        worker.process(make_sampled_frame(), detection, make_track())
        box = detection.bbox
        assert liveness.boxes == [(box.x1, box.y1, box.x2, box.y2)]

    def test_each_track_gets_its_own_face_not_the_first_one(self) -> None:
        def det(x: float) -> FaceDetection:
            return FaceDetection(
                bbox=BoundingBox(x1=x, y1=10, x2=x + 50, y2=60),
                confidence=0.9,
                detector_name="t",
                detector_version="1",
            )

        first, second = det(10), det(300)
        track = Track(
            track_id="t2", bbox=second.bbox, confidence=0.9, age=1, hits=2, missed_frames=0
        )
        assert detection_for_track(track, [first, second]) is second

    def test_track_missed_in_this_frame_is_not_given_another_face(self) -> None:
        face = FaceDetection(
            bbox=BoundingBox(x1=10, y1=10, x2=60, y2=60),
            confidence=0.9,
            detector_name="t",
            detector_version="1",
        )
        stale = Track(
            track_id="old", bbox=face.bbox, confidence=0.9, age=3, hits=2, missed_frames=1
        )
        assert detection_for_track(stale, [face]) is None

    def test_rtsp_frames_are_converted_from_bgr_to_rgb(self) -> None:
        class BlueCapture(FakeCapture):
            def read(self):
                ok, frame = super().read()
                frame[:, :, 0] = 255  # OpenCV order: channel 0 is blue
                return ok, frame

        source = RTSPVideoSource(
            "rtsp://camera.test/stream",
            capture_factory=factory_from_queue([BlueCapture()]),
            sleep_fn=lambda _s: None,
        )
        source.open()
        frame = source.read()
        assert frame.data[0, 0].tolist() == [0, 0, 255]


@needs_models
class TestRealModels:
    def test_yunet_finds_no_face_in_a_blank_image(self) -> None:
        assert YuNetFaceDetector(YUNET).detect(np.full((240, 320, 3), 127, np.uint8)) == []

    def test_sface_vector_is_512_unit_norm_with_zero_tail(self) -> None:
        face = np.random.default_rng(3).integers(0, 256, (112, 112, 3), dtype=np.uint8)
        result = SFaceEmbedder(SFACE).generate(face)
        v = np.array(result.vector)
        assert result.dimension == 512 and (result.model_code, result.model_version) == (
            "yunet-sface",
            "2021dec",
        )
        assert np.linalg.norm(v) == pytest.approx(1.0) and not v[128:].any()

    def test_minifasnet_scores_are_probabilities_and_deterministic(self) -> None:
        pad = MiniFASNetLivenessDetector(MODELS)
        image = np.random.default_rng(4).integers(0, 256, (480, 640, 3), dtype=np.uint8)
        first = pad.evaluate(image, (250, 150, 390, 330))
        again = pad.evaluate(image, (250, 150, 390, 330))
        assert 0.0 <= first.score <= 1.0 and first.score == again.score
        assert pad.evaluate(image, None).is_live is False

    def test_onnx_mode_uses_minifasnet(self, monkeypatch) -> None:
        monkeypatch.setattr(settings, "ai_mode", "onnx")
        monkeypatch.setattr(settings, "liveness_model_dir", MODELS)
        monkeypatch.setattr(liveness_factory, "_detector_instance", None)
        try:
            assert isinstance(
                liveness_factory.get_face_liveness_detector(), MiniFASNetLivenessDetector
            )
        finally:
            monkeypatch.setattr(liveness_factory, "_detector_instance", None)


@needs_samples
class TestSampleImages:
    def _face(self, name: str) -> tuple[np.ndarray, FaceDetection]:
        rgb = cv2.cvtColor(cv2.imread(os.path.join(SAMPLES, name)), cv2.COLOR_BGR2RGB)
        faces = YuNetFaceDetector(YUNET).detect(rgb)
        assert len(faces) == 1
        return rgb, faces[0]

    def test_real_face_is_live_and_photos_of_faces_are_not(self) -> None:
        pad = MiniFASNetLivenessDetector(MODELS)
        for name, live in (
            ("image_T1.jpg", True),
            ("image_F1.jpg", False),
            ("image_F2.jpg", False),
        ):
            rgb, face = self._face(name)
            b = face.bbox
            assert pad.evaluate(rgb, (b.x1, b.y1, b.x2, b.y2)).is_live is live, name

    def test_embedding_matches_opencv_reference_pipeline(self) -> None:
        from app.alignment.standard import StandardFaceAligner

        rgb, face = self._face("image_T1.jpg")
        ours = np.array(
            SFaceEmbedder(SFACE).generate(StandardFaceAligner().align(rgb, face)).vector
        )
        bgr = to_bgr(rgb)
        det = cv2.FaceDetectorYN.create(YUNET, "", (bgr.shape[1], bgr.shape[0]))
        _, ref_faces = det.detect(bgr)
        rec = cv2.FaceRecognizerSF.create(SFACE, "")
        ref = rec.feature(rec.alignCrop(bgr, ref_faces[0])).reshape(-1)
        assert float(ours[:128] @ (ref / np.linalg.norm(ref))) > 0.99
        assert face.landmarks is not None and face.landmarks[0].x < face.landmarks[1].x
