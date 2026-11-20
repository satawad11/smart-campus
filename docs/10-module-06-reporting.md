# Module 06 — Historical Search + Dashboard + Reporting

## Goal
Make events useful for operations, management, investigation, and export.

## Dashboard
Metrics:
- detected
- recognized
- review
- unknown
- allow
- deny
- cameras online/total
- AI nodes online/total

Charts:
- by hour
- by decision
- by camera
- by department

## Historical Search
Filters:
- keyword
- person/student
- from/to
- site/zone/camera
- event type
- decision
- academic status

Gate pass history (GATE-008, `feature.vehicle`, page "ประวัติการผ่านจุดเข้าออก"): gate, match result,
current decision, plate read, person, vehicle, from/to. Plate searches are audited.

## Reports
- daily
- weekly
- monthly
- yearly
- academic year
- custom
- review
- unknown
- camera
- AI node
- Smart Gate passes per gate, in the range reports (GATE-008, `feature.vehicle`)
- data quality
- face enrollment progress

## Summary Layer
Use hourly/daily summaries or materialized structures for performance.
Raw events remain source of truth.

## Exports
- XLSX
- CSV
- PDF

Large exports:
`request -> queue -> generate -> object storage -> notification`

## Accuracy Rules
- distinguish total events vs unique persons
- unknown events are not automatically unique people
- timezone boundaries must be tested
- executive reports should prefer aggregate data

## Permissions
- report.view
- report.export
- report.executive
- report.system
- optional report.sensitive

## Acceptance
See Module 06 acceptance criteria.
