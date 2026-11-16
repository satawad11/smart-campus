"""VEH-005: the node reads plates on a camera only when the backend explicitly enables it."""

from __future__ import annotations

import pytest

from app.orchestration.orchestrator import CameraPipelineRunner


def _runner_with(config: dict) -> CameraPipelineRunner:
    # The property only reads self.config; skip the heavy constructor (stream, workers).
    runner = CameraPipelineRunner.__new__(CameraPipelineRunner)
    runner.config = config
    return runner


@pytest.mark.parametrize(
    ("value", "expected"),
    [
        (True, True),
        (False, False),
        (None, False),
        ("true", False),
        (1, False),
    ],
)
def test_plate_recognition_is_on_only_for_literal_true(value: object, expected: bool) -> None:
    assert _runner_with({"plate_recognition_enabled": value}).plate_recognition_enabled is expected


def test_missing_key_from_older_backend_means_off() -> None:
    assert _runner_with({"camera_uuid": "c-1"}).plate_recognition_enabled is False


def test_update_config_switches_plate_recognition() -> None:
    runner = _runner_with({"plate_recognition_enabled": False, "roi": None})
    runner.roi = None
    runner.camera_code = "C1"

    runner.update_config({"plate_recognition_enabled": True, "roi": None})
    assert runner.plate_recognition_enabled is True

    runner.update_config({"roi": None})
    assert runner.plate_recognition_enabled is False
