# Smart Campus AI Development Control Pack — Full Blueprint Edition

This pack is designed to control Codex, Claude Code, Antigravity, or other coding agents while implementing the **AI Smart Campus Security & Access Platform**.

## Start Here

1. `PROJECT_RULES.md`
2. `docs/00-system-blueprint.md`
3. `docs/01-database-blueprint.md`
4. `docs/02-api-contract.md`
5. `docs/03-ui-ux-blueprint.md`
6. `docs/04-development-blueprint.md`
7. Current module document
8. `ACCEPTANCE_CRITERIA.md`
9. `TASKS.md`

## Agent Entry Points

- Codex / generic coding agents: `AGENTS.md`
- Claude Code: `CLAUDE.md`
- Other agents: explicitly instruct them to read `PROJECT_RULES.md`

## Foundation Execution

Use prompts in:

`prompts/FND-001.md` through `prompts/FND-012.md`

Read:

`prompts/FOUNDATION_RUNBOOK.md`

Run exactly one Task ID at a time.

## Important Rule

Do not ask an agent to "build the whole system".

Use:

`Spec -> Contract -> Task -> Test -> Code -> Review -> Gate`

The project repository, not chat memory, is the source of truth.
