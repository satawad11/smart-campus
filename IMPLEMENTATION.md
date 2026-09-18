# IMPLEMENTATION.md

## Development Method

This project uses **Spec -> Contract -> Task -> Test -> Code -> Review -> Gate**.

A feature is not accepted merely because the UI renders or the code compiles.

## Repository Expectations

Recommended structure:

```text
smart-campus/
├── AGENTS.md
├── CLAUDE.md
├── PROJECT_RULES.md
├── IMPLEMENTATION.md
├── TASKS.md
├── ACCEPTANCE_CRITERIA.md
├── docs/
├── contracts/
├── apps/
│   ├── backend/
│   ├── frontend/
│   └── ai-service/
├── infrastructure/
├── tests/
│   ├── Acceptance/
│   ├── Architecture/
│   └── Security/
└── docker-compose.yml
```

## Human Gate

The project owner approves:
- architecture changes;
- contract breaking changes;
- security-boundary changes;
- database migrations that destroy/transform existing production data;
- activation of future feature flags;
- production AI threshold changes after pilot.

## Agent Handoff

Agents may change between Codex, Claude Code, Antigravity, or other coding agents. Therefore:
- task state belongs in `TASKS.md`;
- approved contracts belong in repository files;
- decisions must not live only in chat memory;
- every completed task needs an implementation report.

## Pull Request Gate

A PR should contain:
- one coherent Task ID or tightly coupled group approved by owner;
- updated tests;
- no unrelated refactoring;
- migration notes when applicable;
- API notes when applicable;
- security implications;
- implementation report.

## Environment Sequence

Development -> Staging -> Pilot -> Production.

Never use production student data in local development unless explicitly approved and appropriately protected.
