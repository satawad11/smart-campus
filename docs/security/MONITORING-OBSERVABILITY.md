# Monitoring, Logging & Observability Architecture (SEC-013)

## 1. Metrics Collection
The platform operates a three-tier monitoring topology:
1. **Core Backend & Ingestion**:
   - HTTP response latency & status code distributions (`2xx`, `4xx`, `5xx`).
   - Queue depth and latency for asynchronous jobs (`event:process`, `reports:export`, `backup:run`).
   - Database connection pool utilization, query response time, pgvector search latency.
   - Redis memory footprint and connection counts.
2. **Edge Hardware & Camera Ingestion**:
   - RTSP stream status (`ONLINE`, `OFFLINE`, reconnect frequency).
   - Frame processing rate (FPS) and recognition pipeline inference latency.
   - Hardware metrics: GPU VRAM utilization, CPU load, ambient temperature.
3. **Security Telemetry**:
   - Failed authentication attempts and rate-limit triggers.
   - Unauthorized access attempts by role/scope.
   - Liveness spoof detection rates.

## 2. Centralized Logging Standards
- **Format**: Structured JSON via Monolog to `stdout` and persistent rotated log files (`storage/logs/laravel.log`).
- **Context Injection**:
  - `request_id`: Traced end-to-end via `X-Request-ID`.
  - `user_id`: Authenticated actor ID.
  - `ip`: Client IP address (sanitized from trusted proxies).
- **Redaction**: Passwords, tokens, biometric embeddings, and secrets are strictly stripped by `AuditService` and Monolog formatters before storage.

## 3. Alert Routing & Thresholds
| Alert Condition | Severity | Threshold | Action |
|---|---|---|---|
| PostgreSQL Unavailable | CRITICAL | Instantaneous failure | High-priority paging, incident auto-creation |
| Redis Cluster Down | CRITICAL | Instantaneous failure | High-priority paging |
| Camera Stream Offline | WARNING | > 2 minutes no frames | Officer alert triage dashboard |
| AI Edge Node Down | CRITICAL | Heartbeat stale > 60s | System alert generated, fallback node routed |
| Failed Backup Job | CRITICAL | Any backup job failure | Email/PagerDuty dispatch to DevOps |
| Login Failure Surge | WARNING | > 5 attempts on single IP in 60s | Temporary IP throttle |
