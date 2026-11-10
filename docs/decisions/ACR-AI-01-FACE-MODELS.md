# ARCHITECTURE_CHANGE_REQUEST — ACR-AI-01 Production face models (YuNet + SFace)

**Status:** APPROVED by the project owner (chat, 2026-11-10): YuNet + SFace, storage option B.
The owner chose YuNet + SFace from the B5 shortlist
([OWNER-DECISIONS-2026-10-14](OWNER-DECISIONS-2026-10-14.md) D11). Liveness: the owner chose to
find a PAD model with a confirmed licence (not attendance-only without PAD). Thresholds still
need separate approval after the evaluation. Not yet implemented.
· **Raised:** 2026-11-10 · **Resolves:** part of B5 (detection + embedding). Liveness/PAD stays open.

```text
ARCHITECTURE_CHANGE_REQUEST

Problem:
  No production face model is installed (B5). AI_MODE=mock returns synthetic results, so the
  platform cannot recognise real people. The ONNX adapters in apps/ai-service are placeholders:
  ONNXFaceDetector.detect() returns no faces, and ONNXFaceEmbedder assumes an ArcFace-style
  512-d model. The InsightFace pretrained models are licensed for non-commercial research only.

Current specification:
  Module 03 / FACE-008: face_profiles.embedding is vector(512) with an HNSW cosine index;
  FaceEnrollmentVerificationService rejects any profile whose dimension is not 512.
  ai_models.embedding_dimension and face_profiles.embedding_dimension already exist, and the
  candidate search filters by recognition_model_id, so vectors of different models never mix.
  Thresholds (FACE-021, config/face.php): match 0.80, review 0.60, ambiguity margin 0.05,
  duplicate review 0.65, liveness real 0.70 / spoof 0.40.

Proposed change:
  1. Detection: YuNet (OpenCV Zoo, MIT) in a real ONNXFaceDetector, returning boxes, scores and
     the five landmarks used by the existing aligner.
  2. Embedding: SFace (OpenCV Zoo, Apache 2.0), 128-d, on the existing 112x112 aligned face,
     L2-normalised.
  3. Storage, option B (recommended, see Alternatives): store the 128-d vector zero-padded to
     512 in the existing column. Zero-padding a unit vector keeps its norm and every cosine
     similarity exactly, so the index, the search SQL and the 512 checks stay unchanged.
     ai_models gets a row yunet-sface / <version> with embedding_dimension = 512 (stored) and
     the native dimension 128 recorded in its metadata. The padding happens in one place in
     ai-service and is covered by a test that cosine(padded) == cosine(native).
  4. Model files are vendored into the AI node kit under models/ with their licence texts and
     SHA-256 checksums; the kit refuses to start a model whose checksum does not match.
  5. Thresholds are model-specific. The FACE-021 values are NOT changed by this ACR. New values
     for SFace are proposed only after the evaluation below, and need their own human approval.
     Until then the current 0.80 match threshold applies (stricter than SFace's published
     operating point, so the failure mode is "not recognised", not "wrong person").
  6. Liveness/PAD is out of scope. The fail-closed UnavailableFaceLivenessDetector stays the
     default; recognition stays blocked by liveness unless a separate decision changes that.

Reason:
  Only shortlisted option whose licences (MIT, Apache 2.0) allow commercial use without a
  separate agreement. Both run on CPU (Raspberry Pi 5 class nodes) through ONNX Runtime/OpenCV.

Impact:
  ai-service: real detector and embedder, padding helper, model loading by checksum, tests.
  AI node kit: bundled model files and licences (size grows by a few MB).
  Backend: one ai_models row (seeder/migration), no schema change, no API contract change.
  Accuracy: SFace is smaller and less accurate than ArcFace-R100 class models; the evaluation
  decides whether it is good enough for this campus.

Migration impact:
  None for existing data: there are no real embeddings (mock only). Mock profiles keep their
  own model id and are not searched against SFace vectors. Reversible: switching AI_MODE back
  to mock or removing the model row restores today's behaviour.

Alternatives:
  A. New vector(128) column + index, dimension-aware search and checks. Cleanest data model,
     but a schema and code change across enrollment, verification and search.
  B. Zero-pad to 512 (proposed). No schema change; 4x storage per vector (2 KB, negligible).
     Risk: the padding is hidden, so it must be documented and tested.
  C. Buy a commercial InsightFace licence and keep 512-d ArcFace. Better accuracy, cost and
     licence terms unknown.
  D. Stay on mock (no biometrics) and run the pilot with manual events (current D11 decision).
```

## Licence verification (2026-11-10, upstream repositories checked directly)

| Model | Files and licence as published | Open question upstream |
|---|---|---|
| YuNet | opencv_zoo `models/face_detection_yunet`: "All files in this directory are licensed under MIT" | opencv_zoo #320 (open, no answer): training data (WIDER FACE) and commercial use of the weights |
| SFace | opencv_zoo `models/face_recognition_sface`: directory README states Apache-2.0 for all files | opencv_zoo #313 (open since 2026-07-22, no maintainer answer): the SFace paper names CASIA-WebFace, VGGFace2 and MS1MV2, which carry research-only terms; which data trained this exact weight is undocumented |
| MiniFASNet (PAD) | minivision-ai/Silent-Face-Anti-Spoofing: repository licence Apache-2.0; the two weights (`2.7_80x80_MiniFASNetV2.pth`, `4_0_0_80x80_MiniFASNetV1SE.pth`) are committed in that repository | #146 and #147 (open, no answer): confirmation that the licence covers the weights, and training-data provenance |

Finding: for all three, the publisher's licence covers the files, and none of the publishers
documents the training data. That residual risk (dataset terms flowing into the weights) is a
legal judgement, not an engineering one. Proposal: use them for the college's own consented
pilot with this risk recorded; before selling or redistributing, get qualified legal review or
replace them with commercially licensed models. MiniFASNet weights are PyTorch files: export
them to ONNX ourselves from the upstream commit and verify the export numerically, rather than
using third-party ONNX re-uploads.

## Evaluation before go-live (FACE-021 procedure)

1. **Consent:** explicit consent of each volunteer (PDPA s.26, biometric data); minors need a
   guardian. Start with 20 to 30 consenting staff and volunteers. No other student data.
2. **Enrol** each volunteer with the normal enrolment flow (several poses, quality gate).
3. **Measure on site cameras:** genuine-match rate, false-match rate between different people,
   and the share sent to review, at several candidate thresholds.
4. **Propose thresholds** with the measured numbers; the owner approves them in writing.
5. **Liveness decision** (separate): a PAD model with a confirmed licence, or a written decision
   to run attendance-only recognition without PAD, accepting that a photo can fool it.

## Decisions needed

| Question | Proposal |
|---|---|
| Approve YuNet + SFace as the production detection and embedding models | Yes |
| Storage | Option B (zero-pad to 512), no schema change |
| Thresholds | Unchanged until the evaluation; new values need separate approval |
| Liveness/PAD | Separate decision; stays fail-closed meanwhile |
