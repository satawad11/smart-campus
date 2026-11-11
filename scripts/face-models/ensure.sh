# Sourced by the packaging scripts (ACR-AI-01): make sure apps/ai-service/models holds the
# pinned face models before the ai-service / AI node image is built (the Dockerfile's
# COPY . . bakes them into /app/models). Fetches them once; verifies them every time.
ensure_face_models() {
  local dir="${ROOT}/apps/ai-service/models"
  if [ ! -f "${dir}/MODELS.sha256" ]; then
    bash "${ROOT}/scripts/fetch-face-models.sh"
  fi
  (cd "${dir}" && sha256sum -c --quiet MODELS.sha256) \
    || { echo "ERROR: face models in apps/ai-service/models fail their checksums." >&2; exit 1; }
  echo "Face models verified (ACR-AI-01)."
}
