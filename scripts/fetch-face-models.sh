#!/usr/bin/env bash
# Fetch and prepare the production face models (ACR-AI-01) into apps/ai-service/models/.
#
# Run on the vendor/build machine before building the ai-service / AI node images.
# Every upstream file is pinned to a commit and a SHA-256; any mismatch aborts.
#   YuNet  (face detection, MIT)          opencv/opencv_zoo
#   SFace  (face embedding, Apache-2.0)   opencv/opencv_zoo
#   MiniFASNet V2 + V1SE (PAD, Apache-2.0) minivision-ai/Silent-Face-Anti-Spoofing,
#     exported to ONNX in a throwaway Docker container (torch never runs on this host)
#     and verified against PyTorch through OpenCV DNN.
# Licence notices are copied next to the models. The model files are not committed to git.
#
# Usage: scripts/fetch-face-models.sh            (needs curl, sha256sum, docker)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${ROOT}/apps/ai-service/models"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

ZOO_COMMIT=f12e12798e8314f7c074a6656816c048dcc95b7a
FAS_COMMIT=b6d5f04ad78778917853b25c778acef6d5626d15
ZOO_LFS="https://media.githubusercontent.com/media/opencv/opencv_zoo/${ZOO_COMMIT}/models"
ZOO_RAW="https://raw.githubusercontent.com/opencv/opencv_zoo/${ZOO_COMMIT}/models"

fetch() { # fetch <url> <dest> [sha256]
  curl -sSfL --retry 3 -o "$2" "$1"
  if [ -n "${3:-}" ]; then
    echo "$3  $2" | sha256sum -c --quiet - || { echo "ERROR: checksum mismatch for $1" >&2; exit 1; }
  fi
}

mkdir -p "${OUT}/licenses"
echo "Fetching YuNet and SFace (opencv_zoo ${ZOO_COMMIT:0:7})..."
fetch "${ZOO_LFS}/face_detection_yunet/face_detection_yunet_2023mar.onnx" \
  "${OUT}/face_detection_yunet_2023mar.onnx" 8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4
fetch "${ZOO_LFS}/face_recognition_sface/face_recognition_sface_2021dec.onnx" \
  "${OUT}/face_recognition_sface_2021dec.onnx" 0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79
fetch "${ZOO_RAW}/face_detection_yunet/LICENSE" "${OUT}/licenses/YuNet-LICENSE.txt"
fetch "${ZOO_RAW}/face_recognition_sface/LICENSE" "${OUT}/licenses/SFace-LICENSE.txt"

echo "Fetching MiniFASNet (Silent-Face-Anti-Spoofing ${FAS_COMMIT:0:7})..."
fetch "https://codeload.github.com/minivision-ai/Silent-Face-Anti-Spoofing/tar.gz/${FAS_COMMIT}" "${WORK}/fas.tar.gz"
mkdir -p "${WORK}/fas" && tar -xzf "${WORK}/fas.tar.gz" -C "${WORK}/fas" --strip-components=1
(cd "${WORK}/fas/resources/anti_spoof_models" && sha256sum -c --quiet - <<'EOF'
a5eb02e1843f19b5386b953cc4c9f011c3f985d0ee2bb9819eea9a142099bec0  2.7_80x80_MiniFASNetV2.pth
84ee1d37d96894d5e82de5a57df044ef80a58be2b218b5ed7cdfd875ec2f5990  4_0_0_80x80_MiniFASNetV1SE.pth
EOF
) || { echo "ERROR: MiniFASNet weight checksum mismatch" >&2; exit 1; }
cp "${WORK}/fas/LICENSE" "${OUT}/licenses/MiniFASNet-LICENSE.txt"
cp "${ROOT}/scripts/face-models/export_minifasnet.py" "${WORK}/"

echo "Exporting MiniFASNet to ONNX in a throwaway container..."
# Git Bash on Windows: pass a Windows path to docker and keep /work unconverted.
HOST_WORK="$(cygpath -m "${WORK}" 2>/dev/null || echo "${WORK}")"
# The container runs as root: hand everything it wrote back to the caller, or the EXIT
# trap cannot delete it on a Linux host (Docker Desktop on Windows hides this).
MSYS_NO_PATHCONV=1 docker run --rm -e HOST_IDS="$(id -u):$(id -g)" \
    -v "${HOST_WORK}:/work" -w /work python:3.12-slim sh -c '
  pip install -q --disable-pip-version-check --root-user-action=ignore \
    torch --index-url https://download.pytorch.org/whl/cpu &&
  pip install -q --disable-pip-version-check --root-user-action=ignore \
    onnx "numpy<3" opencv-python-headless &&
  python -B export_minifasnet.py /work/fas /work/onnx
  status=$?
  chown -R "${HOST_IDS}" /work 2>/dev/null || true
  exit "${status}"'
cp "${WORK}"/onnx/*.onnx "${OUT}/"

cat > "${OUT}/licenses/NOTICE.txt" <<EOF
Face models bundled with Smart Campus (ACR-AI-01). Each model keeps its upstream licence.

face_detection_yunet_2023mar.onnx  YuNet, Shiqi Yu, MIT (YuNet-LICENSE.txt)
  https://github.com/opencv/opencv_zoo/tree/${ZOO_COMMIT}/models/face_detection_yunet
face_recognition_sface_2021dec.onnx  SFace, Apache-2.0 (SFace-LICENSE.txt)
  https://github.com/opencv/opencv_zoo/tree/${ZOO_COMMIT}/models/face_recognition_sface
minifasnet_v2_2.7_80x80.onnx, minifasnet_v1se_4.0_80x80.onnx  MiniFASNet, Minivision,
  Apache-2.0 (MiniFASNet-LICENSE.txt). Converted to ONNX from the upstream .pth weights;
  no change to the weights.
  https://github.com/minivision-ai/Silent-Face-Anti-Spoofing/tree/${FAS_COMMIT}

Training-data provenance is not documented upstream for any of these weights; see
docs/decisions/ACR-AI-01-FACE-MODELS.md before any use beyond the institution's own pilot.
EOF

(cd "${OUT}" && sha256sum ./*.onnx > MODELS.sha256)
echo "Models ready in apps/ai-service/models:"
cat "${OUT}/MODELS.sha256"
