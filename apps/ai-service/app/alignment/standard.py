"""Standard 5-point similarity transformation face aligner (FACE-005)."""

import numpy as np

from app.alignment.base import FaceAligner
from app.alignment.exceptions import AlignmentRuntimeError, InvalidLandmarksError
from app.alignment.schemas import AlignedFace
from app.detection.schemas import FaceDetection

# Standard ArcFace canonical 5-point template for 112x112 normalized face crops
# Coordinates: [left_eye, right_eye, nose, left_mouth, right_mouth]
STANDARD_5PT_TEMPLATE_112 = np.array(
    [
        [38.2946, 51.6963],  # Left eye
        [73.5318, 51.6963],  # Right eye
        [56.0252, 71.7366],  # Nose
        [41.5493, 92.3655],  # Left mouth corner
        [70.7299, 92.3655],  # Right mouth corner
    ],
    dtype=np.float32,
)


def estimate_similarity_transform(src_pts: np.ndarray, dst_pts: np.ndarray) -> np.ndarray:
    """Estimate 2x3 2D similarity transform mapping src_pts to dst_pts (Umeyama algorithm).

    Args:
        src_pts: (N, 2) source landmark coordinates.
        dst_pts: (N, 2) destination reference template coordinates.

    Returns:
        (2, 3) affine similarity matrix M = [s*R | t].

    Raises:
        InvalidLandmarksError: If source landmark points are collinear, degenerate, or singular.
    """
    n, dim = src_pts.shape
    if n < 2 or dim != 2:
        raise InvalidLandmarksError(f"Invalid landmark shape for transform: {src_pts.shape}")

    src_mean = np.mean(src_pts, axis=0)
    dst_mean = np.mean(dst_pts, axis=0)

    src_centered = src_pts - src_mean
    dst_centered = dst_pts - dst_mean

    src_var = np.mean(np.sum(src_centered**2, axis=1))
    if src_var < 1e-6:
        raise InvalidLandmarksError("Source landmarks have zero spatial variance (collapsed)")

    # Covariance matrix (2, 2)
    cov = np.dot(dst_centered.T, src_centered) / n

    # SVD
    try:
        u, s, vt = np.linalg.svd(cov)
    except Exception as exc:
        raise InvalidLandmarksError(f"Singular landmark geometry in SVD: {exc}") from exc

    # Reflection check
    d = np.ones(dim, dtype=np.float32)
    if np.linalg.det(u) * np.linalg.det(vt) < 0:
        d[dim - 1] = -1.0

    # Rotation matrix
    r = np.dot(u, np.dot(np.diag(d), vt))

    # Scale factor
    scale = float(np.sum(s * d) / src_var)
    if scale <= 1e-6:
        raise InvalidLandmarksError("Computed scale factor is non-positive or near-zero")

    # Translation vector
    t = dst_mean - scale * np.dot(r, src_mean)

    # 2x3 affine matrix
    m = np.zeros((2, 3), dtype=np.float32)
    m[:, :2] = scale * r
    m[:, 2] = t
    return m


def warp_affine_bilinear(
    image: np.ndarray,
    matrix_2x3: np.ndarray,
    target_width: int,
    target_height: int,
) -> np.ndarray:
    """Warp image using a 2x3 affine matrix with bilinear interpolation.

    Utilizes cv2.warpAffine if available; otherwise falls back to pure numpy interpolation.
    Safely handles boundary coordinates with zero-padding without crashing.
    """
    try:
        import cv2  # type: ignore

        return cv2.warpAffine(
            image,
            matrix_2x3,
            (target_width, target_height),
            flags=cv2.INTER_LINEAR,
            borderMode=cv2.BORDER_CONSTANT,
            borderValue=(0, 0, 0),
        )
    except (ImportError, Exception):
        pass

    # Pure NumPy CPU-only vectorized bilinear warp implementation
    m_inv_3x3 = np.eye(3, dtype=np.float32)
    m_inv_3x3[:2, :] = matrix_2x3
    try:
        m_inv = np.linalg.inv(m_inv_3x3)[:2, :]
    except Exception as exc:
        raise AlignmentRuntimeError(f"Transformation matrix is non-invertible: {exc}") from exc

    # Destination coordinate meshgrid
    x_coords, y_coords = np.meshgrid(
        np.arange(target_width, dtype=np.float32),
        np.arange(target_height, dtype=np.float32),
    )
    ones = np.ones_like(x_coords)
    coords_dst = np.stack([x_coords, y_coords, ones], axis=-1)  # (H_out, W_out, 3)

    # Map to source coordinates
    src_x = coords_dst @ m_inv[0]
    src_y = coords_dst @ m_inv[1]

    h_in, w_in = image.shape[:2]
    num_channels = image.shape[2] if image.ndim == 3 else 1

    x0 = np.floor(src_x).astype(np.int32)
    x1 = x0 + 1
    y0 = np.floor(src_y).astype(np.int32)
    y1 = y0 + 1

    wx1 = src_x - x0
    wx0 = 1.0 - wx1
    wy1 = src_y - y0
    wy0 = 1.0 - wy1

    # Valid in-bounds mask
    valid_mask = (src_x >= 0) & (src_x < w_in - 1) & (src_y >= 0) & (src_y < h_in - 1)

    x0_c = np.clip(x0, 0, w_in - 1)
    x1_c = np.clip(x1, 0, w_in - 1)
    y0_c = np.clip(y0, 0, h_in - 1)
    y1_c = np.clip(y1, 0, h_in - 1)

    img_float = image.astype(np.float32)
    if img_float.ndim == 2:
        img_float = img_float[:, :, np.newaxis]

    out = np.zeros((target_height, target_width, num_channels), dtype=np.float32)
    for c in range(num_channels):
        val_00 = img_float[y0_c, x0_c, c]
        val_01 = img_float[y0_c, x1_c, c]
        val_10 = img_float[y1_c, x0_c, c]
        val_11 = img_float[y1_c, x1_c, c]

        interp = wy0 * (wx0 * val_00 + wx1 * val_01) + wy1 * (wx0 * val_10 + wx1 * val_11)
        out[:, :, c] = np.where(valid_mask, interp, 0.0)

    return np.clip(out, 0, 255).astype(np.uint8)


class StandardFaceAligner(FaceAligner):
    """Production face aligner performing 5-point similarity transformation."""

    def __init__(
        self,
        target_size: tuple[int, int] = (112, 112),
        name: str = "standard_5pt_aligner",
        version: str = "1.0.0",
    ):
        self._target_size = target_size
        self._name = name
        self._version = version

    @property
    def name(self) -> str:
        return self._name

    @property
    def version(self) -> str:
        return self._version

    @property
    def target_size(self) -> tuple[int, int]:
        return self._target_size

    def _get_scaled_template(self) -> np.ndarray:
        """Return reference landmark template scaled to target dimensions."""
        tw, th = self._target_size
        scale_x = float(tw) / 112.0
        scale_y = float(th) / 112.0
        template = STANDARD_5PT_TEMPLATE_112.copy()
        template[:, 0] *= scale_x
        template[:, 1] *= scale_y
        return template

    def align(self, image: np.ndarray, detection: FaceDetection) -> AlignedFace:
        """Align detected face using 5-point similarity transform.

        Args:
            image: Source decoded image array.
            detection: FaceDetection with 5 landmarks.

        Returns:
            AlignedFace object containing normalized face array and metadata.
        """
        valid_img, landmarks_5pt = self.validate_inputs(image, detection)

        # Standardize source image channels to 3-channel RGB without mutating original
        if valid_img.ndim == 2:
            src_rgb = np.stack([valid_img] * 3, axis=-1)
        elif valid_img.ndim == 3 and valid_img.shape[2] == 1:
            src_rgb = np.concatenate([valid_img] * 3, axis=-1)
        elif valid_img.ndim == 3 and valid_img.shape[2] == 4:
            src_rgb = valid_img[:, :, :3].copy()
        else:
            # 3-channel image: keep read-only slice so caller array is never mutated
            src_rgb = valid_img

        src_pts = landmarks_5pt.to_numpy()
        dst_pts = self._get_scaled_template()

        # Compute 2x3 similarity transformation matrix
        try:
            matrix_2x3 = estimate_similarity_transform(src_pts, dst_pts)
        except InvalidLandmarksError:
            raise
        except Exception as exc:
            raise AlignmentRuntimeError(f"Failed to compute similarity transform: {exc}") from exc

        tw, th = self.target_size

        # Apply affine warp
        try:
            aligned_img = warp_affine_bilinear(src_rgb, matrix_2x3, tw, th)
        except AlignmentRuntimeError:
            raise
        except Exception as exc:
            raise AlignmentRuntimeError(f"Affine warp execution failed: {exc}") from exc

        return AlignedFace(
            image=aligned_img,
            width=tw,
            height=th,
            channels=3,
            format=self.output_format,
            transform_matrix=matrix_2x3.tolist(),
            detector_name=detection.detector_name,
            detector_version=detection.detector_version,
            aligner_name=self.name,
            aligner_version=self.version,
            model_type=self.model_type,
        )
