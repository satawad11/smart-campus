#!/usr/bin/env bash
#
# verify-ci.sh — FND-011 verification
#
# Validates the base CI pipeline: the workflow file parses as YAML, triggers on
# push and pull_request, and defines jobs covering backend tests, frontend
# lint/build, AI tests, a dependency/security scan, and a Docker build check.
#
# Exit 0 + "CI OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT}"

WF="${ROOT}/.github/workflows/ci.yml"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

echo "[1/3] Workflow file present..."
[ -f "${WF}" ] || { echo "  FAIL: .github/workflows/ci.yml missing"; echo "CI FAILED"; exit 1; }

echo "[2/3] Valid YAML + triggers + jobs..."
PYBIN="$(command -v python || command -v python3 || true)"
if [ -n "${PYBIN}" ] && ! "${PYBIN}" -c "import yaml" >/dev/null 2>&1; then
  PYBIN=""  # PyYAML unavailable — use the textual fallback below.
fi
if [ -n "${PYBIN}" ]; then
  "${PYBIN}" - "$WF" <<'PY' || fail "workflow YAML/structure invalid"
import sys, yaml
with open(sys.argv[1]) as f:
    wf = yaml.safe_load(f)
# PyYAML parses the bare `on:` key as boolean True.
triggers = wf.get(True, wf.get("on"))
assert triggers, "no triggers"
assert "push" in triggers and "pull_request" in triggers, "must trigger on push and pull_request"
jobs = wf.get("jobs", {})
for j in ["backend", "frontend", "ai-service", "security-scan", "docker-build"]:
    assert j in jobs, f"missing job: {j}"
blob = yaml.dump(wf)
for needle in ["php artisan test", "npm run build", "npm run lint", "pytest", "docker compose build"]:
    assert needle in blob, f"missing step command: {needle}"
print("  parsed: %d jobs, triggers ok" % len(jobs))
PY
else
  echo "    python unavailable — doing textual checks..."
  for needle in "php artisan test" "npm run build" "pytest" "docker compose build" "pull_request"; do
    grep -q "${needle}" "${WF}" || fail "missing '${needle}' in workflow"
  done
fi

echo "[3/3] Docker build target is valid (compose config)..."
if command -v docker >/dev/null 2>&1; then
  docker compose config >/dev/null 2>&1 || fail "docker compose config invalid"
else
  echo "    docker unavailable — skipping compose validation."
fi

echo
if [ "${errors}" -ne 0 ]; then
  echo "CI FAILED: ${errors} problem(s)."
  exit 1
fi
echo "CI OK"
