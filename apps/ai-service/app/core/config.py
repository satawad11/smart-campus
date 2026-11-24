"""AI service settings (FND-005).

Values are read from environment variables (see .env.example). `AI_MODE`
selects the perception backend: ``mock`` (synthetic results) or ``onnx`` (the ACR-AI-01
models: YuNet detection, SFace embedding, MiniFASNet PAD, run through OpenCV). Model files
live in ``models/`` (scripts/fetch-face-models.sh); paths are relative to the working
directory, /app in the images.
"""

from pydantic import model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

# Placeholder identity of the mock pipeline; never a production model (ACR-AI-01).
MOCK_MODEL_CODE = "arcface-r50-v1"
MOCK_MODEL_VERSION = "1.0.0"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    app_name: str = "Smart Campus AI Service"
    version: str = "0.1.0"
    # mock | onnx
    ai_mode: str = "mock"

    # AI Node runtime contract
    backend_base_url: str = "http://localhost:8000"
    # Lab/rehearsal only: downgrade the plain-HTTP backend check from FAIL to WARNING.
    ai_node_allow_insecure_backend: bool = False
    ai_node_uuid: str | None = None
    ai_node_token: str | None = None
    model_code: str = MOCK_MODEL_CODE
    model_version: str = MOCK_MODEL_VERSION
    detector_model_path: str | None = "models/face_detection_yunet_2023mar.onnx"
    embedder_model_path: str | None = "models/face_recognition_sface_2021dec.onnx"
    liveness_model_dir: str | None = "models"
    # Frames wider than this are downscaled for face detection only (0 = never).
    # 1280 keeps faces of ~24 px at 1080p detectable while cutting detection time ~2.4x.
    detector_max_input_width: int = 1280
    # FACE-021 liveness thresholds (same values as the backend's config/face.php)
    liveness_threshold_real: float = 0.70
    liveness_threshold_spoof: float = 0.40
    # CCTV liveness policy (AI node only; the enrollment/scan API always enforces):
    #   enforce  - only a REAL face is recognised (close-range access points)
    #   advisory - PAD runs and is reported; a SPOOF face is still reported to the backend
    #              (LIVENESS_FAILED event) but UNCERTAIN no longer blocks recognition, and
    #              faces smaller than liveness_min_face_px are not scored (MiniFASNet is a
    #              close-range model and is not reliable on distant CCTV faces)
    #   off      - PAD is not run
    liveness_mode: str = "advisory"
    liveness_min_face_px: float = 80.0
    # CCTV face-quality gate (AI node only). Measured on real faces downscaled to CCTV
    # sizes: SFace still separates identities at ~28 px faces, so the enrollment-grade
    # gate (100 px reference, 30 deg yaw on an estimator that over-reads yaw ~1.5x)
    # threw away most usable surveillance faces.
    cctv_min_face_px: float = 24.0
    cctv_min_blur_score: float = 0.20
    cctv_max_yaw_degrees: float = 65.0
    cctv_max_pitch_degrees: float = 35.0
    cctv_max_roll_degrees: float = 35.0
    cctv_min_quality_score: float = 0.45
    # A track whose attempt was rejected or not a MATCH is re-tried after this many
    # seconds (a face walking towards the camera gets better); MATCH results are kept
    # for recognition_cache_ttl_seconds.
    recognition_retry_seconds: float = 1.0
    recognition_cache_ttl_seconds: float = 30.0
    event_buffer_path: str = "data/offline_events.db"
    log_level: str = "INFO"

    # Runtime operational intervals
    ai_node_autorun: bool = False
    heartbeat_interval_seconds: float = 15.0
    config_sync_interval_seconds: float = 30.0
    retry_interval_seconds: float = 10.0
    camera_health_interval_seconds: float = 15.0
    command_poll_interval_seconds: float = 10.0
    target_fps: float = 5.0

    @model_validator(mode="after")
    def default_model_identity(self) -> "Settings":
        """In onnx mode the embedding comes from SFace: never report the mock identity."""
        if self.ai_mode.strip().lower() == "onnx" and (
            self.model_code == MOCK_MODEL_CODE and self.model_version == MOCK_MODEL_VERSION
        ):
            self.model_code = "yunet-sface"
            self.model_version = "2021dec"
        return self


settings = Settings()
