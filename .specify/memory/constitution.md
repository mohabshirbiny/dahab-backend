<!--
Sync Impact Report
==================
Version change: 1.0.0 → 2.0.0 (MAJOR: Principle II redefined; Principle III contract list narrowed)
Modified principles:
  - II. "Least Privilege Enforced by the Engine, Not by Code" → "Customer Isolation by the Engine; Staff Authorization by Permission Data"
    (customer row-level security kept; PostgreSQL role grants for staff / wallet visibility removed;
    staff roles and role→permission mappings are data managed from the Dashboard)
  - III. "Docs Are the Source of Truth" — `dahabctoblueprint.md` removed from the contract;
    recorded product-owner decisions in a feature spec override the Technical Spec until docs/ is updated in the same PR
Added sections: none
Removed sections: none
Templates status:
  ✅ .specify/templates/plan-template.md — no change required (references constitution by path)
  ✅ .specify/templates/spec-template.md — no change required
  ✅ .specify/templates/tasks-template.md — no change required
  ✅ .specify/templates/checklist-template.md — no change required
Follow-up TODOs:
  - docs/Technical Spec/dahab-spec-part1-auth.md §3.1, §3.3, §4.4, §5.2 and
    docs/Technical Spec/dahab-dashboard-authorization.md updated by specs/002-dynamic-staff-authorization
Source: product-owner decisions 2026-09-26 (specs/002-dynamic-staff-authorization/spec.md)
-->

# Dahab Backend Constitution

## Core Principles

### I. Named Actor on Every State Change (NON-NEGOTIABLE)

Every state-changing request MUST resolve to exactly one `customer_id` or one
`staff_id` before it commits. The ledger and audit log refuse rows without an
attributed actor by database constraint, not by application convention. A
request that cannot be attributed to a real, named principal MUST NOT move
money, gold, listings, or verification state.

Rationale: This is the invariant the audit log depends on and the reason the
database schema pins the actor into `ledger_transaction` and `audit_log` via
`CHECK` constraints. See `docs/Technical Spec/dahab-spec-part1-auth.md` §1.

### II. Customer Isolation by the Engine; Staff Authorization by Permission Data

Customer data isolation MUST be enforced by PostgreSQL row-level security as
defense in depth. Application code MAY assume RLS is on but MUST NOT rely on
remembering a `WHERE` clause for customer tenancy or ownership. Any query
that reads or writes another customer's row without an explicit, documented,
and audited elevation is a defect.

Staff authorization MUST be enforced by the application from the permission
tables: staff roles, the permissions each role holds, and which staff hold
which roles are data, managed from the Dashboard. Permission codes are
defined in code, one per protected action. Staff authorization MUST NOT
depend on PostgreSQL roles or grants (including wallet visibility). Founder
status MUST NOT be changeable from the Dashboard or API.

Rationale: A forgotten predicate on customer data is a matter of when, not
if, and the engine forgets nothing, so customer isolation stays in the
database. Staff access must be adjustable by the business without schema or
infrastructure changes, so it lives in permission data and is audited.

### III. Docs Are the Source of Truth (NON-NEGOTIABLE)

`docs/` is the authoritative reference for product requirements, database
design, business rules, and workflows. Code MUST NOT invent behavior that is
not in `docs/`, and MUST NOT contradict it. When implementation reveals a gap
or an error in `docs/`, the fix lands in `docs/` first (or in the same PR),
never in code alone.

Rationale: Multiple contributors and future agents will read this repo; the
only way to keep them aligned is to keep the specification and the code in
lock-step. The technical spec parts (`docs/Technical Spec/`) and the SQL
schema files (`docs/Database schema/`) together define the contract.
`docs/dahabctoblueprint.md` is background only and MUST NOT be used as a
reference. A product-owner decision recorded in an accepted feature spec
overrides the Technical Spec, and the PR implementing it MUST update `docs/`.

### IV. Foundation Before Modules

A business module (Gold Items, Buy Requests, Inspections, Wallets, Payments,
Settlements, Commissions, Marketplace, Seller/Buyer surfaces) MUST NOT be
introduced until its supporting foundation is in place: (a) migrations that
match `docs/Database schema/*.sql` for the affected tables, (b) request
validation and API-Resource shapes, (c) at least one Action or Service that
encapsulates the state change, (d) a feature-level Pest test covering the
happy path and one refusal path, and (e) OpenAPI annotations on every new
endpoint. Modules MUST NOT be scaffolded ahead of an accepted specification.

Rationale: The `docs/` describe a system that holds other people's money and
other people's gold. Half-built features are worse than absent ones because
they invite state that the ledger cannot reconcile.

### V. Test the Boundary and the Ledger

Any code path that changes the ledger, changes a wallet balance, transitions
a listing/inspection/settlement, or emits a notification MUST be covered by a
feature test that exercises the HTTP boundary. Pure unit tests are welcome
but do not satisfy this rule for state-changing paths. Tests MUST assert on
observable effects (persisted rows, response envelope, dispatched jobs, sent
notifications), not on private helper calls.

Rationale: The value of a test in this system is that it will catch a broken
migration, a broken RLS policy, or a broken permission before it reaches the
ledger. Tests that mock the boundary do not catch those.

## Technology & Infrastructure Constraints

The project runs on a fixed stack; substitutions require an amendment.

- **PHP 8.3+**, **Laravel 12+**.
- **PostgreSQL 16+** as the sole primary datastore. No MySQL, no SQLite in
  production. SQLite in-memory is permitted for framework-only tests.
- **Redis 7+** for cache, sessions, and queues.
- **Laravel Sanctum** for API tokens; **Laravel Horizon** for queue supervision;
  **Laravel Notifications** for outbound channels.
- **OpenAPI 3** via `darkaonline/l5-swagger`. Every public `/api/v1/*` endpoint
  MUST carry an OpenAPI annotation.
- **Pest 3** (on PHPUnit 11) for tests; **Pint** for formatting.
- **Docker + Docker Compose** for reproducible local and CI environments; the
  same image runs in production. Non-container-safe extensions MUST NOT be
  required at runtime.
- **API versioning** under `/api/v1/`. A breaking change lands under a new
  version prefix; the old prefix is deprecated on a documented schedule
  before removal.

Secrets, credentials, and personal data MUST NOT be committed. `.env` is
ignored; `.env.example` documents required keys and safe defaults.

## Development Workflow & Quality Gates

- **Spec Kit is the lifecycle.** Non-trivial work flows through
  `/speckit-specify → /speckit-clarify → /speckit-plan → /speckit-tasks →
  /speckit-implement → /speckit-analyze`. Ad-hoc code that would fit a spec
  MUST be justified in the PR description.
- **Green tests block merge.** `composer test` (Pest) MUST pass on the target
  branch before merge. Failing or skipped tests require a linked issue and
  reviewer approval.
- **Style is enforced.** `./vendor/bin/pint` MUST be run before opening a PR.
- **No skipped hooks.** `--no-verify`, `--no-gpg-sign`, and equivalent
  bypasses are forbidden unless the reviewer explicitly authorizes them in
  the PR thread and the reason is captured.
- **Migrations are reversible.** Every migration ships a working `down()` or
  an explicit note in the PR that reversal is intentionally impossible
  (destructive data change), reviewed by a second engineer.
- **PR review MUST attach a Constitution Check.** Reviewers confirm the five
  Core Principles were considered; violations require a written waiver in the
  PR body citing the principle and the reason.
- **No business modules land in the skeleton phase.** Until the foundation
  is accepted, PRs MUST NOT introduce Dahab-specific models, migrations,
  controllers, services, or endpoints beyond health/auth probes.

## Governance

This Constitution supersedes ad-hoc conventions and prior informal practice.
When guidance elsewhere in the repository conflicts with this document, this
document wins until it is amended.

**Amendments** land as PRs that touch `.specify/memory/constitution.md`. An
amendment PR MUST:

- State the semantic version bump and its rationale.
  - **MAJOR** — a principle is removed or its meaning is redefined; a
    non-negotiable is downgraded; the stack is substituted.
  - **MINOR** — a principle or section is added, or an existing one is
    materially expanded.
  - **PATCH** — clarifications, wording, typos, non-semantic refinements.
- Update `Last Amended` to the merge date (ISO `YYYY-MM-DD`).
- Include a `Sync Impact Report` HTML comment at the top of the file listing
  version delta, added/removed sections, and any template follow-ups.
- Be reviewed by at least one engineer other than the author.

**Compliance review** happens in every PR. Reviewers verify that the change
is consistent with the five Core Principles and the constraints above, and
raise a blocking comment when it is not.

**Runtime development guidance** — day-to-day conventions, folder layout,
and commit style — lives in `README.md` and in the `.specify/templates/`
files. Those documents defer to this Constitution when they conflict.

**Version**: 2.0.0 | **Ratified**: 2026-09-19 | **Last Amended**: 2026-09-26
