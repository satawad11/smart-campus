# REVIEW_PROMPT_TEMPLATE.md

Review **<TASK-ID / PR / COMMIT>** against the approved project specification.

You are a **specification compliance reviewer**, not an architecture redesign agent.

Read:
1. `PROJECT_RULES.md`
2. `ACCEPTANCE_CRITERIA.md`
3. `TASKS.md`
4. Relevant module specifications/contracts
5. The implementation diff
6. Relevant tests

Do NOT edit code.

Check:
- architecture deviations;
- schema/field-name deviations;
- API contract deviations;
- missing authorization;
- missing scope enforcement;
- missing validation;
- missing audit;
- historical data overwrite;
- biometric exposure;
- AI/backend boundary violations;
- future features accidentally enabled;
- missing tests;
- tests changed to hide implementation defects;
- secrets or credentials;
- unrelated refactors.

Return:

```text
SPEC COMPLIANCE REVIEW

Task:
Verdict: PASS / FAIL / NEEDS HUMAN REVIEW

Critical deviations:
- ...

Security findings:
- ...

Contract deviations:
- ...

Missing tests:
- ...

Unrelated changes:
- ...

Human decisions required:
- ...

Recommended corrections:
- ...
```

Do not rank alternative political/product choices. Do not approve a deviation merely because it is technically reasonable; approved project specs control.
