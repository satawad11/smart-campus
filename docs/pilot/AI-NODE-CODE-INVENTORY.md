# AI Node Code Inventory (Module 04 & Foundation)

**Document:** `docs/pilot/AI-NODE-CODE-INVENTORY.md`  
**Purpose:** Authoritative code location inventory for existing Module 04 AI Node implementation and contracts.  
**Rule:** Reuses existing implementations from CAM-003 through CAM-016 without architecture redesign or technology replacement.

---

## 1. Executive Summary

The AI Node is an edge-deployed perception processing component. It connects to campus RTSP video streams, samples frames, detects faces, filters by region-of-interest (ROI), tracks faces across frames, extracts biometric embeddings, performs local quality/liveness checks, queries backend candidate recognition, delivers recognition events, durably buffers events during backend disconnects, and reports operational health.

All components for tasks CAM-003 through CAM-016 exist in the repository across two applications:
1. `apps/ai-service`: Python FastAPI/edge perception runtime (`app/`)
2. `apps/backend`: Laravel REST/internal service API (`app/Http/Controllers/Internal/`, `app/Services/Camera/`)

---

## 2. Code Inventory by Task ID

### CAM-003 — AI Node Registration & Heartbeat

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Backend API Route** | `apps/backend/routes/internal.php` | `POST /internal/v1/ai-nodes/register`<br>`POST /internal/v1/ai-nodes/heartbeat` | Machine-to-machine internal API protected by `ai_node.token` middleware. |
| **Backend Controller** | `apps/backend/app/Http/Controllers/Internal/AiNodeInternalController.php` | `register()`, `heartbeat()` | Ingests check-in (self-reported facts) and operational telemetry (CPU/RAM/GPU/queue). |
| **Backend Service** | `apps/backend/app/Services/Camera/AiNodeService.php` | `checkIn()`, `heartbeat()` | Updates `last_seen_at`, records `AiNodeHealthLog`, updates status without accepting client-asserted status. |
| **Backend Requests** | `apps/backend/app/Http/Requests/AiNode/Internal/CheckInAiNodeRequest.php`<br>`apps/backend/app/Http/Requests/AiNode/Internal/AiNodeHeartbeatRequest.php` | FormRequest validation rules | Enforces strict boundaries: prohibits client-supplied `node_code`, `status`, `site_uuid`. Bounds numeric ranges (0-100%). |
| **Backend Models** | `apps/backend/app/Models/AiNode.php`<br>`apps/backend/app/Models/AiNodeHealthLog.php` | Eloquent models | Entity and health log persistence. |
| **Backend Migration** | `apps/backend/database/migrations/2026_09_21_300003_create_ai_nodes_table.php`<br>`apps/backend/database/migrations/2026_09_21_300007_add_registration_fields_to_ai_nodes_table.php` | DB migrations | Schema for `ai_nodes` table including `hostname`, `ip_address`, `software_version`, `capabilities`. |
| **Backend Tests** | `apps/backend/tests/Feature/AiNodeApiTest.php` | `test_checkin_*`, `test_heartbeat_*` | Verifies check-in, heartbeat, status constraints, and telemetry logging. |

---

### CAM-004 — Per-Node Authentication

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Backend Middleware** | `apps/backend/app/Http/Middleware/RequireAiNodeToken.php` | `handle()` | Authenticates machine bearer token against SHA-256 hash in `ai_node_credentials`. Injects `resolved_ai_node`. |
| **Backend Service** | `apps/backend/app/Services/Camera/AiNodeCredentialService.php` | `issueCredential()`, `authenticateToken()`, `revokeCredential()` | Generates high-entropy token (`sc_node_...`), hashes with SHA-256, enforces single active credential per node. |
| **Backend Model** | `apps/backend/app/Models/AiNodeCredential.php` | Eloquent model | Holds `token_hash`, `prefix`, `last_used_at`, `revoked_at`. Never stores plaintext tokens. |
| **Backend Migration** | `apps/backend/database/migrations/2026_09_21_300008_create_ai_node_credentials_table.php` | DB migration | Schema for `ai_node_credentials` with index on `token_hash`. |
| **Backend Controllers** | `apps/backend/app/Http/Controllers/Api/AiNodeCredentialController.php`<br>`apps/backend/app/Http/Controllers/Api/AiNodeCredentialRevokeController.php` | `store()`, `index()`, `revoke()` | Administrative credential provisioning and rotation under `/api/v1/ai-nodes/{node}/credentials`. |
| **Node Client Auth** | `apps/ai-service/app/orchestration/backend_client.py`<br>`apps/ai-service/app/delivery/client.py`<br>`apps/ai-service/app/health/client.py` | `headers={"Authorization": f"Bearer {self._node_token}"}` | Injects bearer token into HTTP headers. Never logs token in cleartext. |
| **Backend Tests** | `apps/backend/tests/Feature/AiNodeCredentialApiTest.php`<br>`apps/backend/tests/Feature/InternalFaceRecognitionTest.php` | Feature tests | Verifies token rotation, revocation, invalid tokens, and non-impersonation. |

---

### CAM-005 — VideoSource Abstraction

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Abstract Interface** | `apps/ai-service/app/streams/base.py` | `VideoSource` | Provider-independent frame acquisition contract (`open()`, `read()`, `close()`, context manager). |
| **Schemas** | `apps/ai-service/app/streams/schemas.py` | `Frame`, `SourceState` | In-memory frame container: numpy array `data`, `width`, `height`, `captured_at`, `sequence_number`. |
| **Exceptions** | `apps/ai-service/app/streams/exceptions.py` | `VideoSourceError`, `VideoSourceConnectionError`, `VideoSourceReadError`, `VideoSourceNotOpenError`, `VideoSourceAlreadyOpenError`, `VideoSourceEOFError` | Normalized error hierarchy for stream operations. |
| **Mock Provider** | `apps/ai-service/app/streams/mock.py` | `MockVideoSource` | Deterministic synthetic frame source for load tests and pipeline verification without real hardware. |
| **Factory** | `apps/ai-service/app/streams/factory.py` | `get_video_source()` | Instantiates appropriate `VideoSource` based on URL or provider name. |
| **Tests** | `apps/ai-service/tests/test_streams.py` | Unit tests (25 tests) | Verifies lifecycle state machine, error handling, context management. |

---

### CAM-006 — RTSP Source and Reconnect

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **RTSP Implementation** | `apps/ai-service/app/streams/rtsp.py` | `RTSPVideoSource` | Real RTSP streaming via OpenCV FFMPEG backend. Bounded connection/read timeouts (`CAP_PROP_*_TIMEOUT_MSEC`). |
| **Reconnect Engine** | `apps/ai-service/app/streams/rtsp.py` | Exponential backoff with jitter in `read()` | Automatically self-heals transient network dropouts with backoff multiplier, max backoff ceiling, and jitter. |
| **URL Sanitization** | `apps/ai-service/app/streams/rtsp.py` | `mask_rtsp_url()` | Replaces `user:password@` in RTSP URLs with `***:***@` for safe logging and exceptions. |
| **Capture Protocol** | `apps/ai-service/app/streams/rtsp.py` | `CaptureLike` protocol | Structural typing wrapper enabling dependency injection of capture doubles in tests. |
| **Tests** | `apps/ai-service/tests/test_rtsp.py` | Unit tests (27 tests) | Verifies initial connect failure, transient reconnect, exponential backoff, url masking, clean close. |

---

### CAM-007 — Frame Sampler

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Implementation** | `apps/ai-service/app/sampling/sampler.py` | `FrameSampler` | Throttles high-FPS VideoSource to target processing rate (e.g. 5 FPS) using wall-clock timing. |
| **Schemas** | `apps/ai-service/app/sampling/schemas.py` | `SampledFrame` | Wraps `Frame` with `camera_uuid`, `sampled_sequence`, and `sampling_decision`. |
| **Backpressure** | `apps/ai-service/app/sampling/sampler.py` | Bounded `deque(maxlen=queue_size)` | Drops stale frames when full (`evicted_count`) rather than growing memory unboundedly. |
| **Tests** | `apps/ai-service/tests/test_sampling.py` | Unit tests (18 tests) | Verifies interval throttling, queue bounding, stale eviction, monotonic clock independence. |

---

### CAM-008 — Face Tracking

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Implementation** | `apps/ai-service/app/tracking/tracker.py` | `IoUTracker` | Greedy IoU-based multi-object face tracker associating detections across frames without heavy dependencies. |
| **Geometry** | `apps/ai-service/app/tracking/iou.py` | `compute_iou()` | Intersection-over-Union calculation for bounding boxes. |
| **Schemas** | `apps/ai-service/app/tracking/schemas.py` | `Track` | Ephemeral tracking state: `track_id`, `bbox`, `confidence`, `age`, `hits`, `missed_frames`. |
| **Lifecycle** | `apps/ai-service/app/tracking/tracker.py` | Match / Age / Expire | New detections create tracks; missed frames increment miss counter; drops tracks after `max_missed_frames`. |
| **Tests** | `apps/ai-service/tests/test_tracking.py` | Unit tests (21 tests) | Verifies deterministic greedy IoU matching, track creation, miss aging, track expiration. |

---

### CAM-009 — Recognition Worker Queue

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Worker Pipeline** | `apps/ai-service/app/orchestration/worker.py` | `RecognitionWorker` | Executes per-track recognition pipeline: alignment -> quality evaluation -> liveness -> embedding generation -> backend candidate search. |
| **Thread Pool** | `apps/ai-service/app/orchestration/worker.py` | `RecognitionWorkerPool` | Bounded thread pool with non-blocking queue (`queue_size`). Drops new tasks under backpressure. |
| **Backend Client** | `apps/ai-service/app/orchestration/backend_client.py` | `HttpRecognitionBackendClient` | Calls backend `POST /internal/v1/face/recognize` with embedding vector. Never sends raw image bytes. |
| **Backend Endpoint** | `apps/backend/app/Http/Controllers/Internal/FaceRecognitionInternalController.php` | `recognize()` | Searches pgvector index for candidates and evaluates against FACE-021 thresholds. |
| **Backend Service** | `apps/backend/app/Services/Face/InternalFaceRecognitionService.php` | `recognize()` | Wires `FaceEmbeddingRepository` and `FaceRecognitionClassificationService`. |
| **Schemas** | `apps/ai-service/app/orchestration/schemas.py` | `RecognitionWorkerResult`, `BackendRecognitionResponse` | Holds classification (`MATCH`, `REVIEW`, `UNKNOWN`), similarity score, candidate person UUID. |
| **Tests** | `apps/ai-service/tests/test_orchestration.py`<br>`apps/ai-service/tests/test_backend_client.py`<br>`apps/backend/tests/Feature/InternalFaceRecognitionTest.php` | Unit & Feature tests | Verifies pipeline execution, cache hits, quality rejection, timeout handling, and pgvector classification. |

---

### CAM-010 — Recognition Cache

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Implementation** | `apps/ai-service/app/caching/cache.py` | `RecognitionCache` | Prevents redundant biometric inferences on the same face track across consecutive frames. |
| **Key Invalidation** | `apps/ai-service/app/caching/cache.py` | `get()`, `put()`, `invalidate()` | Keys by `(camera_uuid, track_id)`. Invalidates on TTL expiry, track generation change, or model code/version switch. |
| **Tests** | `apps/ai-service/tests/test_caching.py` | Unit tests (17 tests) | Verifies cache hit/miss, TTL expiration, model change invalidation, stale-track reuse protection. |

---

### CAM-011 — Event Delivery

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Delivery Service** | `apps/ai-service/app/delivery/service.py` | `EventDeliveryService`, `to_event_payload()` | Converts `RecognitionWorkerResult` to `RecognitionEventPayload` and dispatches immediately to backend. |
| **HTTP Client** | `apps/ai-service/app/delivery/client.py` | `HttpEventDeliveryClient` | Dispatches `POST /internal/v1/events/recognition` with bearer token auth. |
| **Schemas** | `apps/ai-service/app/delivery/schemas.py` | `RecognitionEventPayload`, `DeliveryOutcome` | Schema matching backend ingestion contract: `event_uuid`, `camera_uuid`, `ai_node_uuid`, `occurred_at`, `classification`. |
| **Backend Ingestion** | `apps/backend/app/Http/Controllers/Internal/RecognitionEventInternalController.php` | `store()` | Ingests event into `access_events` table (Module 05 EVT-003). Enforces UUID idempotency. |
| **Backend Service** | `apps/backend/app/Services/Event/RecognitionEventIngestionService.php` | `ingest()` | Persists event, evaluates access policy, commits transaction before realtime WebSocket broadcast. |
| **Tests** | `apps/ai-service/tests/test_delivery.py`<br>`apps/backend/tests/Feature/RecognitionEventIngestionTest.php` | Unit & Feature tests | Verifies payload formatting, HTTP delivery, backend idempotency, deduplication. |

---

### CAM-012 — Offline Durable Buffer & Retry

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Durable Store** | `apps/ai-service/app/delivery/buffer.py` | `OfflineEventBuffer` | Embedded SQLite database storing `pending_events` table. Preserves `event_uuid` and original `occurred_at`. |
| **Buffered Service** | `apps/ai-service/app/delivery/buffered_service.py` | `BufferedEventDeliveryService` | Delivers immediately if backend available; catches network errors and enqueues to SQLite buffer without event loss. |
| **Retry Worker** | `apps/ai-service/app/delivery/buffered_service.py` | `retry_pending(limit=50)` | Drains buffered events with bounded exponential backoff and jitter. Deletes from SQLite only upon 2xx confirmation. |
| **Tests** | `apps/ai-service/tests/test_offline_buffer.py` | Unit tests (15 tests) | Verifies SQLite persistence, FIFO retry, backoff progression, duplicate suppression, process restart survival. |

---

### CAM-013 — Camera Health Collection

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Collector** | `apps/ai-service/app/health/collector.py` | `CameraHealthCollector` | Rolling-window telemetry accumulator: computes FPS, latency, dropped frames, resolution, reconnect delta. |
| **Frame Metrics** | `apps/ai-service/app/health/frame_metrics.py` | `compute_frame_metrics()` | Computes brightness (mean intensity) and blur score (Laplacian variance) on raw camera frames. |
| **HTTP Client** | `apps/ai-service/app/health/client.py` | `HttpCameraHealthClient` | Sends `POST /internal/v1/cameras/{cameraUuid}/health` with bearer token auth. |
| **Schemas** | `apps/ai-service/app/health/schemas.py` | `CameraHealthSample`, `FrameMetrics` | Telemetry payload. Never sends status string; backend derives `HealthStatus` independently. |
| **Backend Controller** | `apps/backend/app/Http/Controllers/Internal/CameraHealthInternalController.php` | `record()` | Resolves camera assignment and records health sample. |
| **Backend Service** | `apps/backend/app/Services/Camera/CameraHealthService.php` | `recordSample()` | Evaluates FPS/latency against thresholds and updates `cameras` health status. |
| **Backend Migration** | `apps/backend/database/migrations/2026_09_21_300005_create_camera_health_logs_table.php` | DB migration | Schema for `camera_health_logs` table. |
| **Tests** | `apps/ai-service/tests/test_health.py`<br>`apps/backend/tests/Feature/CameraHealthTest.php` | Unit & Feature tests | Verifies rolling-window metrics, FPS calculation, reconnect delta, backend status derivation. |

---

### CAM-014 — AI Node Health Collection

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Backend Ingestion** | `apps/backend/app/Http/Controllers/Internal/AiNodeInternalController.php` | `heartbeat()` | Ingests node telemetry: CPU, RAM, GPU, temperature, active cameras, queue depth. |
| **Backend Service** | `apps/backend/app/Services/Camera/AiNodeService.php` | `heartbeat()` | Evaluates telemetry against `config/ai_node.php` thresholds (`ONLINE` vs `DEGRADED`). |
| **Stale Detection** | `apps/backend/app/Console/Commands/DetectStaleAiNodesCommand.php` | `ai-nodes:detect-stale` | Scheduled command that transitions nodes with no heartbeat > 90s to `OFFLINE`. |
| **Backend Migration** | `apps/backend/database/migrations/2026_09_21_300006_create_ai_node_health_logs_table.php` | DB migration | Schema for `ai_node_health_logs` table. |
| **Backend Config** | `apps/backend/config/ai_node.php` | Health threshold settings | Sets `degraded_cpu_usage` (90%), `degraded_ram_usage` (90%), `stale_after_seconds` (90s). |
| **Backend Tests** | `apps/backend/tests/Feature/AiNodeHealthTest.php` | Feature tests (10 tests) | Verifies `ONLINE` / `DEGRADED` / `OFFLINE` state transitions and history pagination. |

---

### CAM-015 — Camera Configuration Sync

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Backend Route** | `apps/backend/routes/internal.php` | `GET /internal/v1/ai-nodes/{aiNodeUuid}/config` | Secure endpoint gated by `ai_node.token`. |
| **Backend Controller** | `apps/backend/app/Http/Controllers/Internal/AiNodeConfigInternalController.php` | `show()` | Asserts node matches credential (`assertOwnConfig`), dispatches config payload. |
| **Backend Service** | `apps/backend/app/Services/Camera/AiNodeConfigService.php` | `getConfig()`, `assertOwnConfig()` | Resolves cameras assigned to node; decrypts `rtsp_url_encrypted`, `username_encrypted`, `password_encrypted`. |
| **Backend Model** | `apps/backend/app/Models/Camera.php` | Eloquent model | Holds encrypted RTSP credentials and camera flags (`face_detection_enabled`, `roi_polygon`). |
| **Backend Tests** | `apps/backend/tests/Feature/AiNodeConfigTest.php` | Feature tests (6 tests) | Verifies token requirement, decrypted credential delivery, own-node isolation, and toggle flags. |

---

### CAM-016 — Region of Interest (ROI)

| Layer | File Path | Component / Symbols | Responsibility |
| :--- | :--- | :--- | :--- |
| **Filter Implementation** | `apps/ai-service/app/roi/filter.py` | `point_in_polygon()`, `is_detection_in_roi()` | Ray-casting point-in-polygon test against normalized polygon coordinates. |
| **Schemas** | `apps/ai-service/app/roi/schemas.py` | `RegionOfInterest`, `PolygonPoint` | Defines polygon vertex container with validation and point conversion. |
| **Backend Migration** | `apps/backend/database/migrations/2026_09_22_300009_add_roi_polygon_to_cameras_table.php` | DB migration | Adds `roi_polygon` JSON column to `cameras` table. |
| **Backend Controller/Resource**| `apps/backend/app/Http/Resources/CameraResource.php` | `roi` serialization | Exposes polygon points to administrative UI and internal config sync. |
| **Tests** | `apps/ai-service/tests/test_roi.py`<br>`apps/backend/tests/Feature/CameraRoiTest.php` | Unit & Feature tests | Verifies inside/outside/boundary point classification, empty ROI passthrough, API serialization. |

---

## 3. Foundation Components Reused by AI Node

| Component | File Path | Purpose |
| :--- | :--- | :--- |
| **Face Detection** | `apps/ai-service/app/detection/factory.py`<br>`apps/ai-service/app/detection/onnx_detector.py`<br>`apps/ai-service/app/detection/mock.py` | Face detection abstraction and ONNX/mock providers. |
| **Face Alignment** | `apps/ai-service/app/alignment/factory.py`<br>`apps/ai-service/app/alignment/standard.py`<br>`apps/ai-service/app/alignment/mock.py` | 5-point landmark affine transformation. |
| **Face Quality** | `apps/ai-service/app/quality/factory.py`<br>`apps/ai-service/app/quality/standard.py`<br>`apps/ai-service/app/quality/mock.py` | Sharpness, brightness, and pose angle scoring. |
| **Face Embedding** | `apps/ai-service/app/embeddings/factory.py`<br>`apps/ai-service/app/embeddings/onnx_embedder.py`<br>`apps/ai-service/app/embeddings/mock.py` | 512-d normalized biometric vector generation. |
| **Liveness / PAD** | `apps/ai-service/app/liveness/factory.py`<br>`apps/ai-service/app/liveness/unavailable.py`<br>`apps/ai-service/app/liveness/mock.py` | Fail-closed presentation attack detection interface. |
| **Configuration** | `apps/ai-service/app/core/config.py` | Pydantic settings loading from environment. |
| **Health Probes** | `apps/ai-service/app/core/health.py` | Readiness and liveness checkers for HTTP server. |
| **FastAPI App** | `apps/ai-service/app/main.py` | HTTP server providing local health inspection. |

---

## 4. Architectural Boundaries Confirmed

1. **No Actuation:** The AI node contains no relay or door actuation code (`PROJECT_RULES.md §5`).
2. **No Decision Authority:** The AI node never issues `GRANT` / `DENY` access decisions. It only asserts perception candidate classification (`MATCH`, `REVIEW`, `UNKNOWN`) and similarity scores. The backend event engine (`apps/backend/app/Services/Event/`) decides policy.
3. **Privacy & Security:**
   - Plaintext credentials and bearer tokens are masked in logs and exception messages.
   - Raw video frames and full-resolution images are never persisted to disk or transmitted to the backend.
   - Biometric vectors remain in volatile memory on the edge and are transmitted only over authenticated internal machine-to-machine HTTPS.
4. **Future Modules Locked:** No door control, visitor, vehicle/ALPR, or AI assistant components are introduced.
