"""Tests for safe parsing of deploy/campus-server/update.env."""

import importlib.util
import os
import stat
import tempfile
import unittest
from pathlib import Path


HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("update_config", HERE.parent / "update-config.py")
config_mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(config_mod)


class UpdateConfigTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self.tmp.name)
        self.path = self.dir / "update.env"

    def tearDown(self):
        self.tmp.cleanup()

    def test_reads_legacy_plain_assignment_file_as_data(self):
        self.path.write_text("GITHUB_REPO=satawad11/smart-campus\nGITHUB_TOKEN=github_pat_a1b2_3\n")
        self.assertEqual(
            config_mod.read_config(self.path),
            ("satawad11/smart-campus", "github_pat_a1b2_3"),
        )

    def test_rejects_shell_payload_without_executing_it(self):
        marker = self.dir / "should-not-exist"
        self.path.write_text(
            f"GITHUB_REPO=satawad11/smart-campus\nGITHUB_TOKEN=x\n$(touch {marker})\n"
        )
        with self.assertRaises(ValueError):
            config_mod.read_config(self.path)
        self.assertFalse(marker.exists())

    def test_rejects_shell_syntax_inside_repository_or_token(self):
        for repository, token in (
            ("owner/repo;touch marker", ""),
            ("owner/repo", "token;touch_marker"),
        ):
            with self.subTest(repository=repository, token=token):
                with self.assertRaises(ValueError):
                    config_mod.validate(repository, token)

    def test_rejects_duplicate_or_missing_keys(self):
        for content in (
            "GITHUB_REPO=owner/repo\nGITHUB_REPO=other/repo\nGITHUB_TOKEN=\n",
            "GITHUB_REPO=owner/repo\n",
        ):
            with self.subTest(content=content):
                self.path.write_text(content)
                with self.assertRaises(ValueError):
                    config_mod.read_config(self.path)

    def test_writer_atomically_creates_private_config(self):
        config_mod.write_config(self.path, "owner/repo", "github_pat_a1b2_3")
        self.assertEqual(config_mod.read_config(self.path), ("owner/repo", "github_pat_a1b2_3"))
        if os.name == "posix":
            self.assertEqual(stat.S_IMODE(self.path.stat().st_mode), 0o600)

    def test_refuses_symlinked_configuration(self):
        target = self.dir / "target.env"
        target.write_text("GITHUB_REPO=owner/repo\nGITHUB_TOKEN=\n")
        try:
            self.path.symlink_to(target)
        except (NotImplementedError, OSError) as exc:
            self.skipTest(f"symlinks are unavailable: {exc}")
        with self.assertRaises(ValueError):
            config_mod.read_config(self.path)


if __name__ == "__main__":
    unittest.main()
