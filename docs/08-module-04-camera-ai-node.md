# Module 04 — Camera + AI Node + RTSP

## Goal
Connect real cameras to AI nodes and produce stable recognition results.

## Flow
`Camera -> RTSP -> StreamManager -> FrameSampler -> Detector -> Tracker -> Quality -> Recognition -> Event delivery`

## Camera
Dynamic configuration from web:
- code/name
- site/zone
- IP
- RTSP
- credentials encrypted
- AI node
- face detection toggle
- recognition toggle
- liveness toggle
- status

## AI Node
- registration
- per-node credentials
- heartbeat
- health metrics
- assigned cameras
- config sync

## Stream States
- STOPPED
- STARTING
- RUNNING
- RECONNECTING
- ERROR
- DISABLED

## Runtime Principles
- do not recognize every frame
- detect at sampled FPS
- use tracking
- recognition on new/good-quality tracks
- local track cache
- backend semantic cooldown
- drop stale jobs under backpressure

## Offline Buffer
If backend unavailable:
- store pending events durably on AI node
- retry
- preserve event_uuid and occurred_at

## Health
Camera:
- FPS
- latency
- dropped frames
- reconnects
- brightness
- blur
- last frame

AI Node:
- CPU
- RAM
- GPU
- GPU memory
- temperature
- active cameras
- queue depth

## Time
Use NTP. Observe clock drift.

## Permissions
- camera.view/create/update/enable/live/test
- ai_node.view/manage

## Acceptance
See Module 04 acceptance criteria.
