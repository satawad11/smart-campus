"""COM-03: the node executes only allowlisted commands and reports results."""

from typing import Any

from app.orchestration.node_client import AiNodeClient, HttpAiNodeClient
from app.orchestration.orchestrator import NodeOrchestrator


class CommandNodeClient(AiNodeClient):
    def __init__(self, commands: list[dict[str, Any]]):
        self.commands = commands
        self.results: list[tuple[str, bool, str | None]] = []
        self.config_fetches = 0

    def check_in(self, **kwargs) -> dict:
        return {"success": True}

    def heartbeat(self, **kwargs) -> dict:
        return {"success": True}

    def get_config(self, ai_node_uuid: str) -> dict:
        self.config_fetches += 1
        return {"ai_node_uuid": ai_node_uuid, "cameras": []}

    def claim_commands(self) -> list[dict[str, Any]]:
        claimed, self.commands = self.commands, []
        return claimed

    def report_command_result(self, command_uuid, succeeded, message=None) -> None:
        self.results.append((command_uuid, succeeded, message))


def _orchestrator(client: CommandNodeClient, restarts: list[int]) -> NodeOrchestrator:
    return NodeOrchestrator(
        node_uuid="node-1",
        ai_mode="mock",
        event_buffer_path=":memory:",
        node_client=client,
        restart_handler=lambda: restarts.append(1),
    )


def test_resync_config_fetches_configuration_and_reports_success():
    client = CommandNodeClient([{"uuid": "c1", "command": "RESYNC_CONFIG"}])
    orchestrator = _orchestrator(client, [])

    assert orchestrator.process_commands() == 1
    assert client.config_fetches == 1
    assert client.results[0][:2] == ("c1", True)


def test_restart_reports_before_restarting():
    restarts: list[int] = []
    client = CommandNodeClient([{"uuid": "c2", "command": "RESTART_SERVICE"}])

    _orchestrator(client, restarts).process_commands()

    assert client.results == [("c2", True, "restarting service")]
    assert restarts == [1]


def test_unknown_commands_are_refused_not_interpreted():
    restarts: list[int] = []
    client = CommandNodeClient([{"uuid": "c3", "command": "rm -rf /"}, {"command": "NO_UUID"}])

    assert _orchestrator(client, restarts).process_commands() == 1
    assert client.results == [("c3", False, "unsupported command: rm -rf /")]
    assert restarts == []
    assert client.config_fetches == 0


def test_a_failing_command_is_reported_as_failed():
    client = CommandNodeClient([{"uuid": "c4", "command": "RESYNC_CONFIG"}])

    def boom(_uuid):
        raise RuntimeError("backend unreachable")

    client.get_config = boom
    _orchestrator(client, []).process_commands()

    assert client.results == [("c4", False, "backend unreachable")]


def test_http_client_claims_and_reports(monkeypatch):
    calls: list[tuple[str, dict]] = []

    class Response:
        status_code = 200

        def __init__(self, body):
            self._body = body

        def json(self):
            return self._body

    def fake_post(url, json, headers, timeout):
        calls.append((url, json))
        if url.endswith("/claim"):
            return Response({"data": [{"uuid": "c5", "command": "RESYNC_CONFIG"}]})
        return Response({"success": True})

    import httpx

    monkeypatch.setattr(httpx, "post", fake_post)
    client = HttpAiNodeClient("http://backend:8000", "token")

    assert client.claim_commands() == [{"uuid": "c5", "command": "RESYNC_CONFIG"}]
    client.report_command_result("c5", False, "x" * 600)

    assert calls[0][0] == "http://backend:8000/internal/v1/ai-nodes/commands/claim"
    assert calls[1][0] == "http://backend:8000/internal/v1/ai-nodes/commands/c5/result"
    assert calls[1][1]["status"] == "FAILED"
    assert len(calls[1][1]["message"]) == 500


class RuntimeConfigClient(CommandNodeClient):
    def __init__(self, runtime):
        super().__init__([])
        self.runtime = runtime

    def get_config(self, ai_node_uuid: str) -> dict:
        return {"ai_node_uuid": ai_node_uuid, "cameras": [], "runtime": self.runtime}


def test_runtime_settings_revision_is_applied_and_reported():
    client = RuntimeConfigClient(
        {"revision": 3, "settings": {"heartbeat_interval_seconds": 20, "target_fps": 8}}
    )
    orchestrator = _orchestrator(client, [])

    assert orchestrator.sync_config() is True
    assert orchestrator.applied_config_revision == 3
    assert orchestrator.heartbeat_interval == 20.0
    assert orchestrator.target_fps == 8.0
    assert orchestrator.get_status_summary()["applied_config_revision"] == 3


def test_out_of_range_or_unknown_runtime_settings_are_ignored():
    bad = {"heartbeat_interval_seconds": 1, "shell": "x", "target_fps": True}
    client = RuntimeConfigClient({"revision": 4, "settings": bad})
    orchestrator = _orchestrator(client, [])
    before = (orchestrator.heartbeat_interval, orchestrator.target_fps)

    orchestrator.sync_config()

    assert (orchestrator.heartbeat_interval, orchestrator.target_fps) == before
    assert orchestrator.applied_config_revision == 4


def test_missing_runtime_section_keeps_defaults():
    orchestrator = _orchestrator(RuntimeConfigClient(None), [])

    orchestrator.sync_config()

    assert orchestrator.applied_config_revision is None


def test_heartbeat_sends_config_revision(monkeypatch):
    sent = {}

    class Response:
        status_code = 200

        @staticmethod
        def json():
            return {"success": True}

    def fake_post(url, json, headers, timeout):
        sent.update(json)
        return Response()

    import httpx

    monkeypatch.setattr(httpx, "post", fake_post)
    HttpAiNodeClient("http://backend:8000", "t").heartbeat(config_revision=5)

    assert sent["config_revision"] == 5


def test_quality_gate_settings_reach_the_shared_evaluator():
    """CAM-022: quality_* keys retune the gate every camera worker uses."""
    settings = {"quality_max_pitch_degrees": 45, "quality_min_blur_score": 0.2, "target_fps": 6}
    orchestrator = _orchestrator(RuntimeConfigClient({"revision": 5, "settings": settings}), [])

    orchestrator.sync_config()

    t = orchestrator.quality_evaluator.thresholds
    assert t.max_pitch_degrees == 45.0
    assert t.min_blur_score == 0.2
    assert t.max_yaw_degrees == 30.0  # untouched keys keep the default
    assert orchestrator.target_fps == 6.0
    assert orchestrator.applied_config_revision == 5


def test_quality_gate_is_absolute_per_revision_and_bounded():
    """A later revision without a key restores its default; out-of-range values are ignored."""
    client = RuntimeConfigClient({"revision": 1, "settings": {"quality_max_yaw_degrees": 50}})
    orchestrator = _orchestrator(client, [])
    orchestrator.sync_config()
    assert orchestrator.quality_evaluator.thresholds.max_yaw_degrees == 50.0

    client.runtime = {
        "revision": 2,
        "settings": {"quality_min_luminance": 0.1, "quality_max_pitch_degrees": 90},
    }
    orchestrator.sync_config()

    t = orchestrator.quality_evaluator.thresholds
    assert t.max_yaw_degrees == 30.0
    assert t.min_luminance == 0.1
    assert t.max_pitch_degrees == 25.0  # 90 is outside 15..60
