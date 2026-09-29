# Pilot Test Dataset & Validation Process (SEC-018)

## 1. Pilot Scope & Cohort Specifications
The pilot phase validates system end-to-end reliability under controlled real-world conditions prior to campus-wide rollout:
- **Physical Boundaries**: 1 Site (Main Campus), 1 Access Point / Gate (Gate 1 Pedestrian Entrance).
- **Edge Devices**: 2 RTSP IP Cameras (Entrance Cam A - High Angle, Entrance Cam B - Eye Level) + 1 Jetson/Edge AI Node.
- **Participant Cohort**:
  - 80 enrolled students spanning diverse departments and grades.
  - 20 control non-enrolled individuals (for negative / UNKNOWN testing).
  - 5 authorized operators (Security Officer, Discipline Staff, Registrar, System Admin).

## 2. Dataset Synthesis & Enrollment
1. **Enrollment Phase**:
   - Each participant completes enrollment under standard indoor lighting via `FaceEnrollmentService`.
   - Quality filters enforced: minimum resolution 112x112, face detection confidence >= 0.85, yaw/pitch within +/- 20 degrees.
   - Reference images stored securely in private object storage; 512-dimension normalized embeddings indexed into pgvector.
2. **Test Variations & Environmental Scenarios**:
   - **Baseline Walkthrough**: Single person natural pace under balanced ambient light.
   - **Adverse Lighting**: Direct morning backlight, dusk, and artificial night illumination.
   - **Accessories & Occlusions**: Prescription eyeglasses, dark sunglasses, medical face masks, winter scarves.
   - **Distance & Speed**: Slow walk (1 m/s), fast walk (2 m/s), 1.5m to 4m detection range.
   - **Crowd Dynamics**: Clustered 3-5 person arrivals through pedestrian turnstile zone.
   - **Spoof / Presentation Attacks**: Printed color photo (A4), high-resolution tablet screen playback.

## 3. Evaluation Metrics & KPI Targets
| Metric | Formula | Target Threshold |
|---|---|---|
| True Acceptance Rate (TAR) | Correct MATCH / Total authorized attempts | >= 98.5% |
| False Acceptance Rate (FAR) | Incorrect MATCH / Impostor attempts | <= 0.05% |
| Presentation Attack Detection (PAD) | Correct Spoof Rejection / Spoof attempts | >= 99.0% |
| Ingestion & Decision Latency | Camera RTSP frame -> Decision persisted | <= 1500 ms (P95) |
| Event Loss Rate | Dropped frames / total detections | 0.00% |
