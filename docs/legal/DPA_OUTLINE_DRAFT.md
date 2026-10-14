# Data Processing Agreement — Outline (DRAFT)

**Not a contract.** Heads of terms for counsel to turn into an agreement between the institution (data controller)
and the vendor (data processor). Technical facts reference [PDPA_DATA_MAP](../commercial/PDPA_DATA_MAP.md) and
[SECURITY_BASELINE](../commercial/SECURITY_BASELINE.md).

1. **Parties and roles** — institution = controller; vendor = processor; sub-processors listed in an annex (none for
   on-premises deployments under ACR-COM-01 option B).
2. **Subject matter and duration** — installation, support and updates of the campus platform for the licence term.
3. **Data and data subjects** — students (incl. minors), staff users; categories per PDPA_DATA_MAP §2, including
   biometric (sensitive) data.
4. **Processing only on documented instructions** — vendor accesses the instance only for support requested by the
   institution; remote access method and logging to be agreed.
5. **Location** — data stays on the institution's server; no transfer outside Thailand [confirm].
6. **Security measures** — annex = SECURITY_BASELINE technical controls; vendor staff confidentiality and training.
7. **Personnel access** — named support staff, MFA, audit log review by the institution.
8. **Sub-processors** — prior written approval.
9. **Assistance** — with DSRs (DSR_PROCEDURE), DPIA, breach notification.
10. **Breach notification to the controller** — without undue delay, target [24] hours after discovery, with the
    facts needed for the controller's own notification.
11. **Return and deletion at end of contract** — institution keeps its data (export path: database + object-storage
    backup, report exports); vendor deletes any copies it holds and certifies it. Licence expiry never deletes data.
12. **Audit rights** — documentation review; on-site audit with notice.
13. **Liability and governing law** — counsel.
