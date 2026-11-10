"""Export the upstream MiniFASNet PyTorch weights to ONNX (ACR-AI-01).

Runs inside the throwaway container started by scripts/fetch-face-models.sh, never on the
campus host. Usage:

    python export_minifasnet.py <silent-face-source-dir> <output-dir>

The weights are loaded with ``weights_only=True`` (tensors only: a .pth file is a pickle,
and a full unpickle could run code). Each export is verified against PyTorch twice: with
ONNX's reference-free check (onnx.checker) and by running the exported file through OpenCV
DNN, the runtime the AI service uses. The script fails if the outputs differ.
"""

import os
import sys
from collections import OrderedDict

import cv2
import numpy as np
import onnx
import torch
import torch.nn.functional as F

SOURCE, OUT = sys.argv[1], sys.argv[2]
sys.path.insert(0, SOURCE)

from src.model_lib.MiniFASNet import MiniFASNetV1SE, MiniFASNetV2  # noqa: E402
from src.utility import get_kernel, parse_model_name  # noqa: E402

MODELS = {
    "2.7_80x80_MiniFASNetV2.pth": (MiniFASNetV2, "minifasnet_v2_2.7_80x80.onnx"),
    "4_0_0_80x80_MiniFASNetV1SE.pth": (MiniFASNetV1SE, "minifasnet_v1se_4.0_80x80.onnx"),
}
TOLERANCE = 1e-4

os.makedirs(OUT, exist_ok=True)
rng = np.random.default_rng(20261110)

for weights, (cls, onnx_name) in MODELS.items():
    h, w, _, scale = parse_model_name(weights)
    model = cls(conv6_kernel=get_kernel(h, w))
    state = torch.load(
        os.path.join(SOURCE, "resources", "anti_spoof_models", weights),
        map_location="cpu",
        weights_only=True,
    )
    if next(iter(state)).startswith("module."):
        state = OrderedDict((k[7:], v) for k, v in state.items())
    model.load_state_dict(state)
    model.eval()

    target = os.path.join(OUT, onnx_name)
    dummy = torch.zeros(1, 3, h, w)
    torch.onnx.export(
        model,
        dummy,
        target,
        input_names=["input"],
        output_names=["logits"],
        opset_version=11,
        dynamo=False,
    )
    onnx.checker.check_model(onnx.load(target))

    # Same input convention as upstream test.py: BGR uint8 crop, CHW float, no /255.
    net = cv2.dnn.readNetFromONNX(target)
    worst = 0.0
    for _ in range(8):
        crop = rng.integers(0, 256, size=(h, w, 3), dtype=np.uint8)
        tensor = torch.from_numpy(crop.transpose(2, 0, 1)).float().unsqueeze(0)
        with torch.no_grad():
            ref = F.softmax(model(tensor), dim=1).numpy()
        net.setInput(tensor.numpy())
        logits = net.forward()
        got = np.exp(logits - logits.max(axis=1, keepdims=True))
        got /= got.sum(axis=1, keepdims=True)
        worst = max(worst, float(np.abs(ref - got).max()))
    status = "OK" if worst <= TOLERANCE else "FAIL"
    print(f"{onnx_name}: scale={scale} input={h}x{w} max_softmax_diff={worst:.2e} {status}")
    if worst > TOLERANCE:
        sys.exit(f"export of {weights} differs from PyTorch by {worst}")
