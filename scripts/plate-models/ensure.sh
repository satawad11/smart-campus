# Sourced by scripts/package-ai-node.sh (VEH-008, ACR-VEH-01 D4): make sure
# apps/ai-service/models holds the pinned plate models before the AI node image is built.
# Fetches them once; verifies them every time. Bundling the models does not switch plate
# reading on: that still needs feature.alpr and the camera switch (owner approval after the
# real-photo evaluation).
ensure_plate_models() {
  local dir="${ROOT}/apps/ai-service/models"
  if [ ! -f "${dir}/PLATE_MODELS.sha256" ]; then
    bash "${ROOT}/scripts/fetch-plate-models.sh"
  fi
  (cd "${dir}" && sha256sum -c --quiet PLATE_MODELS.sha256) \
    || { echo "ERROR: plate models in apps/ai-service/models fail their checksums." >&2; exit 1; }
  echo "Plate models verified (VEH-008)."
}
