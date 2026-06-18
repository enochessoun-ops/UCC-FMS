# UCC FMS — University of Cape Coast Financial Management System

A **standalone, university-wide** financial management system for the University of Cape Coast.

> **Independent application.** UCC FMS was built on the SBS-ERP foundation but is a **separate, independent product** from both **SBS-ERP** (School of Biological Sciences) and **AOI-FMS**. They share lineage, not a codebase or a deployment. Changes here do not affect SBS-ERP or AOI-FMS, and vice-versa.

## What makes this the *university-wide* build
- **Org hierarchy (`org_units`)** spanning colleges, schools, faculties, departments, centres, directorates, halls, institutes and sections.
- **Per-node financial statements** — every unit can produce its own Trial Balance / SFP / I&E, scoped by `unit_id`.
- **Subtree consolidation** — a parent node (e.g. a College) rolls up all its descendants; the root view is the whole-university position.
- **Row-level scoping** — users have a `home_unit_id` and a scope (`own_unit` / `subtree` / `university`); non-admins see only their unit's books.

## Stack & deployment
- Current implementation: Python (Flask/WSGI), `gunicorn app:app`, SQLite (PDO-compatible file).
- **Target for UCC infrastructure: PHP 8 / Apache (cPanel)** — see `PHP_PORT_PLAN.md`. The PHP port is in progress (foundation only); the Python build is the reference and the 60-check gate (`smoke_test.py` + `regression_fixes.py`) is the acceptance contract.

## Gate
`python3 smoke_test.py --base http://127.0.0.1:PORT --user admin --pass UCC@2024 --period 2026-06`
`python3 regression_fixes.py --base ... ` — full finance regression.

## Relationship to siblings
| App | Identity | Repo |
|---|---|---|
| **UCC FMS** | University-wide (this repo) | UCC-FMS |
| SBS-ERP | School of Biological Sciences | SBS-ERP |
| AOI-FMS | Africa Ocean Institute | aoi-fms |
