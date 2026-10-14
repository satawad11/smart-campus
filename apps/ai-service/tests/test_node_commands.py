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
