# MinIO / S3-compatible object storage (FND-008)

Development object storage runs as the `minio` service in `docker-compose.yml`
(S3 API on `:9000`, console on `:9001`). Credentials come from
`MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD` (see `.env.example` — dev defaults
only, no secrets committed).

## Buckets

The approved buckets (development blueprint §8) are listed in
[`buckets.txt`](./buckets.txt):

- `person-photos`
- `face-samples`
- `event-snapshots`
- `report-exports`

Face samples and biometric data are Highly Sensitive (PROJECT_RULES.md §10):
buckets are private; access uses authorization / signed URLs, never a public
bucket. Media upload features are out of scope for FND-008.

## Provisioning buckets (development)

With the stack running, create the buckets idempotently:

```bash
bash scripts/provision-minio.sh
```

The script uses the `minio/mc` client and reads bucket names from
`buckets.txt`. It is safe to re-run.

## Laravel wiring

`config/filesystems.php` defines one S3 disk per bucket (`person_photos`,
`face_samples`, `event_snapshots`, `report_exports`) plus the generic `s3`
disk, all pointing at the MinIO endpoint with path-style addressing. Bucket
names are overridable via `S3_BUCKET_*` env vars.
