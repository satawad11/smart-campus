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
├── update.sh                # Zero-downtime update script (preserves buffer)
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

### Step 3: Configure Node Credentials
Edit `.env` and set:
```bash
BACKEND_BASE_URL=https://campus-backend.example.edu
AI_NODE_UUID=11111111-2222-3333-4444-555555555555
AI_NODE_TOKEN=sc_node_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
```
*(Node credentials are created in the backend administrative interface under AI Nodes -> Manage Credentials).*

### Step 4: Start Services
```bash
./start.sh
```

### Step 5: Verify Health & Connectivity
```bash
./healthcheck.sh
```

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

## 5. Model Management & Blocker B5 Status

- **Face Detector:** ONNX Runtime (`ONNXFaceDetector`) or `MockFaceDetector`.
- **Face Embedder:** ONNX Runtime (`ONNXFaceEmbedder`, 512-d normalized vector) or `MockFaceEmbedder`.
- **Face Quality:** Standard sharpness and brightness evaluator (`StandardFaceQualityEvaluator`).
- **Face Liveness / PAD:** Fail-closed presentation attack detector (`UnavailableFaceLivenessDetector`).

> [!IMPORTANT]
> **Blocker B5 Status: HUMAN DECISION REQUIRED**
> In accordance with `TASKS.md` and pre-pilot audit finding `AUDIT-B5`, production model selection, licensing, and weights provisioning remain an unmade human decision. Live biometric access matching is on HOLD until approved model weights are placed in `./models/` and calibrated against `docs/FACE-021-PILOT-THRESHOLDS-REVIEW.md`.
> For initial network, streaming, and event buffering rehearsal, `AI_MODE=mock` is supported.

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
