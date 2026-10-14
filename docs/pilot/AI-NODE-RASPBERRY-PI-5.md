# AI Node on Raspberry Pi 5 (pilot rehearsal)

Status: **rehearsal guide**. The ARM64 kit image is built and import-tested under
emulation on the build machine. It has not yet been tested on a physical Pi;
record the results of the first run in the pilot evidence log.

Read together with [AI-NODE-INSTALLATION-GUIDE.md](AI-NODE-INSTALLATION-GUIDE.md).
This page lists only what differs on a Raspberry Pi 5.

## Constraints

- **CPU architecture is ARM64.** Use a kit built with
  `AI_NODE_PLATFORM=linux/arm64` (its `ARCH` file reads `arm64`). `install.sh`
  refuses a kit built for another architecture.
- **64-bit OS only:** Raspberry Pi OS Lite (64-bit, Bookworm) or Ubuntu Server
  24.04 for Raspberry Pi. `uname -m` must print `aarch64`.
- **Cameras must be network (RTSP) cameras**, or `mock://` cameras for
  rehearsal. The node does not read the Pi camera module (CSI) or USB cameras.
- **Mock models only (Blocker B5).** `AI_MODE=mock`. Do not point the node at
  real people or real student data.
- Hardware: official 27 W power supply, active cooler, and preferably an SSD
  (NVMe HAT or USB 3) rather than the SD card for the offline event buffer.
- Performance of real ONNX models on the Pi 5 CPU is **unmeasured**; capacity
  per node must be measured once a model is approved.

## 1. Build the kit (build machine)

```bash
AI_NODE_PLATFORM=linux/arm64 scripts/package-ai-node.sh <version>
# -> dist/ai-node-kit-<version>-arm64.tar.gz
```

Cross-building runs under emulation and takes several minutes.

## 2. Prepare the Pi

```bash
sudo apt update && sudo apt full-upgrade -y
sudo timedatectl set-timezone Asia/Bangkok
timedatectl            # "System clock synchronized: yes"
uname -m               # aarch64
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"   # log out and back in
docker version && docker compose version
```

Check that the Pi reaches the backend before going further:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' <BACKEND_BASE_URL>/up
```

## 3. Register the node in the backend

As in the main guide (Step 2.3 and Section 5):

1. Create the AI node and issue a credential (the token is shown once).
2. Make sure the node's `MODEL_CODE` / `MODEL_VERSION` exist in the model
   registry. For rehearsal, use a mock entry such as `mock-face` / `0.0.0`.
3. Assign a camera: a real RTSP URL, or `mock://<name>` for a simulated stream.

## 4. Install

```bash
scp dist/ai-node-kit-<version>-arm64.tar.gz <user>@<pi>:~
# on the Pi
tar -xzf ai-node-kit-<version>-arm64.tar.gz
cd ai-node-kit-<version>-arm64
./install.sh
nano .env      # BACKEND_BASE_URL, AI_NODE_UUID, AI_NODE_TOKEN, MODEL_CODE, MODEL_VERSION
./start.sh
./healthcheck.sh
```

Transport security: a real site needs `https://`. For a lab backend that only
serves plain HTTP on the LAN, `healthcheck.sh` reports `backend_transport` as
FAIL unless `AI_NODE_ALLOW_INSECURE_BACKEND=true`. Set that only for a lab
rehearsal with mock cameras, because the node token travels unencrypted.

## 5. Accept

- `healthcheck.sh`: no FAIL. Expected WARNINGs: mock models, and plain HTTP in
  a lab.
- Backend: the node shows online with CPU, RAM and disk telemetry. Camera
  status is online, and recognition events appear in the live monitor.
- `docker stats smart-campus-ai-node`: note CPU, memory and temperature
  (`vcgencmd measure_temp`) after 30 minutes and record them.
- Reboot the Pi. The container restarts on its own (`restart: unless-stopped`)
  and heartbeats resume.
