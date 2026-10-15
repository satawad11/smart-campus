"""COM-03e: node-side enrollment-code exchange (app.enroll)."""

import httpx
import pytest

from app import enroll as enroll_module
from app.core.config import settings


class _Response:
    def __init__(self, status_code: int, payload: dict | None = None):
        self.status_code = status_code
        self._payload = payload or {}

    def json(self) -> dict:
        return self._payload


@pytest.fixture
def local_backend(monkeypatch):
    monkeypatch.setattr(settings, "backend_base_url", "http://localhost:8000/")
    monkeypatch.setattr(settings, "ai_node_allow_insecure_backend", False)


def test_success_prints_only_the_two_env_lines(local_backend, monkeypatch, capsys):
    calls = []

    def fake_post(url, json, timeout):
        calls.append((url, json))
        return _Response(201, {"data": {"ai_node_uuid": "u-1", "token": "t" * 64}})

    monkeypatch.setattr(enroll_module.httpx, "post", fake_post)

    assert enroll_module.main(["enroll", " ABCD-EFGH-JKMN "]) == 0
    out = capsys.readouterr().out.splitlines()
    assert out == ["AI_NODE_UUID=u-1", "AI_NODE_TOKEN=" + "t" * 64]
    url = "http://localhost:8000/internal/v1/ai-nodes/enroll"
    assert calls == [(url, {"code": "ABCD-EFGH-JKMN"})]


@pytest.mark.parametrize("status", [422, 429, 500])
def test_rejection_prints_nothing_to_stdout(local_backend, monkeypatch, capsys, status):
    monkeypatch.setattr(enroll_module.httpx, "post", lambda *a, **k: _Response(status))

    assert enroll_module.main(["enroll", "ABCD-EFGH-JKMN"]) == 1
    captured = capsys.readouterr()
    assert captured.out == ""
    assert "ERROR" in captured.err


def test_unreachable_backend_is_reported(local_backend, monkeypatch, capsys):
    def boom(*a, **k):
        raise httpx.ConnectError("refused")

    monkeypatch.setattr(enroll_module.httpx, "post", boom)
    assert enroll_module.main(["enroll", "ABCD"]) == 1
    assert "unreachable" in capsys.readouterr().err


def test_refuses_plain_http_to_a_network_host(monkeypatch, capsys):
    monkeypatch.setattr(settings, "backend_base_url", "http://192.168.1.10")
    monkeypatch.setattr(settings, "ai_node_allow_insecure_backend", False)

    def must_not_call(*a, **k):
        raise AssertionError("token must not be fetched over plain HTTP")

    monkeypatch.setattr(enroll_module.httpx, "post", must_not_call)
    assert enroll_module.main(["enroll", "ABCD"]) == 1
    assert "Refusing to enroll" in capsys.readouterr().err


def test_usage_error_without_code(capsys):
    assert enroll_module.main(["enroll"]) == 1
    assert "Usage" in capsys.readouterr().err
