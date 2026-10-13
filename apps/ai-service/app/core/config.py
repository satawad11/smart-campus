"""AI service settings (FND-005).

Values are read from environment variables (see .env.example). `AI_MODE`
selects the perception backend; only ``mock`` is implemented in this task
(real ONNX/OpenCV pipelines arrive in Module 03/04).
"""

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    app_name: str = "Smart Campus AI Service"
    version: str = "0.1.0"
    # mock | onnx
    ai_mode: str = "mock"

    # AI Node runtime contract
    backend_base_url: str = "http://localhost:8000"
    ai_node_uuid: str | None = None
    ai_node_token: str | None = None
    model_code: str = "arcface-r50-v1"
    model_version: str = "1.0.0"
    detector_model_path: str | None = None
    embedder_model_path: str | None = None
    event_buffer_path: str = "data/offline_events.db"
    log_level: str = "INFO"

    # Runtime operational intervals
    ai_node_autorun: bool = False
    heartbeat_interval_seconds: float = 15.0
    config_sync_interval_seconds: float = 30.0
    retry_interval_seconds: float = 10.0
    camera_health_interval_seconds: float = 15.0
    target_fps: float = 5.0


settings = Settings()
