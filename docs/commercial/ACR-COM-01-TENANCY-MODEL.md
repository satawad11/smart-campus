# ARCHITECTURE_CHANGE_REQUEST — ACR-COM-01 Tenancy Model for Commercial V1

**Status:** DECIDED — **Option B** (owner delegation, 2026-10-14; see docs/decisions/OWNER-DECISIONS-2026-10-14.md D2) · **Raised:** 2026-10-13 · **Raised by:** AI coding agent
The central management plane is a separate later project; the campus instance stays single-tenant.
**Hosting:** DECIDED — **both** customer on-premises and vendor-hosted (cloud) instances (owner, explicit chat instruction 2026-10-15; see D13).

```text
ARCHITECTURE_CHANGE_REQUEST

Problem:
  Commercial V1 must serve many institutions with data isolated per institution (COM-01).
  The approved V1 is single-institution: no tenant dimension in ~45 tables, storage paths,
  Reverb channels, queues, caches, reports, audit or RBAC.

Current specification:
  docs/00-system-blueprint.md §1 (one vocational college), §12 (Site -> Zone -> Camera),
  PROJECT_RULES §8 scope model GLOBAL / SITE / ZONE. No tenant concept.

Proposed change (recommended: Option B):
  1. Keep the campus platform single-tenant. Deploy one isolated instance per institution
     (on-premises server or dedicated VM): own PostgreSQL, MinIO, Redis, Reverb, AI nodes.
     Biometric and student data never leave the institution's instance.
  2. Add a separate Central Management Plane (same locked stack: Laravel, Vue 3, PostgreSQL)
     that is multi-tenant by Organization and holds only commercial/operational metadata:
     organizations, contacts, licenses (issuance + revocation list), instance registry,
     software versions/update notices, fleet health summaries, support requests.
  3. The campus instance talks to the central plane outbound only, optionally, over HTTPS:
     license/revocation fetch, version check, aggregate health (no images, no embeddings,
     no person data). It keeps working offline on its last valid signed license.
  4. Inside the campus instance, add an "installation identity" (one row) that the signed
     license binds to. No tenant_id columns are added to existing tables.

Reason:
  - Strongest isolation for Highly Sensitive biometric data (physical, not query-scoped).
    COM-01 forbids relying on a global query scope alone; Option B doesn't depend on one.
  - Matches the target architecture (Hybrid Edge AI + Central Management; no continuous raw
    video upstream; nodes keep working when the centre is unreachable).
  - Reuses the human-approved V1 without rewriting its schema or contracts.
  - Smaller PDPA footprint for the vendor: the central plane isn't a biometric processor.

Impact:
  - Campus instance: additive only (installation identity, license module, optional
    outbound sync). Existing APIs and tables unchanged.
  - New deployable: central plane (new repo folder apps/central or separate repository).
  - Operations: one instance per customer to install, monitor, back up and upgrade, so
    COM-04 installer/updater quality becomes critical.
  - Cost: per-customer hardware/VM; hosted (vendor-run) instances remain possible, one VM each.

Migration impact:
  - None for existing data. Existing pilot becomes "instance #1".

Alternatives:
  A. Shared multi-tenant campus app: tenant_id on every table + tenant-aware storage,
     channels, queues, caches, reports, RBAC; PostgreSQL RLS as a second barrier.
     Pros: one deployment to operate. Cons: largest change to approved V1 (re-approval of
     every module), concentrates all customers' biometric data, highest breach blast radius,
     every query a potential cross-tenant leak.
  C. Database-per-tenant in one app: connection switching per tenant.
     Pros: stronger than A for data. Cons: central custody of biometrics remains; complex
     migrations, backups and queue/channel isolation; still a large change.
```

## Decision needed

| Question | Options |
|---|---|
| Tenancy model | **B (recommended)** / A / C |
| Where the central plane lives | New folder `apps/central` in this repository (recommended for shared CI) / separate repository |
| Hosting of campus instances | Customer on-premises / vendor-hosted dedicated VM / both |

## What proceeds while this is pending (valid under A, B and C)
License verification in the instance (COM-02), node management in the instance (COM-03), monitoring and
diagnostics (COM-05), installer/updater (COM-04), security/PDPA and packaging documentation.

## What stays on hold
Organization/tenant tables, tenant context resolution, cross-tenant tests and super-admin cross-tenant access
(COM-01), the customer portal (COM-06), and central fleet aggregation.

## Hosting decision (2026-10-15)

The owner asked for both sales models. Under Option B, both use **the same single-tenant campus build**;
only where it runs and who operates it differ. No shared multi-tenant database is introduced.

| | On-premises | Vendor-hosted (cloud) |
|---|---|---|
| Where the instance runs | Institution server room | One dedicated VM per institution, in a Thailand region where available |
| Who operates it | Institution IT (vendor support) | Vendor |
| AI nodes | On campus LAN | On campus, outbound HTTPS to the instance over the Internet |
| Biometric / student data | Never leave the institution | Stored by the vendor as **data processor** (PDPA s.40): DPA required, encryption at rest, access logging, data-location statement |
| TLS | Institution certificate or internal CA | Public certificate per instance domain |
| Backups | Institution storage (encrypted archives) | Vendor storage in another zone (encrypted archives) |
| Licence | Offline signed file | Same signed file; vendor issues and installs it |

Consequences: vendor-hosted instances need a security baseline for internet-facing hosts (firewall, TLS only,
admin MFA on, patching), a legal review of the DPA and the cross-border/data-location wording, and per-customer
cost in pricing. A shared multi-tenant SaaS (Option A) remains rejected: it would concentrate every customer's
biometric data in one database.
