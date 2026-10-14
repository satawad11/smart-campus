"""Client for backend AI Node internal endpoints (CAM-003, CAM-014, CAM-015).

Calls:
- POST /internal/v1/ai-nodes/register (check-in / registration)
- POST /internal/v1/ai-nodes/heartbeat (telemetry heartbeat)
- GET  /internal/v1/ai-nodes/{aiNodeUuid}/config (camera configuration sync)

Gated by the per-node bearer credential (CAM-004 ai_node.token).
Never logs bearer tokens or decrypted camera passwords.
"""

from abc import ABC, abstractmethod
from typing import Any


class AiNodeClientError(Exception):
    """Raised when an AI node management call fails. Never includes bearer token."""


class AiNodeClient(ABC):
    @abstractmethod
    def check_in(
        self,
        hostname: str | None = None,
        ip_address: str | None = None,
        api_url: str | None = None,
        software_version: str | None = None,
        capabilities: list[str] | None = None,
    ) -> dict[str, Any]: ...

    @abstractmethod
    def heartbeat(
        self,
        cpu_usage: float | None = None,
        ram_usage: float | None = None,
        gpu_usage: float | None = None,
        gpu_memory_usage: float | None = None,
        temperature: float | None = None,
        active_camera_count: int | None = None,
        queue_depth: int | None = None,
        disk_usage: float | None = None,
        software_version: str | None = None,
        model_code: str | None = None,
        model_version: str | None = None,
        config_revision: int | None = None,
    ) -> dict[str, Any]: ...

    @abstractmethod
    def get_config(self, ai_node_uuid: str) -> dict[str, Any]: ...

    # COM-03 remote commands. Non-abstract so clients without support simply
    # never receive any command.
    def claim_commands(self) -> list[dict[str, Any]]:
        return []

    def report_command_result(
        self, command_uuid: str, succeeded: bool, message: str | None = None
    ) -> None:
        return None


class HttpAiNodeClient(AiNodeClient):
    """Real HTTP backend client for AI node management."""

    def __init__(self, base_url: str, node_token: str, timeout_seconds: float = 10.0):
        self._base_url = base_url.rstrip("/")
        self._node_token = node_token
        self._timeout_seconds = timeout_seconds

    def _headers(self) -> dict[str, str]:
        return {
            "Authorization": f"Bearer {self._node_token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
        }

    def check_in(
        self,
        hostname: str | None = None,
        ip_address: str | None = None,
        api_url: str | None = None,
        software_version: str | None = None,
        capabilities: list[str] | None = None,
    ) -> dict[str, Any]:
        try:
            import httpx
        except ImportError as exc:
            raise AiNodeClientError("httpx is not installed") from exc

        payload: dict[str, Any] = {}
        if hostname is not None:
            payload["hostname"] = hostname
        if ip_address is not None:
            payload["ip_address"] = ip_address
        if api_url is not None:
            payload["api_url"] = api_url
        if software_version is not None:
            payload["software_version"] = software_version
        if capabilities is not None:
            payload["capabilities"] = capabilities

        try:
            response = httpx.post(
                f"{self._base_url}/internal/v1/ai-nodes/register",
                json=payload,
                headers=self._headers(),
                timeout=self._timeout_seconds,
            )
        except Exception as exc:
            raise AiNodeClientError(f"Check-in call failed: {exc}") from exc

        if response.status_code not in (200, 201):
            raise AiNodeClientError(f"Check-in call returned HTTP {response.status_code}")

        try:
            return response.json()
        except Exception as exc:
            raise AiNodeClientError("Malformed JSON response from check-in") from exc

    def heartbeat(
        self,
        cpu_usage: float | None = None,
        ram_usage: float | None = None,
        gpu_usage: float | None = None,
        gpu_memory_usage: float | None = None,
        temperature: float | None = None,
        active_camera_count: int | None = None,
        queue_depth: int | None = None,
        disk_usage: float | None = None,
        software_version: str | None = None,
        model_code: str | None = None,
        model_version: str | None = None,
        config_revision: int | None = None,
    ) -> dict[str, Any]:
        try:
            import httpx
        except ImportError as exc:
            raise AiNodeClientError("httpx is not installed") from exc

        payload: dict[str, Any] = {}
        if cpu_usage is not None:
            payload["cpu_usage"] = max(0.0, min(100.0, float(cpu_usage)))
        if ram_usage is not None:
            payload["ram_usage"] = max(0.0, min(100.0, float(ram_usage)))
        if gpu_usage is not None:
            payload["gpu_usage"] = max(0.0, min(100.0, float(gpu_usage)))
        if gpu_memory_usage is not None:
            payload["gpu_memory_usage"] = max(0.0, min(100.0, float(gpu_memory_usage)))
        if temperature is not None:
            payload["temperature"] = float(temperature)
        if active_camera_count is not None:
            payload["active_camera_count"] = max(0, int(active_camera_count))
        if queue_depth is not None:
            payload["queue_depth"] = max(0, int(queue_depth))
        if disk_usage is not None:
            payload["disk_usage"] = max(0.0, min(100.0, float(disk_usage)))
        # COM-03: what the node actually runs, for fleet version and model
        # compatibility checks. Short identifiers only; truncated defensively.
        for key, value in (
            ("software_version", software_version),
            ("model_code", model_code),
            ("model_version", model_version),
        ):
            if value:
                payload[key] = str(value)[:64]
        if config_revision is not None:
            payload["config_revision"] = int(config_revision)

        try:
            response = httpx.post(
                f"{self._base_url}/internal/v1/ai-nodes/heartbeat",
                json=payload,
                headers=self._headers(),
                timeout=self._timeout_seconds,
            )
        except Exception as exc:
            raise AiNodeClientError(f"Heartbeat call failed: {exc}") from exc

        if response.status_code != 200:
            raise AiNodeClientError(f"Heartbeat call returned HTTP {response.status_code}")

        try:
            return response.json()
        except Exception as exc:
            raise AiNodeClientError("Malformed JSON response from heartbeat") from exc

    def claim_commands(self) -> list[dict[str, Any]]:
        """COM-03: pull this node's pending allowlisted commands."""
        import httpx

        try:
            response = httpx.post(
                f"{self._base_url}/internal/v1/ai-nodes/commands/claim",
                json={},
                headers=self._headers(),
                timeout=self._timeout_seconds,
            )
        except Exception as exc:
            raise AiNodeClientError(f"Command claim failed: {exc}") from exc
        if response.status_code != 200:
            raise AiNodeClientError(f"Command claim returned HTTP {response.status_code}")
        try:
            data = response.json().get("data") or []
        except Exception as exc:
            raise AiNodeClientError("Malformed JSON response from command claim") from exc
        return [c for c in data if isinstance(c, dict)]

    def report_command_result(
        self, command_uuid: str, succeeded: bool, message: str | None = None
    ) -> None:
        import httpx

        payload: dict[str, Any] = {"status": "SUCCEEDED" if succeeded else "FAILED"}
        if message:
            payload["message"] = message[:500]
        try:
            response = httpx.post(
                f"{self._base_url}/internal/v1/ai-nodes/commands/{command_uuid}/result",
                json=payload,
                headers=self._headers(),
                timeout=self._timeout_seconds,
            )
        except Exception as exc:
            raise AiNodeClientError(f"Command result failed: {exc}") from exc
        if response.status_code != 200:
            raise AiNodeClientError(f"Command result returned HTTP {response.status_code}")

    def get_config(self, ai_node_uuid: str) -> dict[str, Any]:
        try:
            import httpx
        except ImportError as exc:
            raise AiNodeClientError("httpx is not installed") from exc

        try:
            response = httpx.get(
                f"{self._base_url}/internal/v1/ai-nodes/{ai_node_uuid}/config",
                headers=self._headers(),
                timeout=self._timeout_seconds,
            )
        except Exception as exc:
            raise AiNodeClientError(f"Config sync call failed: {exc}") from exc

        if response.status_code != 200:
            raise AiNodeClientError(f"Config sync call returned HTTP {response.status_code}")

        try:
            res_json = response.json()
            return res_json.get("data", res_json)
        except Exception as exc:
            raise AiNodeClientError("Malformed JSON response from config sync") from exc
