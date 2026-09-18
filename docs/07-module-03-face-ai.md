# Module 03 — Face Enrollment + Face AI Foundation

## Goal
Implement image/webcam-based enrollment and recognition before RTSP runtime processing.

## Pipeline
Enrollment:
`capture -> detection -> quality -> alignment -> embedding -> duplicate check -> save profile -> verification`

Recognition:
`image -> detection -> quality -> liveness -> alignment -> embedding -> top-K search -> MATCH/REVIEW/UNKNOWN`

## Core Rules
- FaceProfile references person_id.
- Store model/version.
- Raw embeddings never go to browser.
- Samples are private.
- Similarity is not automatically probability.
- Thresholds require pilot calibration.

## Enrollment Samples
Minimum strategy:
- FRONT
- LEFT_SLIGHT
- RIGHT_SLIGHT

## Quality Codes
- NO_FACE
- MULTIPLE_FACES
- FACE_TOO_SMALL
- IMAGE_BLUR
- IMAGE_TOO_DARK
- IMAGE_TOO_BRIGHT
- BAD_POSE
- FACE_OCCLUDED
- LOW_QUALITY

## Duplicate Review
A possible duplicate face against a different person creates review/override flow, not silent enrollment.

## Re-enrollment
Create new profile, verify it, activate it, revoke prior active profile while preserving history.

## Liveness
Provide a separate service/interface and score/result.

## Permissions
- face.view
- face.enroll
- face.revoke
- face.reenroll
- face.test
- face.model.view
- face.model.manage

## Acceptance
See Module 03 acceptance criteria.
