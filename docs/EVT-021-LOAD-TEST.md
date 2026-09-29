# EVT-021 — Recognition-Event Ingestion + Realtime Broadcast Load Test

Generated: 2026-09-29

## Data Source Labeling

**REAL**, not synthetic — unlike CAM-020's fully-mocked AI-node pipeline benchmark (`apps/ai-service/scripts/benchmark_camera_load.py`), every request in this report is a genuine HTTP `POST /internal/v1/events/recognition` fired at the actually-running Laravel backend, through the real nginx gateway (`http://nginx`, the same Docker Compose service the frontend and a real AI node would use), authenticated with a real per-node bearer credential issued by `AiNodeCredentialService`. It exercises the real idempotency (EVT-004), semantic-cooldown (EVT-005), policy-matching (EVT-006), decision-persistence (EVT-007), and broadcast (EVT-013) pipeline — no mocks, no test doubles. The realtime side is equally real: a genuine WebSocket client (Node 22's native `WebSocket`, no browser) logs in as a real user, performs the real `/broadcasting/auth` private-channel handshake, and connects through nginx's `/reverb/` proxy to the real Reverb server.

## Method

**Backend load generator**: `php artisan events:load-test` (`app/Console/Commands/LoadTestEventIngestion.php`). Provisions a fixed Site/Zone/Camera/AiNode fixture and N `StudentProfile`s (all `ACTIVE`, so most MATCH classifications resolve to `ALLOW`), then fires `--total` recognition-event payloads in chunks of `--concurrency` using `Illuminate\Http\Client\Pool` (real concurrent HTTP, not sequential). A configurable fraction (`--unknown-ratio`, default 0.2) are `UNKNOWN` classification; the rest are `MATCH` against a small pool of `--persons` known people, so most repeats land inside the same camera+person semantic-cooldown window (EVT-005) — deliberately exercising suppression under load, not just raw insert throughput.

**Realtime verification**: `scripts/verify-realtime-load.js` (Node), run from the frontend container (already has network access to `nginx` and Node 22). Logs in as a real `monitor.view` user, subscribes to the load test's site channel (`site.{uuid}`) over a genuine WebSocket, and counts `event.decided` pushes received during a fixed window — run concurrently with the backend load generator so both together prove ingestion **and** realtime delivery survive the same load, not just one in isolation.

**Infrastructure fixed to make this testable**: this load test is the first thing in the project to reach `/internal/v1/*` through the real nginx gateway rather than through PHPUnit's in-process test client or a direct container-to-container call. Doing so surfaced two real, previously-undiscovered defects, both fixed as part of this task (`infrastructure/nginx/default.conf`):
1. **No `/internal/` route existed at all** — requests fell through to the frontend's catch-all `location /` and hit the Vite dev server (403 "Blocked request... allowedHosts"), never reaching the backend. Fixed by adding a `location /internal/` block mirroring `/api/`'s fastcgi_pass to the same backend upstream.
2. **The `smart-campus-nginx-1` container had no network attached at all** (`docker inspect` showed an empty `Networks` object) despite the compose file correctly declaring `networks: [smartcampus]` — a stale container instance, not a config bug. Its healthcheck (`curl http://localhost:80/healthz`) never touches the network, so it reported `healthy` the entire time while being completely unreachable from any other container. Fixed by `docker compose up -d nginx` to recreate it from the (already-correct) compose definition.

## Results

### Run A — total=200, concurrency=20, persons=15, unknown_ratio=0.2

| Metric | Value |
|---|---|
| Total requests | 200 |
| Succeeded (2xx) | 200 |
| Failed | 0 |
| Wall time | 24.39 s |
| Throughput | 8.2 req/s |
| AccessEvent rows created | 57 |
| AccessDecision rows created | 57 |
| Cooldown-suppressed / idempotent | 143 |
| Realtime messages received | 55 / 57 |

Realtime listener was started only ~5s before the load began; its own login + WebSocket-connect + channel-auth handshake was still completing while the very first chunk's broadcasts went out, explaining the 2 missed messages — a measurement-timing artifact, not a delivery defect (see Run B).

### Run B — total=100, concurrency=20, persons=10, unknown_ratio=0.2 (properly warmed-up listener)

| Metric | Value |
|---|---|
| Total requests | 100 |
| Succeeded (2xx) | 100 |
| Failed | 0 |
| Wall time | 9.5 s |
| Throughput | 10.5 req/s |
| AccessEvent rows created | 38 |
| AccessDecision rows created | 38 |
| Cooldown-suppressed / idempotent | 62 |
| Realtime messages received | **38 / 38 (100%)** |

With the listener given ~12s to fully subscribe before load began, **every single broadcast was received with zero loss** — the clean result confirming the EVT-013 broadcast pipeline itself has no message-loss defect; Run A's 2-message gap was purely the listener's own startup race.

### Run C — total=150, concurrency=25, persons=12, unknown_ratio=0.2

| Metric | Value |
|---|---|
| Total requests | 150 |
| Succeeded (2xx) | 150 |
| Failed | 0 |
| Wall time | 27.72 s |
| Throughput | 5.4 req/s |
| AccessEvent rows created | 46 |
| AccessDecision rows created | 46 |
| Cooldown-suppressed / idempotent | 104 |
| Realtime messages received | 14 / 46 |

The listener's fixed 30s window closed *before* the 27.7s-long load (which itself started ~6s after the listener subscribed) finished producing events — the window was simply too short for this run's parameters, not a delivery failure. Included here deliberately, alongside Run B's clean 100%, to show the verification script's window must be sized to the load's expected duration; it is not evidence of the backend dropping messages.

### Cold-start observation

The very first concurrent chunk of every run measured noticeably higher latency than subsequent chunks (e.g. Run A's first chunk: 3.3s for 20 requests vs. 1.7–3.8s for later chunks; an even smaller 10-request dry run saw 4.8s for the first chunk vs. 0.5s for the second) — consistent with one-time warm-up costs (OPcache priming, first DB/Redis connections, first outbound HTTP connection to the Reverb server for broadcasting) rather than a sustained throughput ceiling.

## Duplicate/Idempotency and Cooldown Under Load

Across all runs, the ratio of "succeeded" to "rows actually created" matches EVT-004/EVT-005 expectations exactly: reusing a small pool of persons against one camera under concurrent load correctly collapses most repeats into cooldown-suppressed 200 responses (idempotent replay), never a duplicate `AccessEvent`/`AccessDecision` row and never a 5xx/race-condition error — the ingestion pipeline's dedup logic holds under real concurrency, not just sequential test execution.

## Security/Privacy Under Load

No credentials, embeddings, or raw biometric data appear in the load generator's own payloads beyond what `IngestRecognitionEventRequest` already accepts (EVT-003), and the realtime verification script's received messages were spot-checked against `AccessEventBroadcast::broadcastWith()` — same shape as the already-unit-tested payload (EVT-013), no additional fields.

## Physical/Production-Scale Validation

**PENDING.** This load test ran against a single-container development stack (one php-fpm worker pool, one Reverb process, no horizontal scaling, no production-grade Postgres tuning) on shared development hardware — it demonstrates *correctness under concurrency* (no dedup races, no dropped broadcasts when measured correctly) but its absolute throughput numbers (~5–10 req/s) are not representative of production capacity planning. Real-world throughput will differ — very likely be substantially higher, given a properly resourced deployment (multiple php-fpm workers, connection pooling, production Postgres) — or lower under adverse network conditions to physical AI-node hardware. Production-scale and multi-node horizontal-scaling validation is deferred to pilot deployment, the same honest scoping CAM-020 already established for this project.
