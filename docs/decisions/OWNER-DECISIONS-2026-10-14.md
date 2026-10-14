# Owner Decisions — 2026-10-14

**How these were made:** in chat on 2026-10-14 the project owner instructed the agent to proceed with the agent's own
recommendations for the open decisions ("เรื่องที่ต้องให้คุณตัดสินใจหรือดำเนินการ ตามที่คุณแนะนำ"). Each decision
below is that recommendation, recorded as **owner-delegated**. No separate line-by-line human review was done; the
owner can override any item. Items that need physical resources, legal judgement or real data stay open.

| # | Topic | Decision | Action taken | Still needs a human |
|---|---|---|---|---|
| D1 | Post-approval change review (PILOT-000B) | Approve the development branch as the next release candidate, **for pilot rehearsal**, not production | Annotated tag `v1.1.0-rc1` (local + backup remote); runbooks reference it as a candidate | Before a real pilot: owner reads PILOT-000B §2/§2b and signs the decision record |
| D2 | Tenancy model (ACR-COM-01) | **Option B**: one isolated instance per institution + a separate multi-tenant central plane holding no biometric/student data | ACR status updated | Building the central plane is a later project |
| D3 | Admin MFA | Required for SUPER_ADMIN and SYSTEM_ADMIN | Implemented `f4a4bce` | — |
| D4 | Dashboard permission (A-14) | Dashboard accepts `dashboard.view` or `report.view`; role matrix unchanged | Implemented `144164d` | — |
| D5 | Server-side MATCH check (A-18) | Re-check node MATCH vs approved threshold; else REVIEW | Implemented `ca4a3d1` | — |
| D6 | Camera snapshot (A-21) | Accept the labelled mock for the pilot; real capture after cameras are installed | Recorded | Implement with real cameras |
| D7 | Backup retention + RPO (B-RET) | Approve proposed values; object-storage RPO exception **for the pilot only**; require archive encryption | PILOT-BACKUP-RETENTION-POLICY updated | Revisit RPO before multi-site use |
| D8 | Licence signing key | Generate `vendor-2026-a`; ship public key | Done `1657ac3`; private key outside repo, user-only ACL | Move private key offline + 2 encrypted backups, delete from workstation |
| D9 | Git remote | Bare backup repository on a different physical disk (C: SSD; repo is on E: HDD) | `backup` remote, all branches/tags pushed | Optional off-site remote (e.g. private GitHub/GitLab, or the organisation Google Drive) — needs the owner's account and explicit OK to upload |
| D10 | Licence enforcement mode | `warn` on the existing pilot; `enforce` on commercial installs | Documented | — |
| D11 | **B5 production biometric models** | **Do not select a production model yet.** Run the pilot non-biometrically (monitoring, manual events, reports) until a model passes evaluation on consented data. Shortlist below | Recorded | Licence confirmation, evaluation with consented data (FACE-021 metrics), ACR if the embedding dimension changes |
| D12 | PDPA documents | Draft privacy notice, DPA outline and DSR procedure | Drafts in `docs/legal/` (marked DRAFT) | Review and adoption by qualified Thai PDPA counsel and the institution (controller) |

## B5 model shortlist (facts checked 2026-10-14 in the official repositories)

| Model | Role | Licence (as stated by the source) | Fit / issue |
|---|---|---|---|
| YuNet (OpenCV Zoo) | face detection | MIT, covers code and ONNX files in the model directory | Good candidate |
| SFace (OpenCV Zoo) | face embedding | Apache 2.0, covers files in the model directory | Embedding is 128-d; the schema fixes `vector(512)` → needs an ARCHITECTURE_CHANGE_REQUEST (new column / migration) |
| InsightFace pretrained (ArcFace, buffalo_l, …) | detection + embedding | Code MIT; **pretrained models non-commercial research only** (commercial licence by contacting InsightFace) | **Not usable for sale** without a commercial licence. The placeholder `model_code=arcface-r50-v1` must not become a production model without that licence |
| MiniFASNet (Silent-Face-Anti-Spoofing) | liveness / PAD | Repository Apache 2.0; **weights' licence not stated** | Confirm with the authors before use |

Sources: github.com/opencv/opencv_zoo (face_detection_yunet, face_recognition_sface READMEs), github.com/deepinsight/insightface
(README licence section), github.com/minivision-ai/Silent-Face-Anti-Spoofing.
