#!/usr/bin/env bash
# =============================================================================
# AI Smart Campus — AI Node upgrade from a previous kit (COM-04)
#
# Run inside a NEWLY EXTRACTED kit directory on the node:
#   ./upgrade.sh                      # previous kit = folder of the running container
#   ./upgrade.sh --from <kit-dir>     # or name it explicitly
#
# 1. verifies this kit (SHA256SUMS, ARCH);
# 2. carries the node's configuration over from the previous kit:
#    .env (UUID, token, backend URL; mode 600), certs/ and models/ — the node is
#    NOT re-enrolled and the token is never printed;
# 3. lists settings that are new in .env.example and missing from .env;
# 4. runs ./update.sh (loads the image, re-creates the container; the offline
#    event buffer volume is preserved) and ./healthcheck.sh.
# On a FAIL health check it exits 1 and prints the rollback command
# (the previous kit's ./update.sh). The previous kit folder is left untouched.
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

CONTAINER="smart-campus-ai-node"
FROM=""
while [ "$#" -gt 0 ]; do
    case "$1" in
        --from) FROM="${2:-}"; shift 2 ;;
        -h|--help) sed -n '2,18p' "$0"; exit 0 ;;
        *) echo "unknown argument: $1 (usage: ./upgrade.sh [--from <kit-dir>])" >&2; exit 2 ;;
    esac
done

die() { echo "ERROR: $*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || die "'docker' not found."
[ -f ai-node-image.tar.gz ] || die "this folder is not an AI node kit (no ai-node-image.tar.gz)."

# 1. Verify this kit
case "$(uname -m)" in
    x86_64|amd64) HOST_ARCH=amd64 ;;
    aarch64|arm64) HOST_ARCH=arm64 ;;
    *) HOST_ARCH="$(uname -m)" ;;
esac
if [ -f ARCH ] && [ "$(cat ARCH)" != "${HOST_ARCH}" ]; then
    die "this kit is for $(cat ARCH) but this host is ${HOST_ARCH}."
fi
if [ -f SHA256SUMS ]; then
    echo "Verifying kit checksums..."
    sha256sum -c --quiet SHA256SUMS || die "checksum mismatch: the kit is damaged or modified. Nothing was changed."
fi
NEW_VERSION="$(cat VERSION 2>/dev/null || echo unknown)"

# 2. Find the previous kit
if [ -z "${FROM}" ]; then
    FROM="$(docker inspect "${CONTAINER}" \
        --format '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' 2>/dev/null || true)"
    [ -n "${FROM}" ] || die "no running ${CONTAINER} container to take the configuration from. Use --from <previous-kit-dir>, or ./install.sh for a first installation."
fi
FROM="$(cd "${FROM}" 2>/dev/null && pwd)" || die "previous kit folder not found: ${FROM}"
[ "${FROM}" != "${SCRIPT_DIR}" ] || die "the running node already uses this folder (${NEW_VERSION}). To re-apply it run ./update.sh."
[ -f "${FROM}/.env" ] || die "${FROM}/.env not found."
OLD_VERSION="$(cat "${FROM}/VERSION" 2>/dev/null || echo unknown)"

echo "Upgrading AI node: ${OLD_VERSION} (${FROM}) -> ${NEW_VERSION} (${SCRIPT_DIR})"

# 3. Carry the configuration over
if [ -f .env ]; then
    echo "Keeping the .env already in this folder."
else
    cp -p "${FROM}/.env" .env
    echo "Copied .env from the previous kit (token not shown)."
fi
chmod 600 .env
mkdir -p certs models
for d in certs models; do
    if [ -n "$(ls -A "${d}" 2>/dev/null)" ]; then
        echo "Keeping the ${d}/ already in this folder."
    elif [ -d "${FROM}/${d}" ] && [ -n "$(ls -A "${FROM}/${d}" 2>/dev/null)" ]; then
        cp -a "${FROM}/${d}/." "${d}/"
        echo "Copied ${d}/ from the previous kit."
    fi
done

if [ -f .env.example ]; then
    missing="$(comm -23 \
        <(grep -oE '^[A-Z_][A-Z0-9_]*=' .env.example | sort -u) \
        <(grep -oE '^[A-Z_][A-Z0-9_]*=' .env | sort -u) | tr -d '=' | tr '\n' ' ')"
    if [ -n "${missing// /}" ]; then
        echo "NOTE: settings new in this version and not in .env (their defaults apply): ${missing}"
        echo "      Review them in .env.example and add them to .env if the defaults do not fit."
    fi
fi

# 4. Apply
echo "${FROM}" > .previous-kit
./update.sh

# Judge by the diagnostic's own verdict (exit 2 = FAIL). Docker's health status is
# not used: the compose healthcheck also reports WARNING (exit 1, e.g. mock mode or
# no cameras assigned) as "unhealthy".
echo "Letting the node check in with the backend..."
sleep 15
state="$(docker inspect "${CONTAINER}" --format '{{.State.Status}}' 2>/dev/null || echo missing)"

echo "Final health check (${NEW_VERSION}):"
set +e
./healthcheck.sh
rc=$?
set -e
if [ "${state}" != "running" ] || [ "${rc}" -ge 2 ]; then
    echo "============================================================" >&2
    echo " UPGRADE TO ${NEW_VERSION} FAILED THE HEALTH CHECK (container: ${state})." >&2
    echo " Roll back to ${OLD_VERSION}:" >&2
    echo "   cd ${FROM} && ./update.sh" >&2
    echo "============================================================" >&2
    exit 1
fi

echo "============================================================"
echo " AI node upgraded to ${NEW_VERSION} (container: ${state})."
echo " Previous kit kept for rollback: cd ${FROM} && ./update.sh"
echo " Delete it once this version is accepted."
echo "============================================================"
