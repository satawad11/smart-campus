"""Standalone daemon entrypoint for AI Node (Module 04).

Usage:
    python -m app.node_daemon

Reads configuration from environment variables or .env file (see .env.example).
Connects to backend internal APIs, syncs assigned cameras, processes RTSP
video streams, performs face tracking and recognition, delivers events, and
buffers offline during network disconnects.

Signals:
    SIGTERM / SIGINT: initiates graceful shutdown, releases OpenCV streams,
    joins worker pools, and flushes pending states cleanly without orphaned processes.
"""

import logging
import signal
import sys
import time

from app.core.config import settings
from app.orchestration.orchestrator import NodeOrchestrator

logging.basicConfig(
    level=getattr(logging, settings.log_level.upper(), logging.INFO),
    format="%(asctime)s [%(levelname)s] [%(name)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("ai_node.daemon")


def main() -> int:
    logger.info("Starting AI Node Daemon (%s, AI_MODE=%s)...", settings.app_name, settings.ai_mode)

    if not settings.ai_node_uuid or not settings.ai_node_token:
        logger.warning(
            "AI_NODE_UUID or AI_NODE_TOKEN is not configured. Running in stand-by observation mode."
        )

    orchestrator = NodeOrchestrator()

    stop_requested = False

    def handle_signal(sig, frame):  # noqa: ANN001
        nonlocal stop_requested
        sig_name = "SIGTERM" if sig == signal.SIGTERM else "SIGINT"
        logger.info("Received %s — initiating graceful AI Node shutdown...", sig_name)
        stop_requested = True
        orchestrator.stop(timeout=10.0)
        sys.exit(0)

    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    try:
        orchestrator.start()
        logger.info("AI Node Daemon is running. Press Ctrl+C or send SIGTERM to stop.")
        while not stop_requested:
            time.sleep(1.0)
    except (KeyboardInterrupt, SystemExit):
        pass
    except Exception as exc:
        logger.exception("Fatal exception in AI Node daemon: %s", exc)
        orchestrator.stop(timeout=5.0)
        return 1

    orchestrator.stop(timeout=5.0)
    logger.info("AI Node Daemon shutdown complete.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
