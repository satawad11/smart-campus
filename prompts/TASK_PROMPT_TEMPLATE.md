# TASK_PROMPT_TEMPLATE.md

Use this template when assigning work to Codex, Claude Code, Antigravity, or another coding agent.

---

Implement **<TASK-ID> — <TASK NAME>**.

Before coding:
1. Read `PROJECT_RULES.md`.
2. Read `ACCEPTANCE_CRITERIA.md`.
3. Read `TASKS.md`.
4. Read these task-specific specifications:
   - `<DOC 1>`
   - `<DOC 2>`
5. Inspect the current repository and existing related tests.

## Scope of this task ONLY

- <item>
- <item>
- <item>

## Explicitly out of scope

- <item>
- <item>
- Any later Task ID.

## Required contracts

Database:
- <tables/fields>

API:
- <routes>

Permissions:
- <permissions>

Events:
- <events>

## Requirements

- Follow approved field names exactly.
- Preserve UUID external identifiers and BIGINT internal IDs.
- Do not redesign architecture.
- Do not add future-phase features.
- Do not weaken or edit golden acceptance/security tests to make the implementation pass.
- If a specification conflict or architecture-changing need is found, stop the affected implementation and return an `ARCHITECTURE_CHANGE_REQUEST`.

## Tests required

- <test>
- <test>
- <test>

## Completion procedure

1. Run formatter/linter.
2. Run relevant migrations.
3. Run relevant unit/feature/integration/security tests.
4. Inspect git diff.
5. Verify no unrelated files changed.
6. Update `TASKS.md` to `[x]` only if all required tests pass.
7. Return the required `IMPLEMENTATION REPORT`.

Do not proceed to the next Task ID automatically.
