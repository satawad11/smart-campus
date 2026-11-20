"""Convert PaddleOCR th_PP-OCRv5_mobile_rec to ONNX and check it (VEH-008, ACR-VEH-01 D4).

Runs inside the throwaway container started by scripts/fetch-plate-models.sh, never on the
host. Steps:
1. paddle2onnx: inference.json + inference.pdiparams -> th_ppocrv5_mobile_rec.onnx (weights
   unchanged);
2. write the CTC dictionary from inference.yml (one character per line; the node adds the
   blank and the space classes, as PaddleOCR's CTCLabelDecode does);
3. run Paddle and the ONNX model through OpenCV DNN (what the node uses) on the same inputs
   and fail if the outputs differ.

Usage: python export_th_ocr.py <paddle model dir> <output dir>
"""

import subprocess
import sys
from pathlib import Path

import cv2
import numpy as np
import paddle.inference as pi
import yaml

src = Path(sys.argv[1])
out = Path(sys.argv[2])
out.mkdir(parents=True, exist_ok=True)
onnx_path = out / "th_ppocrv5_mobile_rec.onnx"

subprocess.run(
    [
        "paddle2onnx",
        "--model_dir", str(src),
        "--model_filename", "inference.json",
        "--params_filename", "inference.pdiparams",
        "--save_file", str(onnx_path),
        "--opset_version", "14",
    ],
    check=True,
)

meta = yaml.safe_load((src / "inference.yml").read_text(encoding="utf-8"))
chars = [str(c) for c in meta["PostProcess"]["character_dict"]]
assert meta["PostProcess"]["name"] == "CTCLabelDecode", meta["PostProcess"]["name"]
assert all(len(c) == 1 and c not in ("\n", "\r") for c in chars), "unexpected dictionary entry"
(out / "th_ppocrv5_mobile_rec_dict.txt").write_text("\n".join(chars) + "\n", encoding="utf-8")

config = pi.Config(str(src / "inference.json"), str(src / "inference.pdiparams"))
config.disable_gpu()
predictor = pi.create_predictor(config)
net = cv2.dnn.readNet(str(onnx_path))

rng = np.random.default_rng(7)
worst = 0.0
for width in (160, 320):
    x = rng.uniform(-1.0, 1.0, size=(1, 3, 48, width)).astype(np.float32)
    handle = predictor.get_input_handle(predictor.get_input_names()[0])
    handle.reshape(x.shape)
    handle.copy_from_cpu(x)
    predictor.run()
    ref = predictor.get_output_handle(predictor.get_output_names()[0]).copy_to_cpu()
    net.setInput(x)
    got = net.forward()
    assert got.shape == ref.shape, (got.shape, ref.shape)
    assert ref.shape[-1] == len(chars) + 2, (ref.shape, len(chars))
    worst = max(worst, float(np.abs(got - ref).max()))

print(f"classes={len(chars) + 2} max_abs_diff={worst:.2e}")
if worst > 1e-3:
    sys.exit("ERROR: ONNX output differs from Paddle")
