# Module 01 — Authentication + User + RBAC

## Goal
Provide secure login, account lifecycle, roles, permissions, scopes, sessions, MFA, login history, and audit.

## Initial Roles
- SUPER_ADMIN
- SYSTEM_ADMIN
- DISCIPLINE_OFFICER
- SECURITY_OFFICER
- EXECUTIVE_VIEWER

## Core Permission Style
Use `module.action`.

Examples:
- dashboard.view
- student.view
- student.create
- student.update
- student.status.change
- face.enroll
- camera.manage
- monitor.view
- event.review
- report.export
- user.create
- role.manage
- audit.view
- system.manage

## Scope
- GLOBAL
- SITE
- ZONE

Backend must enforce scope on queries and actions.

## Account Status
- ACTIVE
- LOCKED
- DISABLED
- EXPIRED

## Required Features
- login/logout/me
- account lock
- password policy
- change/reset password
- MFA
- user CRUD
- role CRUD
- permission assignment
- session list/revoke
- login history
- audit

## Security
- do not reveal username existence on failed login
- disabling user should invalidate sessions according to policy
- prevent destruction of last SUPER_ADMIN
- protect sensitive role changes with audit

## Acceptance
See `ACCEPTANCE_CRITERIA.md`, Module 01.
