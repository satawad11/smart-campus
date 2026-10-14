"""PDPA G-7: the node refuses plain HTTP to a remote backend."""

import pytest

from app.core.config import settings
from app.health_check import check_backend_transport


@pytest.mark.parametrize(
    ("url", "allow_insecure", "expected"),
    [
        ("https://campus.example.edu", False, "PASS"),
        ("http://backend:8000", False, "WARNING"),
        ("http://127.0.0.1:8000", False, "WARNING"),
        ("http://10.20.0.5", False, "FAIL"),
        ("http://campus.example.edu", False, "FAIL"),
        ("http://campus.example.edu", True, "WARNING"),
        ("ftp://campus.example.edu", False, "FAIL"),
        ("", False, "FAIL"),
    ],
)
def test_backend_transport(monkeypatch, url, allow_insecure, expected):
    monkeypatch.setattr(settings, "backend_base_url", url)
    monkeypatch.setattr(settings, "ai_node_allow_insecure_backend", allow_insecure)

    assert check_backend_transport().status == expected
