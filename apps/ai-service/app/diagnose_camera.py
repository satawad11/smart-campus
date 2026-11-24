"""CCTV face pipeline diagnostic (no backend needed).

Runs the same real pipeline the AI node runs - YuNet detection, alignment, the CCTV
quality gate, MiniFASNet liveness (per LIVENESS_MODE), SFace embedding - on a camera
and compares each face with a local gallery of reference photos. Use it on site to
see, per face, which stage keeps or drops it and how similar it is to whom.

    python -m app.diagnose_camera --source rtsp://user:pass@10.0.0.5:554/stream1 \
        --gallery ./gallery --seconds 30 --out ./diag

    --source   RTSP/HTTP URL, a video file, or a webcam index (0)
    --gallery  folder of photos, one person per file (file name = label) or one
               sub-folder per person; optional
    --out      folder for annotated JPEG snapshots of frames with faces; optional

Similarity is SFace cosine; with the default thresholds >= 0.45 is MATCH and
>= 0.36 is REVIEW (same values as the backend's config/face.php). Raw vectors are
never written to disk or printed.
"""

from __future__ import annotations

import argparse
import os
import sys
import time
from dataclasses import dataclass

import cv2
import numpy as np

from app.alignment.standard import StandardFaceAligner
from app.core.config import settings
from app.detection.yunet import YuNetFaceDetector
from app.embeddings.sface import SFaceEmbedder
from app.liveness.minifasnet import MiniFASNetLivenessDetector
from app.orchestration.orchestrator import cctv_quality_thresholds
from app.quality.standard import StandardFaceQualityEvaluator
from app.streams.rtsp import mask_rtsp_url

IMAGE_EXT = (".jpg", ".jpeg", ".png", ".bmp", ".webp")


@dataclass
class FaceReport:
    box: tuple[int, int, int, int]
    size_px: int
    confidence: float
    quality: float
    quality_ok: bool
    quality_reasons: list[str]
    liveness: str | None
    label: str | None
    similarity: float | None
    classification: str


class Pipeline:
    def __init__(self, match: float, review: float, liveness_mode: str):
        self.detector = YuNetFaceDetector(model_path=settings.detector_model_path)
        self.aligner = StandardFaceAligner()
        self.quality = StandardFaceQualityEvaluator(thresholds=cctv_quality_thresholds())
        self.embedder = SFaceEmbedder(model_path=settings.embedder_model_path)
        self.liveness = MiniFASNetLivenessDetector(
            model_dir=settings.liveness_model_dir,
            threshold_real=settings.liveness_threshold_real,
            threshold_spoof=settings.liveness_threshold_spoof,
        )
        self.match, self.review = match, review
        self.liveness_mode = liveness_mode
        self.gallery: list[tuple[str, np.ndarray]] = []

    def check_ready(self) -> list[str]:
        problems = []
        if not self.detector.is_ready:
            problems.append(f"detector model missing: {settings.detector_model_path}")
        if not getattr(self.embedder, "is_ready", True):
            problems.append(f"embedder model missing: {settings.embedder_model_path}")
        if self.liveness_mode != "off" and not self.liveness.is_ready:
            problems.append(f"liveness models missing in: {settings.liveness_model_dir}")
        return problems

    def embed(self, rgb: np.ndarray, detection) -> np.ndarray:
        vector = np.asarray(
            self.embedder.generate(self.aligner.align(rgb, detection)).vector, np.float32
        )
        return vector / (np.linalg.norm(vector) or 1.0)

    def load_gallery(self, folder: str) -> None:
        for root, _dirs, files in os.walk(folder):
            for name in sorted(files):
                if not name.lower().endswith(IMAGE_EXT):
                    continue
                path = os.path.join(root, name)
                label = (
                    os.path.basename(root)
                    if os.path.normpath(root) != os.path.normpath(folder)
                    else os.path.splitext(name)[0]
                )
                bgr = cv2.imread(path)
                if bgr is None:
                    print(f"  gallery: cannot read {path}")
                    continue
                rgb = cv2.cvtColor(bgr, cv2.COLOR_BGR2RGB)
                faces = self.detector.detect(rgb)
                if not faces:
                    print(f"  gallery: no face in {path}")
                    continue
                self.gallery.append((label, self.embed(rgb, faces[0])))
        print(f"gallery: {len(self.gallery)} reference face(s) from {folder}")

    def analyse(self, rgb: np.ndarray) -> list[FaceReport]:
        reports = []
        for det in self.detector.detect(rgb):
            box = det.bbox
            size = int(max(box.width, box.height))
            aligned = self.aligner.align(rgb, det)
            q = self.quality.evaluate(aligned, det)
            reasons = [getattr(r, "value", str(r)) for r in q.rejection_reasons]
            liveness = None
            if self.liveness_mode != "off" and self.liveness.is_ready:
                if self.liveness_mode == "enforce" or size >= settings.liveness_min_face_px:
                    liveness = self.liveness.evaluate(rgb, (box.x1, box.y1, box.x2, box.y2)).status
            label = similarity = None
            if not q.accepted:
                classification = "REJECTED"
            elif self.liveness_mode == "enforce" and liveness != "REAL":
                classification = "REJECTED"
                reasons.append(f"LIVENESS_{liveness}")
            else:
                classification = "NO_GALLERY"
                if self.gallery:
                    vector = self.embed(rgb, det)
                    scored = sorted(
                        ((float(vector @ ref), name) for name, ref in self.gallery), reverse=True
                    )
                    similarity, label = scored[0]
                    classification = (
                        "MATCH"
                        if similarity >= self.match
                        else "REVIEW"
                        if similarity >= self.review
                        else "UNKNOWN"
                    )
            reports.append(
                FaceReport(
                    box=(int(box.x1), int(box.y1), int(box.x2), int(box.y2)),
                    size_px=size,
                    confidence=det.confidence,
                    quality=q.quality_score,
                    quality_ok=q.accepted,
                    quality_reasons=reasons,
                    liveness=liveness,
                    label=label,
                    similarity=similarity,
                    classification=classification,
                )
            )
        return reports


COLOURS = {
    "MATCH": (0, 200, 0),
    "REVIEW": (0, 200, 255),
    "UNKNOWN": (0, 0, 255),
    "REJECTED": (128, 128, 128),
    "NO_GALLERY": (255, 200, 0),
}


def annotate(bgr: np.ndarray, reports: list[FaceReport]) -> np.ndarray:
    out = bgr.copy()
    for r in reports:
        colour = COLOURS.get(r.classification, (255, 255, 255))
        x1, y1, x2, y2 = r.box
        cv2.rectangle(out, (x1, y1), (x2, y2), colour, 2)
        text = r.classification
        if r.label is not None:
            text += f" {r.label} {r.similarity:.2f}"
        cv2.putText(out, text, (x1, max(12, y1 - 6)), cv2.FONT_HERSHEY_SIMPLEX, 0.5, colour, 1)
    return out


def open_source(source: str) -> cv2.VideoCapture:
    if source.isdigit():
        return cv2.VideoCapture(int(source))
    if source.lower().startswith("rtsp"):
        os.environ.setdefault("OPENCV_FFMPEG_CAPTURE_OPTIONS", "rtsp_transport;tcp")
    return cv2.VideoCapture(source, cv2.CAP_FFMPEG)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--source", required=True)
    parser.add_argument("--gallery")
    parser.add_argument("--out")
    parser.add_argument("--seconds", type=float, default=30.0)
    parser.add_argument("--fps", type=float, default=settings.target_fps)
    parser.add_argument("--match", type=float, default=0.45)
    parser.add_argument("--review", type=float, default=0.36)
    parser.add_argument("--liveness-mode", default=settings.liveness_mode)
    args = parser.parse_args(argv)

    pipeline = Pipeline(args.match, args.review, args.liveness_mode.strip().lower())
    problems = pipeline.check_ready()
    if problems:
        for p in problems:
            print("NOT READY:", p)
        print("Run scripts/fetch-face-models.sh, or set DETECTOR_MODEL_PATH / ...")
        return 2
    if args.gallery:
        pipeline.load_gallery(args.gallery)
    if args.out:
        os.makedirs(args.out, exist_ok=True)

    capture = open_source(args.source)
    if not capture.isOpened():
        print(f"cannot open source {mask_rtsp_url(args.source)}")
        return 3
    width = int(capture.get(cv2.CAP_PROP_FRAME_WIDTH))
    height = int(capture.get(cv2.CAP_PROP_FRAME_HEIGHT))
    print(f"source {mask_rtsp_url(args.source)} opened: {width}x{height}")

    totals: dict[str, int] = {}
    sizes: list[int] = []
    frames = faces_frames = 0
    interval = 1.0 / max(args.fps, 0.1)
    started = last = time.monotonic()
    is_file = os.path.isfile(args.source)
    position_ms = 0.0
    while time.monotonic() - started < args.seconds:
        ok, bgr = capture.read()
        if not ok or bgr is None:
            if is_file:
                break
            print("read failed; retrying")
            time.sleep(0.5)
            continue
        if is_file:
            # Files are read as fast as possible; sample by media time instead.
            now_ms = capture.get(cv2.CAP_PROP_POS_MSEC)
            if now_ms - position_ms < interval * 1000 and frames:
                continue
            position_ms = now_ms
        elif time.monotonic() - last < interval:
            continue
        last = time.monotonic()
        frames += 1
        rgb = cv2.cvtColor(bgr, cv2.COLOR_BGR2RGB)
        reports = pipeline.analyse(rgb)
        if not reports:
            continue
        faces_frames += 1
        for r in reports:
            totals[r.classification] = totals.get(r.classification, 0) + 1
            sizes.append(r.size_px)
            who = f" -> {r.label} sim={r.similarity:.3f}" if r.label is not None else ""
            why = f" {r.quality_reasons}" if r.quality_reasons else ""
            print(
                f"frame {frames:4d} face {r.size_px:3d}px conf={r.confidence:.2f} "
                f"Q={r.quality:.2f} live={r.liveness} {r.classification}{who}{why}"
            )
        if args.out:
            cv2.imwrite(os.path.join(args.out, f"frame_{frames:05d}.jpg"), annotate(bgr, reports))
    capture.release()

    print("\nSUMMARY")
    print(f"  frames analysed: {frames}, frames with faces: {faces_frames}")
    if sizes:
        print(f"  face size px: min {min(sizes)} median {int(np.median(sizes))} max {max(sizes)}")
    for key in ("MATCH", "REVIEW", "UNKNOWN", "REJECTED", "NO_GALLERY"):
        if key in totals:
            print(f"  {key}: {totals[key]}")
    if frames and not faces_frames:
        print("  No faces detected: check camera angle/zoom (faces need >= ~24 px).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
