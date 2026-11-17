#!/usr/bin/env bash
# Update one or more AI nodes over SSH with a single command (COM-04).
#
# Usage:
#   scripts/push-ai-node-update.sh <kit.tar.gz | release-tag> <user@node> [user@node ...]
#
#   <kit.tar.gz>   a kit from scripts/package-ai-node.sh (dist/ai-node-kit-<ver>-<arch>.tar.gz)
#   <release-tag>  e.g. v1.2.0: the node's kit (matching its CPU) is downloaded from the
#                  GitHub Release with `gh release download` into dist/
#
# For each node: checks the CPU architecture, verifies the kit's .sha256 when present,
# copies the kit (scp), extracts it next to the running one and runs the kit's
# ./upgrade.sh, which carries .env/certs/models over (no re-enrollment) and
# health-checks the result. The previous kit stays on the node for rollback.
# Needs key-based SSH to the node (the node user must be in the docker group).
# Stops at the first node that fails.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if [ "$#" -lt 2 ]; then
  sed -n '2,18p' "$0" >&2
  exit 2
fi
SOURCE="$1"; shift
SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=10)

node_arch() {
  case "$(ssh "${SSH_OPTS[@]}" "$1" uname -m)" in
    x86_64|amd64) echo amd64 ;;
    aarch64|arm64) echo arm64 ;;
    *) echo unknown ;;
  esac
}

kit_for() { # <arch> -> local kit path
  if [ -f "${SOURCE}" ]; then
    echo "${SOURCE}"
    return
  fi
  local name="ai-node-kit-${SOURCE}-$1.tar.gz"
  if [ ! -f "${ROOT}/dist/${name}" ]; then
    command -v gh >/dev/null 2>&1 || { echo "ERROR: '${SOURCE}' is not a file and gh is not installed." >&2; exit 1; }
    echo "Downloading ${name} from GitHub Release ${SOURCE}..." >&2
    mkdir -p "${ROOT}/dist"
    gh release download "${SOURCE}" --dir "${ROOT}/dist" --clobber \
      --pattern "${name}" --pattern "${name}.sha256" >&2
  fi
  echo "${ROOT}/dist/${name}"
}

for NODE in "$@"; do
  echo "=================== ${NODE} ==================="
  ARCH="$(node_arch "${NODE}")"
  KIT="$(kit_for "${ARCH}")"
  BASE="$(basename "${KIT}")"
  DIR="${BASE%.tar.gz}"

  case "${DIR}" in
    ai-node-kit-*-"${ARCH}") ;;
    *) echo "ERROR: ${BASE} does not match the node's architecture (${ARCH})." >&2; exit 1 ;;
  esac

  if [ -f "${KIT}.sha256" ]; then
    echo "Verifying ${BASE}..."
    (cd "$(dirname "${KIT}")" && sha256sum -c --quiet "${BASE}.sha256")
  fi

  echo "Copying ${BASE} to ${NODE}..."
  scp -q "${SSH_OPTS[@]}" "${KIT}" "${NODE}:${BASE}"

  # shellcheck disable=SC2029  # BASE/DIR are expanded locally on purpose
  ssh "${SSH_OPTS[@]}" "${NODE}" "set -e; tar -xzf '${BASE}' && rm -f '${BASE}' && cd '${DIR}' && ./upgrade.sh"
done
echo "All nodes updated."
