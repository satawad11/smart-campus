#!/usr/bin/env bash
# Fetch and prepare the plate models (VEH-008, ACR-VEH-01 D4) into apps/ai-service/models/.
#
# Approved by the owner on 2026-11-20 (docs/decisions/OWNER-DECISIONS-2026-11-20.md) for
# building and evaluating. Plate reading stays off on every camera until the models are
# evaluated on real gate-camera photos and the thresholds are approved.
#
# Run on the vendor/build machine. Every upstream file is pinned and checked by SHA-256.
#   LPD-YuNet (plate detection, Apache-2.0)            opencv/opencv_zoo
#   th_PP-OCRv5_mobile_rec (Thai text OCR, Apache-2.0) PaddlePaddle on Hugging Face,
#     converted to ONNX in a throwaway Docker container (Paddle never runs on this host)
#     and checked against Paddle through OpenCV DNN.
# Licence texts are copied next to the models. The model files are not committed to git.
#
# Usage: scripts/fetch-plate-models.sh            (needs curl, sha256sum, docker)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${ROOT}/apps/ai-service/models"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

ZOO_COMMIT=f12e12798e8314f7c074a6656816c048dcc95b7a
HF_REVISION=9e25080455925d3943f5db885fddc79db6a07ca3
PADDLEOCR_TAG=v3.0.0
ZOO_LFS="https://media.githubusercontent.com/media/opencv/opencv_zoo/${ZOO_COMMIT}/models"
ZOO_RAW="https://raw.githubusercontent.com/opencv/opencv_zoo/${ZOO_COMMIT}/models"
HF="https://huggingface.co/PaddlePaddle/th_PP-OCRv5_mobile_rec/resolve/${HF_REVISION}"

fetch() { # fetch <url> <dest> [sha256]
  curl -sSfL --retry 3 -o "$2" "$1"
  if [ -n "${3:-}" ]; then
    echo "$3  $2" | sha256sum -c --quiet - || { echo "ERROR: checksum mismatch for $1" >&2; exit 1; }
  fi
}

mkdir -p "${OUT}/licenses"
echo "Fetching LPD-YuNet (opencv_zoo ${ZOO_COMMIT:0:7})..."
fetch "${ZOO_LFS}/license_plate_detection_yunet/license_plate_detection_lpd_yunet_2023mar.onnx" \
  "${OUT}/license_plate_detection_lpd_yunet_2023mar.onnx" \
  6d4978a7b6d25514d5e24811b82bfb511d166bdd8ca3b03aa63c1623d4d039c7
fetch "${ZOO_RAW}/license_plate_detection_yunet/LICENSE" "${OUT}/licenses/LPD-YuNet-LICENSE.txt"

echo "Fetching th_PP-OCRv5_mobile_rec (Hugging Face ${HF_REVISION:0:7})..."
mkdir -p "${WORK}/rec"
fetch "${HF}/inference.json" "${WORK}/rec/inference.json" \
  b92a9442af5ac6da3c6ba30ce1672d00a2d7a0fbd8068ed543ac6dcb9d5c7764
fetch "${HF}/inference.pdiparams" "${WORK}/rec/inference.pdiparams" \
  45ec91f2322b58b8d30ba27d18fcfdbb8bf388b918dd978162d2af91e0c66d4b
fetch "${HF}/inference.yml" "${WORK}/rec/inference.yml" \
  f6ba7fefc38ca1ff398ddafa75d67d16e0b3757c4e6c833adffee98a981766c9
fetch "https://raw.githubusercontent.com/PaddlePaddle/PaddleOCR/${PADDLEOCR_TAG}/LICENSE" \
  "${OUT}/licenses/PaddleOCR-LICENSE.txt"
cp "${ROOT}/scripts/plate-models/export_th_ocr.py" "${WORK}/"

echo "Converting the Thai OCR model to ONNX in a throwaway container..."
# Git Bash on Windows: pass a Windows path to docker and keep /work unconverted.
HOST_WORK="$(cygpath -m "${WORK}" 2>/dev/null || echo "${WORK}")"
MSYS_NO_PATHCONV=1 docker run --rm -e HOST_IDS="$(id -u):$(id -g)" \
    -v "${HOST_WORK}:/work" -w /work python:3.12-slim sh -c '
  apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends libgomp1 >/dev/null 2>&1 &&
  pip install -q --disable-pip-version-check --root-user-action=ignore \
    paddlepaddle==3.0.0 paddle2onnx==2.1.0 setuptools packaging "numpy<3" opencv-python-headless pyyaml &&
  python -B export_th_ocr.py /work/rec /work/onnx
  status=$?
  chown -R "${HOST_IDS}" /work 2>/dev/null || true
  exit "${status}"'
cp "${WORK}/onnx/th_ppocrv5_mobile_rec.onnx" "${WORK}/onnx/th_ppocrv5_mobile_rec_dict.txt" "${OUT}/"

cat > "${OUT}/licenses/PLATE-NOTICE.txt" <<EOF
Plate models bundled with Smart Campus (ACR-VEH-01 D4, VEH-008). Each keeps its upstream licence.

license_plate_detection_lpd_yunet_2023mar.onnx  LPD-YuNet, Dong Xu (watrix.ai), Apache-2.0
  (LPD-YuNet-LICENSE.txt). Trained on Chinese plates (upstream README).
  https://github.com/opencv/opencv_zoo/tree/${ZOO_COMMIT}/models/license_plate_detection_yunet
th_ppocrv5_mobile_rec.onnx, th_ppocrv5_mobile_rec_dict.txt  th_PP-OCRv5_mobile_rec, PaddlePaddle,
  Apache-2.0 (PaddleOCR-LICENSE.txt). Converted to ONNX with paddle2onnx 2.1.0 from the
  upstream inference model; no change to the weights. The dictionary is copied from its
  inference.yml.
  https://huggingface.co/PaddlePaddle/th_PP-OCRv5_mobile_rec/tree/${HF_REVISION}

Training-data provenance is not fully documented upstream; see
docs/decisions/VEH-007-ALPR-LICENCE-CHECK.md.
EOF

(cd "${OUT}" && sha256sum license_plate_detection_lpd_yunet_2023mar.onnx \
  th_ppocrv5_mobile_rec.onnx th_ppocrv5_mobile_rec_dict.txt > PLATE_MODELS.sha256)
echo "Plate models ready in apps/ai-service/models:"
cat "${OUT}/PLATE_MODELS.sha256"
