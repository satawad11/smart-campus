"""AI Node startup must reject template credentials instead of reporting RUNNING."""

from app.core.config import Settings
from app.main import node_credentials_configured


def test_placeholder_credentials_are_not_configured() -> None:
    current = Settings(
        ai_node_uuid="00000000-0000-0000-0000-000000000000",
        ai_node_token="sc_node_your_issued_token_here",
        _env_file=None,
    )

    assert node_credentials_configured(current) is False


def test_enrolled_credentials_are_configured() -> None:
    current = Settings(
        ai_node_uuid="8fa37260-149b-449e-b1df-4b77f9892c90",
        ai_node_token="sc_node_" + "a" * 64,
        _env_file=None,
    )

    assert node_credentials_configured(current) is True
