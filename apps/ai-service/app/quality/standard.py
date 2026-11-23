"""Standard deterministic face quality evaluator implementation (FACE-006)."""

import math

import numpy as np

from app.alignment.schemas import AlignedFace
from app.detection.schemas import FaceDetection
from app.quality.base import FaceQualityEvaluator
from app.quality.exceptions import QualityEvaluationRuntimeError
from app.quality.schemas import FaceQualityResult, QualityRejectionReason, QualityThresholds


def compute_grayscale(image: np.ndarray) -> np.ndarray:
    """Convert 2D or 3D image array to single-channel float32 grayscale."""
    if image.ndim == 2:
        return image.astype(np.float32)
    if image.ndim == 3:
        if image.shape[2] == 1:
            return image[:, :, 0].astype(np.float32)
        # Standard luminance weights for RGB/BGR: 0.299*R + 0.587*G + 0.114*B
        return (
            0.299 * image[:, :, 0].astype(np.float32)
            + 0.587 * image[:, :, 1].astype(np.float32)
            + 0.114 * image[:, :, 2].astype(np.float32)
        )
    raise ValueError(f"Unsupported image dimension: {image.ndim}")


def compute_laplacian_variance(gray: np.ndarray) -> float:
    """Compute Laplacian variance metric for blur/sharpness evaluation."""
    try:
        import cv2  # type: ignore

        lap = cv2.Laplacian(gray.astype(np.uint8), cv2.CV_64F)
        return float(lap.var())
    except (ImportError, Exception):
        pass

    # Pure NumPy 3x3 discrete Laplacian filter convolution
    h, w = gray.shape
    if h < 3 or w < 3:
        return 0.0

    # Kernel: [[0, 1, 0], [1, -4, 1], [0, 1, 0]]
    lap = (
        gray[0 : h - 2, 1 : w - 1]
        + gray[2:h, 1 : w - 1]
        + gray[1 : h - 1, 0 : w - 2]
        + gray[1 : h - 1, 2:w]
        - 4.0 * gray[1 : h - 1, 1 : w - 1]
    )
    return float(np.var(lap))


class StandardFaceQualityEvaluator(FaceQualityEvaluator):
    """Standard CPU-only deterministic face quality evaluator."""

    def __init__(
        self,
        thresholds: QualityThresholds | None = None,
        name: str = "standard_quality_evaluator",
        version: str = "1.0.0",
    ):
        self._thresholds = thresholds or QualityThresholds()
        self._name = name
        self._version = version

    @property
    def name(self) -> str:
        return self._name

    @property
    def version(self) -> str:
        return self._version

    @property
    def thresholds(self) -> QualityThresholds:
        return self._thresholds

    def evaluate(
        self,
        aligned_face: AlignedFace | np.ndarray,
        detection: FaceDetection | None = None,
    ) -> FaceQualityResult:
        """Evaluate technical image quality of face sample.

        Args:
            aligned_face: AlignedFace object or numpy image array.
            detection: Optional source FaceDetection with bounding box and landmarks.

        Returns:
            FaceQualityResult.
        """
        valid_img = self.extract_and_validate_image(aligned_face, detection)

        try:
            gray = compute_grayscale(valid_img)
            rejection_reasons: list[QualityRejectionReason] = []

            # 1. Blur / Sharpness
            lap_var = compute_laplacian_variance(gray)
            # Normalized score: 0.0 (completely blurry) to ~1.0 (very sharp)
            blur_score = float(np.clip(lap_var / (lap_var + 100.0), 0.0, 1.0))
            if blur_score < self.thresholds.min_blur_score:
                rejection_reasons.append(QualityRejectionReason.IMAGE_BLUR)

            # 2. Brightness / Exposure
            mean_luminance = float(np.mean(gray)) / 255.0  # 0.0 .. 1.0
            # Normalized exposure score: 1.0 at ideal 0.50, decreases as it approaches 0 or 1
            brightness_score = float(np.clip(1.0 - 2.0 * abs(mean_luminance - 0.50), 0.0, 1.0))
            if mean_luminance < self.thresholds.min_luminance:
                rejection_reasons.append(QualityRejectionReason.IMAGE_TOO_DARK)
            elif mean_luminance > self.thresholds.max_luminance:
                rejection_reasons.append(QualityRejectionReason.IMAGE_TOO_BRIGHT)

            # 3. Face Size
            if detection is not None:
                box_dim = max(detection.bbox.width, detection.bbox.height)
                # Reference dimension for face box in frame: normalized against 100px minimum
                face_size_score = float(np.clip(box_dim / 100.0, 0.0, 1.0))
            else:
                img_dim = min(valid_img.shape[0], valid_img.shape[1])
                face_size_score = float(np.clip(img_dim / 112.0, 0.0, 1.0))

            if face_size_score < self.thresholds.min_face_size_score:
                rejection_reasons.append(QualityRejectionReason.FACE_TOO_SMALL)

            # 4. Pose (Yaw, Pitch, Roll)
            yaw: float | None = None
            pitch: float | None = None
            roll: float | None = None
            pose_score: float = 1.0

            if detection is not None and detection.landmarks and len(detection.landmarks) == 5:
                pts = detection.landmarks
                le, re, n, lm, rm = pts[0], pts[1], pts[2], pts[3], pts[4]

                # Roll from eye angle
                dx = re.x - le.x
                dy = re.y - le.y
                roll = float(math.degrees(math.atan2(dy, dx))) if dx != 0 or dy != 0 else 0.0

                # Yaw from symmetry of eyes relative to nose
                d_left = abs(n.x - le.x)
                d_right = abs(re.x - n.x)
                denom_x = d_left + d_right
                if denom_x > 1e-4:
                    yaw = float(((d_left - d_right) / denom_x) * 90.0)
                else:
                    yaw = 0.0

                # Pitch from vertical symmetry
                eyes_y = (le.y + re.y) / 2.0
                mouth_y = (lm.y + rm.y) / 2.0
                d_up = abs(n.y - eyes_y)
                d_down = abs(mouth_y - n.y)
                denom_y = d_up + d_down
                if denom_y > 1e-4:
                    pitch = float(((d_up - d_down) / denom_y) * 60.0)
                else:
                    pitch = 0.0

                # Compute normalized pose frontal score
                penalty = abs(yaw) / 90.0 * 0.4 + abs(pitch) / 90.0 * 0.4 + abs(roll) / 180.0 * 0.2
                pose_score = float(np.clip(1.0 - penalty, 0.0, 1.0))

                if (
                    abs(yaw) > self.thresholds.max_yaw_degrees
                    or abs(pitch) > self.thresholds.max_pitch_degrees
                    or abs(roll) > self.thresholds.max_roll_degrees
                    or pose_score < self.thresholds.min_pose_score
                ):
                    rejection_reasons.append(QualityRejectionReason.BAD_POSE)

            # 5. Overall Weighted Quality Score
            t = self.thresholds
            quality_score = float(
                np.clip(
                    t.weight_blur * blur_score
                    + t.weight_brightness * brightness_score
                    + t.weight_pose * pose_score
                    + t.weight_face_size * face_size_score,
                    0.0,
                    1.0,
                )
            )

            if quality_score < t.min_quality_score and not rejection_reasons:
                rejection_reasons.append(QualityRejectionReason.LOW_QUALITY)

            accepted = (len(rejection_reasons) == 0) and (quality_score >= t.min_quality_score)

            return FaceQualityResult(
                quality_score=quality_score,
                blur_score=blur_score,
                brightness_score=brightness_score,
                face_size_score=face_size_score,
                pose_score=pose_score,
                yaw=yaw,
                pitch=pitch,
                roll=roll,
                accepted=accepted,
                rejection_reasons=rejection_reasons,
                evaluator_name=self.name,
                evaluator_version=self.version,
                model_type=self.model_type,
            )
        except Exception as exc:
            raise QualityEvaluationRuntimeError(f"Face quality evaluation failed: {exc}") from exc
