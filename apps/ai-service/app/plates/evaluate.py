"""Plate model evaluation on labelled photos (VEH-007 part 2 / VEH-008).

Runs the node's own plate reader (the same code the cameras use) on a folder of photos and
compares with the plate and province a person typed for each photo. The owner uses the result
to approve the models and thresholds before plate reading is switched on anywhere.

    python -m app.plates.evaluate --images /data/plates --labels /data/plates/labels.csv \
        [--detection-threshold 0.8] [--output result.json]

labels.csv (UTF-8, header row): file,plate,province[,condition]
    car001.jpg,1กข 1234,กรุงเทพมหานคร,day
condition is free text (day / night / backlight / rain ...) and is reported per group.

Photos and labels are personal data (PDPA): keep them on the campus machine; the output has
per-photo results and must be kept the same way. Nothing is sent anywhere.
"""

from __future__ import annotations

import argparse
import csv
import json
import statistics
import sys
import time
from dataclasses import asdict, dataclass
from pathlib import Path

import cv2

from app.plates import thai
from app.plates.pipeline import PlateReader


@dataclass
class PhotoResult:
    file: str
    condition: str
    expected_plate: str | None
    expected_province: str | None
    found: bool
    read_plate: str | None
    read_province: str | None
    ocr_score: float | None
    detection_score: float | None
    plate_correct: bool
    province_correct: bool
    millis: float
    error: str | None = None


def evaluate_photo(reader: PlateReader, path: Path, label: dict[str, str]) -> PhotoResult:
    expected_plate = thai.try_normalize(label.get("plate"))
    expected_province = thai.resolve_province(label.get("province"))
    base = {
        "file": label["file"],
        "condition": (label.get("condition") or "").strip() or "unspecified",
        "expected_plate": expected_plate,
        "expected_province": expected_province,
    }
    image = cv2.imread(str(path), cv2.IMREAD_COLOR)
    if image is None:
        return PhotoResult(
            **base,
            found=False,
            read_plate=None,
            read_province=None,
            ocr_score=None,
            detection_score=None,
            plate_correct=False,
            province_correct=False,
            millis=0.0,
            error="cannot read image",
        )
    rgb = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
    started = time.perf_counter()
    detections = reader.detector.detect(rgb)
    read = reader.read(rgb, detections[0]) if detections else None
    millis = (time.perf_counter() - started) * 1000.0
    read_plate = (read.canonical or read.plate_text or None) if read else None
    return PhotoResult(
        **base,
        found=bool(detections),
        read_plate=read_plate,
        read_province=read.province_code if read else None,
        ocr_score=round(read.plate_score, 4) if read else None,
        detection_score=round(read.detection_score, 4) if read else None,
        plate_correct=read is not None
        and expected_plate is not None
        and read.canonical == expected_plate,
        province_correct=read is not None
        and expected_province is not None
        and read.province_code == expected_province,
        millis=round(millis, 1),
    )


def _rate(hits: int, total: int) -> float | None:
    return round(hits / total, 4) if total else None


def summarise(results: list[PhotoResult]) -> dict:
    def block(rows: list[PhotoResult]) -> dict:
        timed = [r.millis for r in rows if r.error is None]
        return {
            "photos": len(rows),
            "plate_found_rate": _rate(sum(r.found for r in rows), len(rows)),
            "plate_exact_match_rate": _rate(sum(r.plate_correct for r in rows), len(rows)),
            "province_match_rate": _rate(
                sum(r.province_correct for r in rows),
                sum(r.expected_province is not None for r in rows),
            ),
            "mean_millis": round(statistics.fmean(timed), 1) if timed else None,
            "max_millis": round(max(timed), 1) if timed else None,
        }

    conditions = sorted({r.condition for r in results})
    return {
        "overall": block(results),
        "by_condition": {c: block([r for r in results if r.condition == c]) for c in conditions},
        "unlabelled_or_invalid_labels": sum(r.expected_plate is None for r in results),
    }


def run(reader: PlateReader, images: Path, labels: Path) -> tuple[dict, list[PhotoResult]]:
    with labels.open(encoding="utf-8-sig", newline="") as handle:
        rows = [row for row in csv.DictReader(handle) if (row.get("file") or "").strip()]
    results = [evaluate_photo(reader, images / row["file"].strip(), row) for row in rows]
    return summarise(results), results


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--images", required=True, type=Path)
    parser.add_argument("--labels", required=True, type=Path)
    parser.add_argument("--detection-threshold", type=float, default=None)
    parser.add_argument("--detector-input-width", type=int, default=None)
    parser.add_argument("--output", type=Path, default=None)
    args = parser.parse_args(argv)

    from app.core.config import settings
    from app.plates.factory import get_plate_reader

    if args.detection_threshold is not None:
        settings.plate_detection_threshold = args.detection_threshold
    if args.detector_input_width is not None:
        settings.plate_detector_input_width = args.detector_input_width
    reader = get_plate_reader("onnx")
    if not reader.is_ready:
        print("Plate models are missing: run scripts/fetch-plate-models.sh", file=sys.stderr)
        return 2

    summary, results = run(reader, args.images, args.labels)
    summary["detection_threshold"] = settings.plate_detection_threshold
    summary["detector_input_width"] = settings.plate_detector_input_width
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    if args.output is not None:
        args.output.write_text(
            json.dumps(
                {"summary": summary, "photos": [asdict(r) for r in results]},
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )
    return 0


if __name__ == "__main__":  # pragma: no cover
    sys.exit(main())
