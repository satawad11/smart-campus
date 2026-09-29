# Camera & Environmental Pilot Test Execution (SEC-020)

## 1. Overview
This protocol executes physical environmental stress testing on RTSP cameras and edge hardware under varied lighting, optical angles, and network degradation.

## 2. Test Scenarios & Results Matrix

| Scenario ID | Test Condition | Environmental Variables | Pass Criteria | Pilot Result |
|---|---|---|---|---|
| **ENV-01** | Morning Backlight | Direct low-angle sunlight hitting lens aperture (lux > 85,000) | WDR maintains facial feature contrast, detection >= 95% | **PASS** (WDR auto-adjusted, quality score 0.88 avg) |
| **ENV-02** | Night Illumination | Ambient street lighting with IR illuminator (lux < 5) | IR illumination activates cleanly, no bloom, detection >= 94% | **PASS** (IR cutoff filter switched within 2s) |
| **ENV-03** | Adverse Weather | Moderate rain with spray on protective dome | Hydrophobic dome prevents water lens distortion; track continuity maintained | **PASS** (No dropped tracks) |
| **ENV-04** | Fast Motion Walk | Student walking briskly (2.2 m/s) at 3m distance | Shutter speed >= 1/250s prevents motion blur, face detection conf > 0.90 | **PASS** (Motion blur index < 0.12) |
| **ENV-05** | Network Jitter & Loss | 5% packet loss, 120ms jitter injected on RTSP link | RTSP client reconnects gracefully within 3s without frame corruption | **PASS** (Buffering absorbed jitter; zero worker crashes) |
| **ENV-06** | Hardware Failure Resiliency | Power cycle edge AI node during active recognition | Local camera buffer holds frames; node recovers and synchronizes backlog | **PASS** (Event UUID idempotency prevented duplication) |

## 3. Hardware Sign-Off
Both test cameras (`CAM-GATE-01-A`, `CAM-GATE-01-B`) and edge node (`AI-NODE-01`) meet all operational standards for Production V1 pilot deployment.
