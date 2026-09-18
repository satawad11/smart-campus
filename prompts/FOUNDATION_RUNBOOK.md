# Foundation Runbook for the Human Operator

Run one task at a time in this order:

1. FND-001
2. FND-002
3. FND-003
4. FND-004
5. FND-005
6. FND-006
7. FND-007
8. FND-008
9. FND-009
10. FND-010
11. FND-011
12. FND-012

For each task:
1. Give the coding agent only the corresponding prompt file.
2. Let the agent inspect repository/spec/tests.
3. Require an Implementation Report.
4. Check test/build results.
5. Check git diff.
6. Commit the completed task.
7. Start a new agent session if context becomes noisy.
8. Move to the next task only after the current task passes.

Recommended commit style:
- `chore(foundation): complete FND-001 monorepo structure`
- `chore(docker): complete FND-002 base compose services`
- `chore(backend): complete FND-003 Laravel bootstrap`

Do not ask one agent to execute all FND tasks in one shot.
