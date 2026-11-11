"""Tests for deploy/campus-server/update-agent.py (ACR-OPS-01).

Run on Linux (the agent uses fcntl):  python3 -m unittest discover -s deploy/campus-server/tests
"""

import hashlib
import hmac
import importlib.util
import json
import os
import sys
import tempfile
import time
import unittest
import uuid
from pathlib import Path

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("update_agent", HERE.parent / "update-agent.py")
agent_mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent_mod)

KEY = "k" * 64

# Stand-in for `campus.sh`: records its argv, simulates update / update-check.
FAKE_CAMPUS = r'''
import sys, pathlib
d = pathlib.Path(sys.argv[1]); args = sys.argv[2:]
(d / "calls.log").open("a").write(" ".join(args) + "\n")
if args[0] == "update":
    print("downloading ghp_" + "A" * 36)
    print("DB_PASSWORD=supersecret")
    if (d / "fail-update").exists():
        print("install failed"); sys.exit(3)
    (d / "VERSION").write_text(args[1] + "\n")
    (d / "update-history.log").open("a").write("2026-11-10T01:00:00Z update v1.1.0-rc6 -> " + args[1] + "\n")
    print("done")
elif args[0] == "update-check":
    print("checked")
'''


class AgentTestCase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self.tmp.name)
        (self.dir / "campus.env").write_text(f"APP_NAME=x\nUPDATE_AGENT_KEY={KEY}\n")
        (self.dir / "VERSION").write_text("v1.1.0-rc6\n")
        (self.dir / "update-requests" / "inbox").mkdir(parents=True)
        fake = self.dir / "fake_campus.py"
        fake.write_text(FAKE_CAMPUS)
        self.agent = agent_mod.Agent(self.dir, campus_cmd=[sys.executable, str(fake), str(self.dir)])
        self.agent.write_releases(
            [
                self.release("v1.2.0", body="## ฟีเจอร์ใหม่\n- หน้าอัปเดตระบบ"),
                self.release("v1.2.0-rc1", prerelease=True),
                self.release("v1.1.0-nokit", kit=False),
                self.release("v1.1.0-rc6", prerelease=True),
                self.release("v1.1.0-rc5", prerelease=True),
                {"tag_name": "v9.9.9", "draft": True, "assets": []},
                {"tag_name": "bad tag; rm -rf /", "assets": []},
            ],
            "amd64",
        )

    def tearDown(self):
        self.tmp.cleanup()

    @staticmethod
    def release(tag, prerelease=False, kit=True, body=""):
        assets = []
        if kit:
            name = f"campus-server-kit-{tag}-amd64.tar.gz"
            assets = [{"name": name}, {"name": name + ".sha256"}]
        return {"tag_name": tag, "name": tag, "prerelease": prerelease, "published_at": "2026-11-01T00:00:00Z",
                "body": body, "assets": assets}

    def request(self, rtype="update", version="v1.2.0", key=KEY, ttl=600, **over):
        req = {"id": str(uuid.uuid4()), "type": rtype, "version": version if rtype == "update" else None,
               "requested_by": "admin", "requested_at": "2026-11-10T00:00:00Z",
               "expires_at": int(time.time()) + ttl}
        req.update(over)
        req["signature"] = hmac.new(key.encode(), agent_mod.Agent.signing_string(req).encode(),
                                    hashlib.sha256).hexdigest()
        return req

    def status(self):
        return json.loads((self.dir / "update-state" / "status.json").read_text(encoding="utf-8"))

    def calls(self):
        p = self.dir / "calls.log"
        return p.read_text().splitlines() if p.exists() else []


class ReleasesAndStateTest(AgentTestCase):
    def test_releases_json_is_sanitised(self):
        data = json.loads((self.dir / "update-state" / "releases.json").read_text(encoding="utf-8"))
        tags = [r["tag"] for r in data["releases"]]
        self.assertEqual(tags, ["v1.2.0", "v1.2.0-rc1", "v1.1.0-nokit", "v1.1.0-rc6", "v1.1.0-rc5"])
        first = data["releases"][0]
        self.assertEqual(set(first), {"tag", "name", "published_at", "prerelease", "has_kit", "notes"})
        self.assertTrue(first["has_kit"])
        self.assertFalse(data["releases"][2]["has_kit"])
        self.assertIn("checked_at", data)

    def test_publish_state_reads_version_and_history(self):
        (self.dir / "update-history.log").write_text(
            "2026-11-01T00:00:00Z update v1.1.0-rc5 -> v1.1.0-rc6\ngarbage line\n"
            "2026-11-02T00:00:00Z rollback v1.1.0-rc6 -> v1.1.0-rc5\n")
        (self.dir / "install-info.txt").write_text("school=x\ninstalled_at=2026-10-01T00:00:00Z\n")
        self.agent.publish_state()
        installed = json.loads((self.dir / "update-state" / "installed.json").read_text())
        history = json.loads((self.dir / "update-state" / "history.json").read_text())
        self.assertEqual(installed["version"], "v1.1.0-rc6")
        self.assertEqual(installed["installed_at"], "2026-10-01T00:00:00Z")
        self.assertEqual(len(history), 2)
        self.assertEqual(history[0], {"at": "2026-11-02T00:00:00Z", "action": "rollback",
                                      "from": "v1.1.0-rc6", "to": "v1.1.0-rc5"})


class ValidationTest(AgentTestCase):
    def assertRejected(self, raw):
        with self.assertRaises(agent_mod.Rejected):
            self.agent.validate(raw if isinstance(raw, bytes) else json.dumps(raw).encode())

    def test_valid_request_passes(self):
        req = self.request()
        self.assertEqual(self.agent.validate(json.dumps(req).encode())["version"], "v1.2.0")

    def test_bad_signature_wrong_key_or_tampered_field(self):
        self.assertRejected(self.request(key="x" * 64))
        req = self.request()
        req["version"] = "v1.2.0-rc1"
        self.assertRejected(req)

    def test_expired_or_far_future(self):
        self.assertRejected(self.request(ttl=-5))
        self.assertRejected(self.request(ttl=3600))

    def test_unknown_type_and_injection_in_version(self):
        self.assertRejected(self.request(rtype="shell"))
        self.assertRejected(self.request(version="v1.2.0; reboot"))
        self.assertRejected(self.request(version="--force"))
        self.assertRejected(self.request(rtype="check", version=None, requested_by="a b"))

    def test_malformed_and_oversized(self):
        self.assertRejected(b"not json")
        self.assertRejected(b"[]")
        self.assertRejected(b"{" + b" " * 5000 + b"}")

    def test_replay_is_rejected(self):
        req = self.request()
        self.agent.validate(json.dumps(req).encode())
        self.agent.remember(req["id"])
        self.assertRejected(req)

    def test_missing_key_rejects(self):
        (self.dir / "campus.env").write_text("APP_NAME=x\n")
        self.assertRejected(self.request())

    def test_update_target_must_be_newer_listed_and_have_kit(self):
        self.agent.check_update_target("v1.2.0")
        self.agent.check_update_target("v1.2.0-rc1")
        for tag in ["v1.1.0-rc6", "v1.1.0-rc5", "v1.1.0-nokit", "v7.0.0"]:
            with self.assertRaises(agent_mod.Rejected, msg=tag):
                self.agent.check_update_target(tag)
        (self.dir / "VERSION").write_text("dev\n")
        with self.assertRaises(agent_mod.Rejected):
            self.agent.check_update_target("v1.2.0")


@unittest.skipUnless(hasattr(os, "fork"), "agent run loop needs Linux (fcntl)")
class RunTest(AgentTestCase):
    def drop(self, req, name=None):
        (self.dir / "update-requests" / "inbox" / (name or f"{req['id']}.json")).write_text(json.dumps(req))

    def test_update_runs_campus_update_with_only_the_tag(self):
        self.drop(self.request())
        self.assertEqual(self.agent.run(), 0)
        self.assertEqual(self.calls(), ["update v1.2.0 --yes"])
        st = self.status()
        self.assertEqual(st["state"], "succeeded")
        self.assertEqual(st["from_version"], "v1.1.0-rc6")
        tail = "\n".join(st["log_tail"])
        self.assertNotIn("ghp_", tail)
        self.assertNotIn("supersecret", tail)
        installed = json.loads((self.dir / "update-state" / "installed.json").read_text())
        self.assertEqual(installed["version"], "v1.2.0")
        self.assertEqual(list((self.dir / "update-requests" / "inbox").iterdir()), [])

    def test_failed_update_reports_failure(self):
        (self.dir / "fail-update").write_text("1")
        self.drop(self.request())
        self.agent.run()
        st = self.status()
        self.assertEqual(st["state"], "failed")
        self.assertEqual(st["exit_code"], 3)
        self.assertIn("v1.1.0-rc6", st["message"])

    def test_rejected_request_never_runs_anything(self):
        self.drop(self.request(key="x" * 64))
        self.agent.run()
        self.assertEqual(self.calls(), [])
        self.assertEqual(self.status()["state"], "rejected")
        self.assertEqual(list((self.dir / "update-requests" / "inbox").iterdir()), [])

    def test_downgrade_is_rejected(self):
        self.drop(self.request(version="v1.1.0-rc5"))
        self.agent.run()
        self.assertEqual(self.calls(), [])
        self.assertIn("rollback", self.status()["message"])

    def test_check_request_runs_update_check_and_is_rate_limited(self):
        self.drop(self.request(rtype="check"))
        self.agent.run()
        # releases.json was just written in setUp, so this check is a no-op.
        self.assertEqual(self.calls(), [])
        self.assertEqual(self.status()["state"], "succeeded")
        data = json.loads((self.dir / "update-state" / "releases.json").read_text())
        data["checked_at"] = "2026-01-01T00:00:00Z"
        (self.dir / "update-state" / "releases.json").write_text(json.dumps(data))
        self.drop(self.request(rtype="check"))
        self.agent.run()
        self.assertEqual(self.calls(), ["update-check"])

    def test_interrupted_job_is_marked_failed(self):
        self.agent.set_status(state="running", type="update", version="v1.2.0")
        self.agent.run()
        self.assertEqual(self.status()["state"], "failed")

    def test_same_request_twice_runs_once(self):
        req = self.request()
        self.drop(req, "a.json")
        self.drop(req, "b.json")
        self.agent.run()
        self.assertEqual(self.calls(), ["update v1.2.0 --yes"])


if __name__ == "__main__":
    unittest.main()
