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
    # mock | onnx (onnx is a future task)
    ai_mode: str = "mock"


settings = Settings()
