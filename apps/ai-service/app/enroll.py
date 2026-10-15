"""Exchange a one-time enrollment code for this node's identity (COM-03e).

Usage (inside the node image, normally via deploy/ai-node/enroll.sh):
    python -m app.enroll <CODE>

On success prints exactly two lines to stdout for the installer to write
into .env, and nothing else:
    AI_NODE_UUID=<uuid>
    AI_NODE_TOKEN=<token>
Errors go to stderr with exit status 1. The same transport rule as the
healthcheck applies: plain HTTP only to a local host or with
AI_NODE_ALLOW_INSECURE_BACKEND=true, so the token is never fetched in clear
text over a network by accident.
"""

import sys

import httpx

from app.core.config import settings
from app.health_check import check_backend_transport


def enroll(code: str) -> tuple[str, str]:
    transport = check_backend_transport()
    if transport.status == "FAIL":
        raise RuntimeError(f"Refusing to enroll: {transport.message}")

    url = settings.backend_base_url.rstrip("/") + "/internal/v1/ai-nodes/enroll"
    try:
        response = httpx.post(url, json={"code": code}, timeout=15.0)
    except httpx.HTTPError as exc:
        raise RuntimeError(f"Backend unreachable at {settings.backend_base_url}: {exc}") from exc

    if response.status_code == 429:
        raise RuntimeError("Too many attempts; wait one minute and try again.")
    if response.status_code != 201:
        raise RuntimeError(
            "Enrollment code rejected (invalid, already used or expired). "
            "Ask the administrator for a new code."
        )

    data = response.json().get("data") or {}
    uuid, token = data.get("ai_node_uuid"), data.get("token")
    if not uuid or not token:
        raise RuntimeError("Unexpected response from the backend.")
    return uuid, token


def main(argv: list[str]) -> int:
    if len(argv) != 2 or not argv[1].strip():
        print("Usage: python -m app.enroll <ENROLLMENT-CODE>", file=sys.stderr)
        return 1
    try:
        uuid, token = enroll(argv[1].strip())
    except RuntimeError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    print(f"AI_NODE_UUID={uuid}")
    print(f"AI_NODE_TOKEN={token}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
