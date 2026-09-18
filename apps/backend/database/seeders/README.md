# Development seed / demo data (FND-012)

Development-only strategy for populating a demo environment. **Never for
production** — `DemoDataSeeder::shouldRun()` refuses the `production`
environment, and no real/production data is committed.

## Usage (development)

```bash
# From apps/backend, against the dev database:
php artisan migrate --seed
# or re-seed only:
php artisan db:seed
```

## Demo data flow

`DatabaseSeeder` calls `DemoDataSeeder`. As each module lands it adds its own
idempotent seeder and registers it in `DatabaseSeeder`:

| Demo data                     | Owning task/module           | Status      |
|-------------------------------|------------------------------|-------------|
| Roles / Permissions / Users   | Module 01 — AUTH-003         | placeholder |
| One Site + Zones              | Module 04 — CAM-*            | placeholder |
| Demo recognition events       | Module 05 — EVT-*            | placeholder |

The intended demo topology (added when the tables exist): **one Site**, a small
set of **Zones** under it, a demo **role/permission** matrix, and a handful of
**mock recognition events**.

## Mock event flow

Demo events do not require camera hardware: the AI service runs with
`AI_MODE=mock` (FND-005), which returns MATCH / UNKNOWN / LOW_CONFIDENCE /
LOW_QUALITY / LIVENESS_FAIL outcomes. Concrete demo-event seeding is added with
the Event engine (Module 05); until then this is a documented placeholder.

## Why placeholders now

`DemoDataSeeder` performs no writes today because the Site/Zone/Role/Permission/
Event tables are introduced by later modules. Creating them here would
implement future modules early, which the project rules forbid. Keeping the
placeholder means `php artisan db:seed` succeeds on a clean database at every
foundation step.
