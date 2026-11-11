#!/usr/bin/env python3
"""Read and write the host's GitHub update configuration as inert data."""

from __future__ import annotations

import argparse
import os
import re
import stat
import sys
import tempfile
from pathlib import Path


REPOSITORY_RE = re.compile(r"[A-Za-z0-9-]{1,39}/[A-Za-z0-9_.-]{1,100}\Z")
TOKEN_RE = re.compile(r"[A-Za-z0-9_]+\Z")


def validate(repository: str, token: str) -> None:
    if not REPOSITORY_RE.fullmatch(repository):
        raise ValueError("repository must be an owner/repository name")
    if any(part in {".", ".."} for part in repository.split("/")):
        raise ValueError("repository path segments are invalid")
    if token and not TOKEN_RE.fullmatch(token):
        raise ValueError("token contains unsupported characters")


def read_config(path: Path) -> tuple[str, str]:
    if path.is_symlink() or not path.is_file():
        raise ValueError("configuration must be a regular file")

    values: dict[str, str] = {}
    content = path.read_text(encoding="utf-8")
    for line in content.splitlines():
        key, separator, value = line.partition("=")
        if not separator or key not in {"GITHUB_REPO", "GITHUB_TOKEN"}:
            raise ValueError("configuration contains an invalid entry")
        if key in values:
            raise ValueError(f"configuration repeats {key}")
        values[key] = value

    if set(values) != {"GITHUB_REPO", "GITHUB_TOKEN"}:
        raise ValueError("configuration must contain GITHUB_REPO and GITHUB_TOKEN")
    validate(values["GITHUB_REPO"], values["GITHUB_TOKEN"])
    return values["GITHUB_REPO"], values["GITHUB_TOKEN"]


def write_config(path: Path, repository: str, token: str) -> None:
    validate(repository, token)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as stream:
            stream.write(f"GITHUB_REPO={repository}\nGITHUB_TOKEN={token}\n")
            stream.flush()
            os.fsync(stream.fileno())
        if os.name == "posix":
            temporary.chmod(stat.S_IRUSR | stat.S_IWUSR)
        os.replace(temporary, path)
    except BaseException:
        try:
            os.close(fd)
        except OSError:
            pass
        temporary.unlink(missing_ok=True)
        raise


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    read_parser = subparsers.add_parser("read")
    read_parser.add_argument("path", type=Path)
    write_parser = subparsers.add_parser("write")
    write_parser.add_argument("path", type=Path)
    args = parser.parse_args()

    try:
        if args.command == "read":
            repository, token = read_config(args.path)
            print(repository)
            print(token)
        else:
            lines = sys.stdin.read().splitlines()
            if len(lines) != 2:
                raise ValueError("expected repository and token on separate lines")
            write_config(args.path, lines[0], lines[1])
    except (OSError, UnicodeError, ValueError) as exc:
        print(f"update.env: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
