# Owner Decision — 2026-11-16: lab recognition thresholds for SFace

> **Superseded 2026-11-23:** these values are now the defaults everywhere
> (`OWNER-DECISIONS-2026-11-23-CCTV-RECOGNITION.md`, R1). No `.env` lines are needed any more.

**How this was made:** in chat on 2026-11-16 the owner saw the lab's Live Monitor recognise almost nobody
(ALLOW 1, REVIEW 7, UNKNOWN 13 in one day). The agent explained that the FACE-021 thresholds (match 0.80, review 0.60)
were kept deliberately strict by ACR-AI-01 §5 and proposed lab values. The owner answered **"Approve for lab"**.

| Item | Decision |
|---|---|
| Scope | **Lab / pilot rehearsal only** (server 10.10.20.199). Not approved for real students or production. |
| Match threshold | `FACE_RECOGNITION_MATCH_THRESHOLD=0.45` |
| Review threshold | `FACE_RECOGNITION_REVIEW_THRESHOLD=0.36` (SFace's published cosine operating point is 0.363) |
| Where | Environment of that server (`apps/backend/.env`). `config/face.php` defaults are **not** changed, so every other install keeps 0.80 / 0.60. |
| Accepted risk | Higher chance of matching the wrong person than at 0.80. Liveness (MiniFASNet) still applies, and the server-side MATCH re-check (D5) uses these values. |

## Still required before real use
ACR-AI-01 "Evaluation before go-live" is unchanged: consenting volunteers (20–30), measured genuine-match and
false-match rates on the site cameras at several thresholds, then thresholds approved in writing with those numbers.
These lab values are a starting point for that evaluation, not its result.

## How to apply (server)
```bash
printf '\nFACE_RECOGNITION_MATCH_THRESHOLD=0.45\nFACE_RECOGNITION_REVIEW_THRESHOLD=0.36\n' >> apps/backend/.env
docker compose -f docker-compose.yml -f docker-compose.https.yml up -d backend queue scheduler websocket
```
To revert, delete the two lines and run the same `up -d`.
