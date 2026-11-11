#!/usr/bin/env python3
"""AI Smart Campus host update agent (ACR-OPS-01, docs/decisions/ACR-OPS-01-WEB-SYSTEM-UPDATE.md).

Runs as root on the campus server, never inside a container.

    update-agent.py run             process signed requests the web app left in update-requests/inbox
    update-agent.py publish-state   write installed.json + history.json from VERSION / update-history.log
    update-agent.py write-releases  read GitHub releases JSON on stdin, write a token-free releases.json

Trust boundary: the backend container can only drop files in update-requests/inbox.
A request is acted on only if it is one of two allowlisted types ("check", "update"),
carries a valid HMAC-SHA256 signature, has not expired or been seen before, and (for
"update") names a tag that root's own releases.json lists as newer than the installed
version with a kit for this machine. Nothing from the request reaches a shell except
that validated tag, passed as a single argv element to the existing `campus.sh update`.

Standard library only; run with `python3 -I`.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

TAG_RE = re.compile(r"^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$")
ID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
USER_RE = re.compile(r"^[A-Za-z0-9._@-]{1,64}$")
MAX_REQUEST_BYTES = 4096
MAX_TTL_SECONDS = 900
CHECK_MIN_INTERVAL_SECONDS = 60
SEEN_KEEP = 500
LOG_TAIL_LINES = 30
MAX_NOTES_CHARS = 20000
SECRET_PATTERNS = [
    re.compile(r"(gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})"),
    re.compile(r"(?i)((?:password|secret|token|key)[A-Za-z_]*\s*[=:]\s*)\S+"),
]


class Rejected(Exception):
    """A request that must not be acted on; the message is shown to the admin (Thai)."""


def now_utc() -> datetime:
    return datetime.now(timezone.utc)


def iso(dt: datetime | None = None) -> str:
    return (dt or now_utc()).strftime("%Y-%m-%dT%H:%M:%SZ")


class Agent:
    def __init__(self, install_dir: Path, campus_cmd: list[str] | None = None):
        self.install_dir = install_dir
        self.state_dir = install_dir / "update-state"
        self.inbox = install_dir / "update-requests" / "inbox"
        self.log_dir = install_dir / "update-logs"
        self.seen_file = install_dir / ".update-agent-seen"
        self.lock_file = install_dir / ".update-agent.lock"
        self.campus_cmd = campus_cmd or ["bash", str(install_dir / "campus.sh")]

    # ---- shared helpers ----------------------------------------------------

    def write_json(self, name: str, data: object) -> None:
        """Atomic write of a world-readable (0644) file in update-state/."""
        self.state_dir.mkdir(parents=True, exist_ok=True)
        target = self.state_dir / name
        tmp = self.state_dir / f".{name}.tmp"
        tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
        os.chmod(tmp, 0o644)
        os.replace(tmp, target)

    def read_json(self, name: str, default: object) -> object:
        try:
            return json.loads((self.state_dir / name).read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return default

    def installed_version(self) -> str:
        try:
            return (self.install_dir / "VERSION").read_text(encoding="utf-8").strip() or "unknown"
        except OSError:
            return "unknown"

    def agent_key(self) -> bytes:
        try:
            for line in (self.install_dir / "campus.env").read_text(encoding="utf-8").splitlines():
                if line.startswith("UPDATE_AGENT_KEY="):
                    value = line.split("=", 1)[1].strip().strip('"')
                    if len(value) >= 32:
                        return value.encode()
        except OSError:
            pass
        raise Rejected("ยังไม่ได้ตั้งค่ากุญแจการอัปเดตผ่านเว็บ (UPDATE_AGENT_KEY)")

    # ---- state published for the web app ---------------------------------

    def publish_state(self) -> None:
        version = self.installed_version()
        history = []
        try:
            lines = (self.install_dir / "update-history.log").read_text(encoding="utf-8").splitlines()
        except OSError:
            lines = []
        for line in lines[-50:]:
            m = re.match(r"^(\S+) (update|rollback) (\S*) -> (\S+)$", line.strip())
            if m:
                history.append({"at": m.group(1), "action": m.group(2), "from": m.group(3) or None, "to": m.group(4)})
        history.reverse()
        installed_at = None
        try:
            for line in (self.install_dir / "install-info.txt").read_text(encoding="utf-8").splitlines():
                if line.startswith("installed_at="):
                    installed_at = line.split("=", 1)[1].strip()
        except OSError:
            pass
        self.write_json("installed.json", {
            "version": version,
            "installed_at": installed_at,
            "updated_at": history[0]["at"] if history else None,
            "published_at": iso(),
        })
        self.write_json("history.json", history)

    def write_releases(self, releases: list, arch: str) -> None:
        out = []
        for r in releases:
            tag = r.get("tag_name") or ""
            if r.get("draft") or not TAG_RE.match(tag):
                continue
            kit = f"campus-server-kit-{tag}-{arch}.tar.gz"
            names = {a.get("name") for a in r.get("assets", [])}
            out.append({
                "tag": tag,
                "name": (r.get("name") or tag)[:200],
                "published_at": r.get("published_at"),
                "prerelease": bool(r.get("prerelease")),
                "has_kit": kit in names and f"{kit}.sha256" in names,
                "notes": (r.get("body") or "")[:MAX_NOTES_CHARS],
            })
        self.write_json("releases.json", {"checked_at": iso(), "arch": arch, "releases": out})

    def set_status(self, **fields) -> None:
        self.write_json("status.json", fields)

    # ---- request validation --------------------------------------------------

    def load_seen(self) -> list[str]:
        try:
            return self.seen_file.read_text(encoding="utf-8").split()
        except OSError:
            return []

    def remember(self, request_id: str) -> None:
        seen = (self.load_seen() + [request_id])[-SEEN_KEEP:]
        self.seen_file.write_text("\n".join(seen) + "\n", encoding="utf-8")
        os.chmod(self.seen_file, 0o600)

    @staticmethod
    def signing_string(req: dict) -> str:
        return "\n".join([req["id"], req["type"], req.get("version") or "", req["requested_by"], str(req["expires_at"])])

    def validate(self, raw: bytes) -> dict:
        if len(raw) > MAX_REQUEST_BYTES:
            raise Rejected("คำขอมีขนาดใหญ่เกินกำหนด")
        try:
            req = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, ValueError):
            raise Rejected("รูปแบบคำขอไม่ถูกต้อง")
        if not isinstance(req, dict):
            raise Rejected("รูปแบบคำขอไม่ถูกต้อง")
        rid, rtype, by = req.get("id"), req.get("type"), req.get("requested_by")
        exp, sig, version = req.get("expires_at"), req.get("signature"), req.get("version")
        if not (isinstance(rid, str) and ID_RE.match(rid)):
            raise Rejected("รหัสคำขอไม่ถูกต้อง")
        if rtype not in ("check", "update"):
            raise Rejected("ประเภทคำขอไม่อนุญาต")
        if not (isinstance(by, str) and USER_RE.match(by)):
            raise Rejected("ชื่อผู้ขอไม่ถูกต้อง")
        if not isinstance(exp, int) or not isinstance(sig, str):
            raise Rejected("คำขอไม่มีลายเซ็นหรือเวลาหมดอายุ")
        if rtype == "update" and not (isinstance(version, str) and TAG_RE.match(version)):
            raise Rejected("รูปแบบเลขรุ่นไม่ถูกต้อง")
        if rtype == "check" and version not in (None, ""):
            raise Rejected("คำขอตรวจรุ่นต้องไม่ระบุเลขรุ่น")
        expected = hmac.new(self.agent_key(), self.signing_string(req).encode(), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, sig):
            raise Rejected("ลายเซ็นคำขอไม่ถูกต้อง")
        now = int(time.time())
        if exp < now or exp - now > MAX_TTL_SECONDS:
            raise Rejected("คำขอหมดอายุแล้ว กรุณากดใหม่อีกครั้ง")
        if rid in self.load_seen():
            raise Rejected("คำขอนี้ถูกใช้ไปแล้ว")
        return req

    def check_update_target(self, tag: str) -> None:
        data = self.read_json("releases.json", {})
        releases = data.get("releases", []) if isinstance(data, dict) else []
        tags = [r.get("tag") for r in releases]
        if tag not in tags:
            raise Rejected(f"ไม่พบรุ่น {tag} ในรายการล่าสุดจาก GitHub กรุณากดตรวจหารุ่นใหม่ก่อน")
        current = self.installed_version()
        if current not in tags:
            raise Rejected(f"ไม่พบรุ่นที่ติดตั้งอยู่ ({current}) ในรายการบน GitHub จึงอัปเดตผ่านเว็บไม่ได้ ใช้คำสั่งบนเซิร์ฟเวอร์แทน")
        # GitHub lists releases newest first.
        if tags.index(tag) >= tags.index(current):
            raise Rejected(f"รุ่น {tag} ไม่ใหม่กว่ารุ่นที่ติดตั้ง ({current}) การย้อนรุ่นทำได้ทางคำสั่ง sudo smart-campus rollback เท่านั้น")
        if not releases[tags.index(tag)].get("has_kit"):
            raise Rejected(f"รุ่น {tag} ไม่มีชุดติดตั้งสำหรับเครื่องนี้")

    # ---- running jobs ----------------------------------------------------------

    def run_logged(self, req: dict, argv: list[str]) -> tuple[int, list[str]]:
        self.log_dir.mkdir(mode=0o700, exist_ok=True)
        log_path = self.log_dir / f"{req['id']}.log"
        env = dict(os.environ, NO_COLOR="1", TERM="dumb")
        with open(log_path, "wb") as log:
            os.chmod(log_path, 0o600)
            code = subprocess.call(argv, stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT,
                                   cwd=str(self.install_dir), env=env)
        text = log_path.read_bytes().decode("utf-8", "replace")
        text = re.sub(r"\x1b\[[0-9;]*[A-Za-z]", "", text)
        tail = [redact(line) for line in text.splitlines() if line.strip()][-LOG_TAIL_LINES:]
        return code, tail

    def handle(self, req: dict) -> None:
        base = {"request_id": req["id"], "type": req["type"], "version": req.get("version"),
                "requested_by": req["requested_by"], "started_at": iso()}
        if req["type"] == "check":
            last = self.read_json("releases.json", {})
            checked = last.get("checked_at") if isinstance(last, dict) else None
            if checked and time.time() - parse_iso(checked) < CHECK_MIN_INTERVAL_SECONDS:
                self.set_status(**base, state="succeeded", finished_at=iso(), exit_code=0,
                                message="เพิ่งตรวจหารุ่นใหม่ไปเมื่อไม่ถึงหนึ่งนาทีที่แล้ว", log_tail=[])
                return
            self.set_status(**base, state="running", message="กำลังตรวจหารุ่นใหม่จาก GitHub")
            code, tail = self.run_logged(req, self.campus_cmd + ["update-check"])
            self.set_status(**base, state="succeeded" if code == 0 else "failed", finished_at=iso(), exit_code=code,
                            message="ตรวจหารุ่นใหม่เรียบร้อย" if code == 0 else "ตรวจหารุ่นใหม่ไม่สำเร็จ", log_tail=tail)
            return

        tag = req["version"]
        from_version = self.installed_version()
        self.check_update_target(tag)
        self.set_status(**base, state="running", from_version=from_version,
                        message=f"กำลังอัปเดตจาก {from_version} เป็น {tag} (สำรองข้อมูล ติดตั้ง และเริ่มระบบใหม่)")
        code, tail = self.run_logged(req, self.campus_cmd + ["update", tag, "--yes"])
        self.publish_state()
        ok = code == 0 and self.installed_version() == tag
        self.set_status(**base, state="succeeded" if ok else "failed", from_version=from_version,
                        finished_at=iso(), exit_code=code, log_tail=tail,
                        message=(f"อัปเดตเป็น {tag} เรียบร้อย" if ok else
                                 f"อัปเดตไม่สำเร็จ ระบบยังเป็นรุ่น {self.installed_version()} "
                                 f"ดูรายละเอียดบนเซิร์ฟเวอร์: {self.log_dir}/{req['id']}.log"))

    def run(self) -> int:
        import fcntl  # Linux only; imported here so the rest is testable anywhere

        with open(self.lock_file, "w") as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return 0  # another run is in progress; files stay in the inbox
            return self._run_locked()

    def _run_locked(self) -> int:
        status = self.read_json("status.json", {})
        if isinstance(status, dict) and status.get("state") == "running":
            # We hold the lock, so nothing is running: the last job was cut off (reboot, kill).
            status.update(state="failed", finished_at=iso(),
                          message="งานก่อนหน้าถูกขัดจังหวะ ตรวจระบบด้วย sudo smart-campus preflight")
            self.set_status(**status)
        if not self.inbox.is_dir():
            return 0
        while True:
            files = sorted((p for p in self.inbox.iterdir() if p.is_file()), key=lambda p: p.stat().st_mtime)
            if not files:
                return 0
            path = files[0]
            try:
                raw = path.read_bytes()[: MAX_REQUEST_BYTES + 1]
            finally:
                path.unlink(missing_ok=True)
            req = None
            try:
                req = self.validate(raw)
                self.remember(req["id"])
                self.handle(req)
            except Rejected as exc:
                self.set_status(request_id=(req or {}).get("id"), type=(req or {}).get("type"),
                                version=(req or {}).get("version"), requested_by=(req or {}).get("requested_by"),
                                state="rejected", message=str(exc), started_at=iso(), finished_at=iso(), log_tail=[])
                print(f"rejected {path.name}: {exc}", file=sys.stderr)


def redact(line: str) -> str:
    line = SECRET_PATTERNS[0].sub("[REDACTED]", line)
    return SECRET_PATTERNS[1].sub(r"\1[REDACTED]", line)


def parse_iso(value: str) -> float:
    try:
        return datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc).timestamp()
    except ValueError:
        return 0.0


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("command", choices=["run", "publish-state", "write-releases"])
    parser.add_argument("--install-dir", default=os.environ.get("SC_INSTALL_DIR", "/opt/smart-campus"))
    parser.add_argument("--arch", default=None)
    args = parser.parse_args(argv)
    agent = Agent(Path(args.install_dir))
    if args.command == "run":
        return agent.run()
    if args.command == "publish-state":
        agent.publish_state()
        return 0
    arch = args.arch
    if not arch:
        try:
            arch = (agent.install_dir / "ARCH").read_text(encoding="utf-8").strip() or "amd64"
        except OSError:
            arch = "amd64"
    agent.write_releases(json.load(sys.stdin), arch)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
