# Pricing Assumptions

**Status: UNVALIDATED ASSUMPTIONS ONLY.** No cost model, market research or trial sale exists. Nothing here is a
price, quote or SLA, and none of it may be presented to a customer as one.

## 1. Cost drivers to measure before pricing
| Driver | How to measure | Status |
|---|---|---|
| Edge node hardware per N cameras | CAM-020 benchmark on the chosen model + target hardware | BLOCKED (B5: no production model) |
| Campus server / VM sizing | pilot resource telemetry (COM-03a) over 14 days | BLOCKED (no pilot host) |
| Installation effort (person-days) | time the first commercial install against INSTALLATION_GUIDE | not started |
| Support load (tickets / site / month) | pilot incident log PILOT-008 | AWAITING pilot |
| Model licence cost | depends on the B5 choice | BLOCKED |
| Vendor central plane hosting | depends on ACR-COM-01 | BLOCKED |

## 2. Structural assumptions (to test, not decided)
- Licence priced per active camera band (matches `limits.max_cameras`), annual term, with grace period.
- Edge hardware sold at cost-plus or customer-supplied against a published spec.
- Installation and training as a one-off service.
- A non-biometric edition is possible while biometric models are unapproved.

## 3. What would validate pricing
At least one completed pilot with accepted results (PILOT-011), measured costs from §1, and a trial sale. Until then
this document stays at "assumption".
