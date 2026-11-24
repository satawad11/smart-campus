# AI Node Pilot Deployment Package (Module 04)

This package deploys the edge AI perception runtime for the **AI Smart Campus Security & Access Platform** on a pilot edge computer (industrial PC, workstation, or edge gateway).

It reuses the existing, audited Module 04 perception implementation (**CAM-003 through CAM-016**) without modifying the architecture or creating secondary runtimes.

---

## 1. Package Structure

The node is installed from a **standalone kit** built on the vendor/build machine:

```bash
scripts/package-ai-node.sh <version>     # run from the repository root
# -> dist/ai-node-kit-<version>.tar.gz   (prebuilt image + scripts + SHA256SUMS)
```

Copy that single archive to the node and extract it. The node needs only Docker:
no source code, no internet access to rebuild. `install.sh` and `update.sh`
verify `SHA256SUMS` and `docker load` the bundled `ai-node-image.tar.gz`
(when run inside a full repository checkout without the image, they build from
`apps/ai-service` instead).

```text
ai-node-kit-<version>/
├── ai-node-image.tar.gz     # Prebuilt smart-campus/ai-node:pilot image (kit only)
├── VERSION, SHA256SUMS      # Kit version and checksums (kit only)
├── docker-compose.yml       # Production edge Docker Compose definition
├── .env.example             # Documented environment contract template (no secrets)
├── install.sh               # Host setup & image load (or build in a repo checkout)
├── start.sh                 # Service startup script
├── stop.sh                  # Graceful shutdown script
├── restart.sh               # Graceful restart script
├── enroll.sh                # One-time enrollment code -> UUID + token in .env (COM-03e)
├── update.sh                # Zero-downtime update script (preserves buffer)
├── upgrade.sh               # Move to this kit from the running one (keeps .env/certs/models)
├── healthcheck.sh           # Comprehensive 11-point diagnostic script
└── README.md                # This operational guide
```

---

## 2. Quick Start

### Step 1: Install Prerequisites
Ensure the host has:
- Ubuntu 22.04 / 24.04 LTS (recommended) or Debian 12
- Docker Engine 24.0+
- Docker Compose V2 plugin (`docker compose`)
- Outbound network access to the central campus backend (HTTPS/443) and camera network (RTSP/554)

### Step 2: Initialize Configuration
```bash
tar -xzf ai-node-kit-<version>.tar.gz
cd ai-node-kit-<version>
./install.sh
```
This generates a local `.env` file with restrictive permissions (`chmod 600`).

### Step 3: Enroll the Node (one-time code)
Set `BACKEND_BASE_URL` (and `MODEL_CODE` / `MODEL_VERSION`) in `.env`. In the web
console open the AI node -> credentials tab -> **Create enrollment code**, then on the node:
```bash
./enroll.sh ABCD-EFGH-JKMN
```
`enroll.sh` exchanges the code (single use, valid 30 minutes) for the node UUID and
token and writes both into `.env` (mode 600); the token is never displayed. Enrolling
revokes any earlier token of that node. Manual alternative: issue a credential in the
console and set `AI_NODE_UUID` / `AI_NODE_TOKEN` in `.env` yourself.

### Step 4: Start Services
```bash
./start.sh
```

### Step 5: Verify Health & Connectivity
```bash
./healthcheck.sh
```

### Updating to a new version
Update the campus server first, then the nodes. From the build machine (key-based
SSH to the node), one command per release:
```bash
scripts/push-ai-node-update.sh v1.2.0 ainode@10.10.20.102 [ainode@... more nodes]
scripts/push-ai-node-update.sh dist/ai-node-kit-v1.2.0-arm64.tar.gz ainode@10.10.20.102
```
A tag downloads the node's kit (matching its CPU) from the GitHub Release with `gh`;
a file path uses a locally built kit. The script verifies the `.sha256`, copies the
kit, extracts it next to the running one and runs its `./upgrade.sh`.

By hand on the node, the same thing:
```bash
tar -xzf ai-node-kit-<new>-arm64.tar.gz && cd ai-node-kit-<new>-arm64 && ./upgrade.sh
```
`upgrade.sh` verifies the kit, copies `.env` (mode 600), `certs/` and `models/` from
the kit the running container was started from (or `--from <dir>`), lists settings
new in `.env.example`, runs `./update.sh` and the health check. The node keeps its
UUID and token (no re-enrollment) and the offline event buffer volume is kept.
Rollback: run `./update.sh` in the previous kit folder (printed at the end, and
saved in `.previous-kit`). Delete the old folder once the new version is accepted.

---

## 3. Persistent Storage & Durability (CAM-012)

The deployment defines a dedicated Docker named volume:
```yaml
volumes:
  ai-node-data:
    name: smart-campus-ai-node-data
```
Mounted inside the container at `/data`.

### Durability Invariant
The durable SQLite event buffer (`/data/offline_events.db`) stores recognition events whenever the backend is temporarily unreachable.
- `docker compose stop` preserves pending events.
- `docker compose down` and `docker compose up` **PRESERVE** pending events.
- Container re-creation or host reboot preserves pending events.
- Events in the buffer retain their exact first-observed `event_uuid` and `occurred_at` timestamp.

---

## 4. Network Requirements

The AI Node requires **ZERO public inbound ports**. All network traffic is initiated outbound by the edge node:

| Destination | Protocol / Port | Purpose | Direction |
| :--- | :--- | :--- | :--- |
| **Backend API Gateway** | HTTPS (443) or HTTP (80/8000) | Registration, Heartbeat, Config sync, Candidate search, Event delivery, Health reporting | Outbound |
| **IP Cameras** | RTSP / TCP / UDP (554) | Live video streaming | Outbound |
| **DNS Server** | UDP / TCP (53) | Hostname resolution | Outbound |
| **NTP Server** | UDP (123) | System time synchronization (< 2.0s drift) | Outbound |

*Note: Port 8001 is bound locally (`127.0.0.1:8001`) on the edge host solely for local management inspection and container health checks.*

---

## 5. Face Models (ACR-AI-01)

With `AI_MODE=onnx` (the kit default) the node runs, through OpenCV:

- **Face Detector:** YuNet `face_detection_yunet_2023mar.onnx` (MIT).
- **Face Embedder:** SFace `face_recognition_sface_2021dec.onnx` (Apache-2.0); 128 values,
  L2-normalised and zero-padded to the platform's 512-d storage (cosine similarity unchanged).
- **Face Quality:** Standard sharpness and brightness evaluator (`StandardFaceQualityEvaluator`).
- **Face Liveness / PAD:** MiniFASNet V2 + V1SE (Apache-2.0), exported to ONNX. Without the
  models it fails closed (`UnavailableFaceLivenessDetector`).

The models are built into the image under `/app/models` with their licence notices
(`/app/models/licenses`); the node downloads nothing. `MODEL_CODE=yunet-sface`,
`MODEL_VERSION=2021dec` must match the model activated on the server
(`php artisan face:model-activate yunet-sface 2021dec`).

> [!IMPORTANT]
> Recognition thresholds default to the SFace operating points MATCH 0.45 / REVIEW 0.36
> (`docs/decisions/OWNER-DECISIONS-2026-11-23-CCTV-RECOGNITION.md`). Fine-tune them on your own
> cameras with the diagnostic below. Training-data provenance of the weights is undocumented
> upstream; see `docs/decisions/ACR-AI-01-FACE-MODELS.md` before any use beyond the institution's
> own pilot. `AI_MODE=mock` is synthetic and never recognises anyone.

### CCTV tuning and on-site check (`app.diagnose_camera`)

The node runs a CCTV-specific gate (`.env.example`, section 2): faces from ~24 px are recognised,
liveness is `advisory` (reported, a SPOOF still raises `LIVENESS_FAILED`, small faces are not
scored), a track that is not yet a MATCH is retried every `RECOGNITION_RETRY_SECONDS`, and
faces too poor to compare are not posted as "unknown person". Frames wider than
`DETECTOR_MAX_INPUT_WIDTH` (1280) are downscaled for detection only.

To see what the pipeline does with a real camera, without the backend, run on the node:

```bash
mkdir -p gallery && cp /path/to/reference-photos/*.jpg gallery/   # one photo per person, file name = label
docker compose run --rm -v "$PWD/gallery:/gallery:ro" -v "$PWD/diag:/diag" ai-node \
  python -m app.diagnose_camera --source 'rtsp://USER:PASS@CAMERA-IP:554/stream1' \
  --gallery /gallery --seconds 30 --out /diag
```

Each face prints its size, quality, liveness and the best gallery similarity (MATCH ≥ 0.45,
REVIEW ≥ 0.36); `diag/` gets annotated snapshots. No faces at all → check camera angle/zoom
(faces need ≥ ~24 px); mostly `REJECTED [FACE_TOO_SMALL]` → use the main stream or zoom in;
mostly `UNKNOWN` with similarities 0.30–0.40 → enrol a clearer, more frontal photo.

---

## 6. Diagnostic Verification (`healthcheck.sh`)

Running `./healthcheck.sh` performs an 11-point diagnostic check:
1. **Node Identity:** Validates `AI_NODE_UUID` syntax.
2. **Backend Connectivity:** Tests TCP handshake to `BACKEND_BASE_URL`.
3. **Credential Validity:** Validates bearer token against backend internal API.
4. **Heartbeat:** Tests heartbeat round-trip (`POST /internal/v1/ai-nodes/heartbeat`).
5. **Config Sync:** Verifies camera configuration delivery (`GET /internal/v1/ai-nodes/{uuid}/config`).
6. **Camera Reachability:** Tests TCP socket connectivity to assigned camera hosts.
7. **RTSP:** Validates RTSP video source readiness.
8. **Model Readiness:** Verifies model initialization and reports B5 status.
9. **Event Buffer:** Verifies SQLite database file read/write access.
10. **Disk Space:** Confirms sufficient storage capacity on `/data` volume.
11. **Time Synchronization:** Measures clock drift against backend HTTP Date header / NTP.

Outputs safe sanitized messages (never exposes passwords, tokens, or raw embeddings).
