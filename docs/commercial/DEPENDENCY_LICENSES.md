# Dependency Licence Audit (2026-10-14)

**Scope:** runtime dependencies that ship in the product. Gathered with `composer licenses --no-dev`, the frontend
`package-lock.json` (non-dev packages) and Python package metadata of the AI service runtime requirements.
**Not legal advice**: items marked REVIEW need counsel before commercial distribution.

## 1. Libraries
| Component | Packages | Licences | Result |
|---|---|---|---|
| Backend (PHP) | 96 | MIT 86, BSD-3 5, Apache-2.0 3, BSD-3/GPL-2.0/GPL-3.0 dual 2 (`nette/schema`, `nette/utils`) | OK: the nette packages are used under their BSD-3 option |
| Frontend bundle (npm) | 57 | MIT 53, BSD-2 1, BSD-3 1, ISC 1, Unlicense 1 | OK |
| AI service (Python) | 22 | MIT, BSD-3, Apache-2.0, PSF-2.0, MPL-2.0 (`certifi`), numpy (BSD-3 + bundled permissive) | OK; MPL-2.0 is file-level copyleft: fine when unmodified |
| AI service: `opencv-python-headless` | 1 | Apache-2.0 (OpenCV); binary wheels bundle third-party codecs (e.g. FFmpeg, LGPL) | **REVIEW**: keep the wheel's bundled licence notices in the distributed image |

## 2. Container images (shipped by the compose files)
| Image | Licence | Result |
|---|---|---|
| `pgvector/pgvector:pg16` | PostgreSQL licence + pgvector (PostgreSQL licence) | OK |
| `nginx` | BSD-2 | OK |
| `redis:7-alpine` | Redis ≥ 7.4 is RSALv2 / SSPLv1 (no longer BSD) | **REVIEW**: internal use by a customer is generally the intended case, but bundling in a sold product needs counsel; drop-in alternative **Valkey** (BSD-3) |
| `chrislusf/seaweedfs:4.52` (object storage, pinned by digest) | Apache-2.0 | OK. Replaced `minio/minio` (AGPL-3.0) in 2026-11 |
| `rclone/rclone:1.75.2` (one-off MinIO migration only, pinned by digest) | MIT | OK |
| `node:22-alpine` (build stage only) | MIT (Node) | OK (not in the runtime image) |

## 3. AI models
See [OWNER-DECISIONS-2026-10-14](../decisions/OWNER-DECISIONS-2026-10-14.md) (B5): InsightFace pretrained models are
non-commercial; YuNet MIT; SFace Apache-2.0; MiniFASNet weights' licence unstated.

## 4. Actions
1. Counsel review of Redis licensing for the commercial package (or switch to Valkey). MinIO: resolved 2026-11 by switching to SeaweedFS (Apache-2.0).
2. ~~Pin image versions~~ Done 2026-10-14 (MinIO); 2026-11: SeaweedFS and rclone pinned by digest.
3. Add a `THIRD_PARTY_NOTICES` file to release artefacts (generated from the tools above) before the first sale.
