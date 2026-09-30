# AI Node Pilot Installation Guide

**Document:** `docs/pilot/AI-NODE-INSTALLATION-GUIDE.md`  
**Target Host:** Pilot Edge Computer / Workstation (Module 04 AI Node)  
**Pilot Boundary:** 1 Site, 1 Gate, 2 Cameras, 1 AI Node  
**Prerequisites:** Fresh installation of Ubuntu 22.04 LTS or Ubuntu 24.04 LTS

---

## 1. Prerequisites & Host Preparation

### Step 1.1: System Updates & Baseline Packages
Log in to the edge computer via console or SSH and install required host utilities:
```bash
sudo apt-get update && sudo apt-get upgrade -y
sudo apt-get install -y ca-certificates curl gnupg lsb-release systemd-timesyncd git
```

### Step 1.2: System Time & NTP Synchronization
Clock synchronization is critical (`ACCEPTANCE_CRITERIA.md § Module 04`). Clock drift between the AI Node and Backend must be under 2.0 seconds:
```bash
# Enable systemd NTP synchronization
sudo timedatectl set-ntp true
sudo systemctl restart systemd-timesyncd

# Verify synchronization
timedatectl status
```
*Confirm `NTP service: active` and `System clock synchronized: yes`.*

### Step 1.3: Install Docker Engine & Compose V2
Install official Docker packages:
```bash
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Add operator user to docker group (optional, requires re-login)
sudo usermod -aG docker "$USER"
```

---

## 2. Package Deployment & Secret Configuration

### Step 2.1: Clone Repository / Deploy Package
Clone the repository or transfer the AI Node deployment package to the target host:
```bash
# Example directory setup
mkdir -p ~/smart-campus
cd ~/smart-campus
git clone <repository-url> .
cd deploy/ai-node
```

### Step 2.2: Run Installer
Run the package installer to prepare directories and configuration:
```bash
chmod +x *.sh
./install.sh
```

### Step 2.3: Node Registration & Credential Provisioning in Backend
On the central backend administrative console:
1. Log in as an administrator with `ai_node.manage` permission.
2. Navigate to **Infrastructure -> AI Nodes**.
3. Create a new AI Node record:
   - **Code:** `AINODE-PILOT-01`
   - **Name:** `Main Gate Perception Node`
   - **Site:** Select Pilot Campus Site (`SITE-PILOT`)
4. Copy the generated **AI Node UUID** (e.g., `8fa37260-149b-449e-b1df-4b77f9892c90`).
5. Click **Issue Credential** (or call `POST /api/v1/ai-nodes/{uuid}/credentials`):
   - Copy the generated plaintext secret token (e.g., `sc_node_7f8a9b0c...`).
   *Note: This plaintext token is displayed only once. Backend stores only a salted SHA-256 hash.*

### Step 2.4: Configure Edge `.env` File
Edit `deploy/ai-node/.env` on the edge host:
```bash
nano .env
```
Populate the real backend connection parameters:
```env
BACKEND_BASE_URL=https://campus-backend.example.edu
AI_NODE_UUID=8fa37260-149b-449e-b1df-4b77f9892c90
AI_NODE_TOKEN=sc_node_7f8a9b0c...
AI_MODE=mock
EVENT_BUFFER_PATH=/data/offline_events.db
LOG_LEVEL=INFO
TARGET_FPS=5.0
```
Save and secure permissions:
```bash
chmod 600 .env
```

---

## 3. Model Installation & Blocker B5 Verification

In accordance with Phase 08 governance and audit finding `AUDIT-B5`:
- **Current Status:** `HUMAN DECISION REQUIRED (HOLD on live biometrics)`.
- **Software Rehearsal:** Set `AI_MODE=mock`. The AI Node uses deterministic mock models matching all Module 03/04 contracts.
- **Production Models (when human decision is resolved):**
  1. Place approved detector ONNX model into `deploy/ai-node/models/detector.onnx`.
  2. Place approved embedding ONNX model into `deploy/ai-node/models/embedder.onnx`.
  3. Verify SHA-256 checksums match registry entries.
  4. In `.env`, set:
     ```env
     AI_MODE=onnx
     DETECTOR_MODEL_PATH=/models/detector.onnx
     EMBEDDER_MODEL_PATH=/models/embedder.onnx
     ```

---

## 4. Service Startup & Telemetry Verification

### Step 4.1: Start Services
Start the AI Node container:
```bash
./start.sh
```

### Step 4.2: Inspect Logs
Verify container startup and initial check-in:
```bash
docker compose logs -f ai-node
```
Expected log entries:
```text
[INFO] Starting AI Node Orchestrator for node 8fa37260-...
[INFO] Check-in confirmed with backend for node 8fa37260-...
[INFO] AI Node Orchestrator started successfully.
```

### Step 4.3: Verify Backend Heartbeat
In the administrative web console:
1. Navigate to **Infrastructure -> AI Nodes**.
2. Locate `AINODE-PILOT-01`.
3. Verify status indicates `ONLINE` with active telemetry (CPU %, RAM %, active cameras).

---

## 5. Camera Assignment & RTSP Verification

### Step 5.1: Assign Cameras to AI Node in Backend
In the central administrative UI (**Infrastructure -> Cameras**):
1. Select or create Pilot Camera 1 (`CAM-PILOT-01` - East Ingress Gate).
2. Set **AI Node** to `AINODE-PILOT-01`.
3. Set **RTSP URL**, **Username**, and **Password** (stored encrypted in PostgreSQL).
4. Set **Face Detection** = Enabled.
5. (Optional) Configure **Region of Interest (ROI)** polygon points around the pedestrian lane.
6. Set **Status** = `ACTIVE`.
7. Repeat for Pilot Camera 2 (`CAM-PILOT-02` - West Ingress Gate).

### Step 5.2: Verify Automatic Config Sync
Within 30 seconds (`CONFIG_SYNC_INTERVAL_SECONDS`), the edge orchestrator polls `GET /internal/v1/ai-nodes/{uuid}/config`:
Check edge logs:
```bash
docker compose logs --tail=50 ai-node
```
Expected output:
```text
[INFO] Camera pipeline started for CAM-PILOT-01 (rtsp://***:***@192.168.10.50:554/stream1)
[INFO] Camera pipeline started for CAM-PILOT-02 (rtsp://***:***@192.168.10.51:554/stream1)
```
*Note: Credentials in RTSP URLs are masked automatically as `***:***` in all logs.*

---

## 6. Installation Verification (11-Point Health Check)

Run the comprehensive installation verification script:
```bash
./healthcheck.sh
```

### Expected Output
```text
============================================================
 AI NODE PILOT INSTALLATION VERIFICATION REPORT
============================================================

 [PASS]     node_identity          : Valid node UUID (8fa37260...)
 [PASS]     backend_connectivity   : TCP connection established to campus-backend.example.edu:443
 [PASS]     credential_validity    : Token authenticated successfully by backend
 [PASS]     heartbeat              : Heartbeat accepted: Heartbeat recorded
 [PASS]     config_sync            : Configuration synced successfully (2 cameras assigned)
 [PASS]     camera_reachability    : 2/2 camera hosts reachable via network
 [PASS]     rtsp                   : 2/2 RTSP sources ready
 [WARNING]  model_readiness        : AI_MODE=mock active. Mock models ready for testing, but production biometrics HOLD (Blocker B5)
 [PASS]     event_buffer           : SQLite buffer writable at /data/offline_events.db
 [PASS]     disk                   : Sufficient disk space available: 42150.4 MB free
 [PASS]     time_synchronization   : Clock synchronized with backend (drift ~0.08s)

------------------------------------------------------------
 OVERALL STATUS: WARNING (Pilot operational with caveats/mock)
------------------------------------------------------------
```

---

## 7. Recognition & Event Ingestion Smoke Test

### Step 7.1: Live Stream Face Detection Smoke Test
1. Have a test operator step into the field of view of `CAM-PILOT-01`.
2. Inspect AI Node logs:
   ```bash
   docker compose logs -f ai-node
   ```
3. Verify frame sampling, tracking, and event dispatch:
   ```text
   [DEBUG] Track track-1 created
   [DEBUG] Recognition result: MATCH person-uuid=... similarity=0.92
   [INFO] Dispatched recognition event uuid=... to backend
   ```

### Step 7.2: Realtime Live Monitor Verification
1. Open the web interface at `/monitor` as a Security Officer.
2. Confirm the recognition event appears on the Live Monitor event stream within 200 ms.
3. Verify event details: Camera name, timestamp, student identity, and access decision.

---

## 8. Troubleshooting Guide

### 1. Backend Unavailable
- **Symptom:** `[FAIL] backend_connectivity : Cannot connect to backend`
- **Cause:** Network firewall, incorrect DNS, or backend service stopped.
- **Resolution:**
  1. Test DNS resolution: `nslookup <backend-domain>`
  2. Test TCP handshake: `nc -zv <backend-host> 443`
  3. Verify backend gateway container status on the server host: `docker compose ps`

### 2. 401 Node Credential Rejected
- **Symptom:** `[FAIL] credential_validity : Authentication failed (HTTP 401 Unauthorized)`
- **Cause:** `AI_NODE_TOKEN` in `.env` is invalid, expired, or was rotated in the backend.
- **Resolution:**
  1. Generate a new token in the backend UI: **AI Nodes -> Manage Credentials -> Issue Credential**.
  2. Update `AI_NODE_TOKEN` in `.env`.
  3. Run `./restart.sh` and re-run `./healthcheck.sh`.

### 3. Camera Authentication Failure
- **Symptom:** Camera stream fails to connect or logs HTTP/RTSP 401.
- **Cause:** Incorrect RTSP credentials configured in backend.
- **Resolution:**
  1. Open camera configuration in backend web console (**Infrastructure -> Cameras**).
  2. Verify username and password against camera web administration portal.
  3. Re-save camera credentials in backend; the node will automatically receive updated decrypted credentials on the next config sync.

### 4. RTSP Timeout / Reconnecting Loop
- **Symptom:** Logs show `RTSPVideoSource reconnecting (attempt N)...`
- **Cause:** Camera offline, packet loss, RTSP port 554 blocked, or incorrect stream path.
- **Resolution:**
  1. Test TCP reachability to camera: `nc -zv <camera-ip> 554`
  2. Test stream using ffprobe or test utility:
     `docker compose exec ai-node python -c "import cv2; cap=cv2.VideoCapture('rtsp://user:pass@camera-ip:554/stream'); print('Opened:', cap.isOpened())"`
  3. Verify camera bitrate and codec (H.264 is recommended; H.265 requires compatible ffmpeg backend).

### 5. Model Unavailable / Not Ready
- **Symptom:** `[FAIL] model_readiness : ONNX models not ready`
- **Cause:** `AI_MODE=onnx` is enabled but ONNX model files are missing or corrupted.
- **Resolution:**
  1. Verify files exist in `deploy/ai-node/models/`.
  2. Check file permissions: `chmod 644 models/*.onnx`.
  3. Confirm model checksum against registry.
  4. For non-biometric rehearsal, set `AI_MODE=mock` in `.env`.

### 6. AI Service / Node Not Ready
- **Symptom:** Docker container exits or health check reports unhealthy.
- **Cause:** Environment variable missing or volume mount permissions issue.
- **Resolution:**
  1. Check Docker logs: `docker compose logs --tail=100 ai-node`
  2. Verify `.env` syntax (no stray characters or unquoted spaces).
  3. Check volume write access: `docker compose exec ai-node ls -ld /data`

### 7. Offline Buffer Growth
- **Symptom:** `buffer_pending_count` increases continuously; events not reaching backend.
- **Cause:** Backend ingestion endpoint (`/internal/v1/events/recognition`) returning non-2xx status or network outage.
- **Resolution:**
  1. Check backend logs: `docker compose logs -f backend`
  2. Check AI Node retry worker logs: `docker compose logs ai-node | grep "Offline buffer retry"`
  3. The buffer is durable and will never drop events. Once backend connectivity is restored, the retry worker drains buffered events with bounded backoff.

### 8. Clock / NTP Synchronization Problem
- **Symptom:** `[FAIL] time_synchronization : Clock drift exceeds threshold`
- **Cause:** Host NTP daemon (`systemd-timesyncd` or `chrony`) is stopped or blocked by firewall.
- **Resolution:**
  1. Check NTP service status: `systemctl status systemd-timesyncd`
  2. Ensure outbound UDP port 123 is permitted by edge network firewall.
  3. Manually force time sync: `sudo systemctl restart systemd-timesyncd`
