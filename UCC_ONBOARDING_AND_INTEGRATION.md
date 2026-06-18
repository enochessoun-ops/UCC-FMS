# UCC FMS — Onboarding Data & Integration Specification

This documents the items that require **UCC's authoritative data or a scoping decision** rather than
code. The platform already models all of them; what remains is loading real data / agreeing an interface.

## 1. Organisation tree (data load)
The `org_units` tree models any depth and consolidates per-unit and university-wide at any node.
Seeded today: 5 Colleges, their Schools/Faculties, 7 Directorates, 3 Institutes, 6 Centres, MIS sections,
**7 residential halls** (Casely Hayford, Atlantic, Adehye, Oguaa, Kwame Nkrumah, Valco, Superannuation),
**7 IGF ventures** (Press, Bookshop, Hospitality/Guest Centre, Hostels, UCC Consult, Farms, Hospital),
and 2 representative academic departments.

**UCC must provide, for bulk load during onboarding:**
- The complete official list of **residential halls** (confirm the full set if more than the 7 seeded).
- The full **academic department** layer under each School/Faculty (Head of Department as unit head).
- The complete **IGF register** (every income-generating venture, with its responsible officer).
- Each unit's **opening balances** and **budget** allocation.

Load via the org-unit API (`POST /api/org-units`) or a one-off seed script keyed on `code`/`parent_code`.

## 2. Student fees / billing — integration with the Student Information System (SIS)
The FMS deliberately does **not** hold the student master or fee schedules — those live in UCC's SIS.
The GL accounts and the **AR subledger already exist** (`4010-4015` fee revenue, `1010` Student Fees
Receivable, `2041` Deferred Student Fees; `ar_customers` / `ar_invoices` / `ar_receipts`).

**Integration interface (to agree with the SIS vendor):**
- Each fee-paying student (or cohort/programme) → an AR **customer**.
- A fee assessment for a period → an AR **invoice** (`POST /api/ar/invoices`, or batch via
  `POST /api/ar/import-invoices`) crediting the fee-revenue account, debiting Student Fees Receivable.
- A fee payment → an AR **receipt** (`POST /api/ar/receipts`) debiting bank, clearing the receivable.
- These post to the GL automatically and feed the AR ageing, SFP and I&E.

**Decision for management:** confirm the SIS push/pull mechanism (scheduled batch file vs API webhook),
the student-grouping granularity (per student vs per programme), and revenue-recognition timing
(on assessment vs on receipt; the system already supports deferred student fees `2041`).

## 3. Fuel-Coupon / "Fuel & Vehicle" module — disposition decision
Inherited from the base app's logistics domain. A university motor pool *can* use basic fuel tracking,
but the inter-unit **lend/borrow** coupon machinery is domain-foreign.
**Options:** (a) hide behind a feature flag for the UCC rollout (recommended), (b) keep for the motor
pool with the lend/borrow features disabled, or (c) remove. Awaiting management decision.

## 4. VAT recoverability (confirm)
Input VAT is currently treated as **irrecoverable** (loaded onto expense), which is correct **if UCC is
not VAT-registered for output**. Finance to confirm UCC's VAT-registration status.

## 5. Deployment / go-live configuration (not code)
- Rotate the default `admin` credential on first login (the login now returns `must_change_password`).
- Tune `dual_control_threshold_ghs` (default 50,000) to UCC's delegation-of-authority limits.
- Disable the seeded `demo` account in production.
- MySQL profile (PHP stack), hosting, TLS, off-site backups — see `DEPLOY_UCC.md`.
