# Feature Specification: Customer Data Isolation Enforced by the Database

**Feature Branch**: `claude/customer-rls`

**Created**: 2026-09-26

**Status**: Draft

**Input**: User description: "Customer data isolation with PostgreSQL row-level security (spec 002 FR-054, Constitution v2.0.0 Principle II), before the ledger. One customer can never read or change another customer's rows, even if application code forgets an ownership check. Staff authorization stays application-level. Deliver policies on the existing customer-owned tables, a safe per-transaction actor binding, an explicit and audited way for staff/system/auth-bootstrap paths to act across customers, and a pattern later modules follow. Prove isolation with tests that bypass application checks."

## Context

- **Why now**: Constitution v2.0.0 Principle II requires customer isolation to be enforced by the database, and spec 002 FR-054 schedules it right after spec 002 and before the ledger. Today only the helper functions that read the current actor exist; no isolation rule is switched on, so isolation depends entirely on every query remembering an ownership filter.
- **Sources**: Part 1 §5.1 (customer row-level security, per-transaction actor binding), `docs/Database schema/05_schema_security.sql` §19, spec 002 (staff authorization is application-level; the system actor). `docs/dahabctoblueprint.md` is not a reference.
- **Known defect this fixes**: the current actor is published for the whole database session rather than for one transaction. On a reused connection (queue workers are long-lived) one request's customer identity could still be set when the next request starts.

## Clarifications

### Session 2026-09-26

- Q: Are staff requests elevated automatically, or must each cross-customer operation opt in? → A: Automatically. Every authenticated staff request may see all customers' rows; staff permissions (spec 002) decide what staff may do. Row-level isolation protects customers from other customers, not from staff.
- Q: Which cross-customer elevations are written to the audit log? → A: The system actor (scheduled/queued jobs) and maintenance (migrations, seeders). Auth bootstrap and staff requests are not separately audited: their own events (sign-in, registration, staff actions) are already audited.
- Q: What may a customer context do with staff-side and shared tables? → A: A customer context may insert audit rows where it is the actor, and may not read `audit_log` or `document_view_log` at all. `personal_access_tokens` stays protected by authentication (it must be readable before any actor is known).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A customer can only ever see and change their own data (Priority: P1)

A signed-in customer uses the app normally: sees their profile, submits identity documents, manages trusted devices. Behind the scenes, even if a query in the application forgot to filter by owner, the database itself returns only that customer's rows and refuses to change anyone else's.

**Why this priority**: This is the whole point of the feature, and the guarantee every later module (listings, orders, wallet) inherits.

**Independent Test**: With two customers A and B, run as customer A a query that deliberately has no owner filter over each customer-owned table: only A's rows come back, and an attempt to update or delete B's row changes nothing (or is refused).

**Acceptance Scenarios**:

1. **Given** customer A is the current actor, **When** any read of a customer-owned table runs without an owner filter, **Then** only A's rows are returned.
2. **Given** customer A is the current actor, **When** an update or delete targets customer B's row, **Then** no row of B changes.
3. **Given** customer A is the current actor, **When** an insert tries to create a row owned by customer B, **Then** it is refused.
4. **Given** the normal customer flows (sign-in, `/me`, identity-document upload and submission, trusted devices, token refresh, sign-out), **When** they run with isolation switched on, **Then** they behave exactly as before.

---

### User Story 2 - No identity means no customer data (Priority: P1)

Any database work that runs without an established actor sees no customer-owned rows and can change none — it fails closed. A request never inherits the identity of a previous request on the same connection.

**Why this priority**: Fail-closed is what makes the protection real; the session-leak defect is a live cross-customer risk on long-lived workers.

**Independent Test**: Run a query over a customer-owned table with no actor set → zero rows. Set customer A for one unit of work, finish it, then run the next unit of work on the same connection without setting an actor → zero rows (A's identity did not carry over).

**Acceptance Scenarios**:

1. **Given** no actor is established, **When** a customer-owned table is read, **Then** no rows are returned; **When** it is written, **Then** the write is refused.
2. **Given** a unit of work bound to customer A has ended, **When** the next unit of work on the same connection starts without its own actor, **Then** it sees none of A's rows.
3. **Given** a long-lived worker processes jobs for different customers one after another, **When** each job runs, **Then** each sees only the data its own actor is entitled to.

---

### User Story 3 - Legitimate cross-customer work keeps working, explicitly and audited (Priority: P1)

Some work legitimately acts without a customer identity or across customers: registration, sign-in and new-device OTP (the customer is not identified yet), password reset, Dashboard staff work (customer lists, identity review — already authorized by staff permissions from spec 002), scheduled jobs acting as the system actor, seeders and migrations. These paths keep working, but only through an explicit, named elevation that the code must ask for, and that is recorded.

**Why this priority**: Without it, switching isolation on breaks sign-in, registration and the whole Dashboard.

**Independent Test**: Registration, customer sign-in (known and new device), staff customer list and identity review all succeed with isolation on; the same cross-customer queries without the elevation return nothing.

**Acceptance Scenarios**:

1. **Given** isolation is on, **When** a customer registers or signs in (including new-device OTP), **Then** it succeeds.
2. **Given** a staff member holding `customer.view`, **When** they list customers or open a customer, **Then** they see all customers as today; staff permissions (spec 002) still decide what they may do.
3. **Given** a scheduled job running as the system actor, **When** it processes several customers' rows, **Then** it can, and its writes are attributed to the system actor.
4. **Given** any cross-customer elevation, **When** it is used, **Then** it names its purpose and is recorded. Staff and auth-bootstrap elevations are traceable through their own audited events; system-actor and maintenance elevations write an audit row (Clarifications).
5. **Given** code that does not ask for elevation, **When** it runs outside a customer identity, **Then** it sees no customer rows (User Story 2).

---

### User Story 4 - Every future customer table follows the same pattern (Priority: P2)

When a later module adds a customer-owned table (listings, buy requests, orders, payout accounts, withdrawals, wallet accounts), the developer applies one documented pattern, and a build check fails if a customer-owned table is added without isolation.

**Why this priority**: The guarantee only holds if new tables cannot silently skip it; it is not needed to protect today's tables.

**Independent Test**: Add a throwaway table with a customer owner column and no isolation in a test; the check reports it.

**Acceptance Scenarios**:

1. **Given** the documented pattern, **When** a module adds a customer-owned table, **Then** it declares its owner rule in the same migration.
2. **Given** a table with a customer owner column and no isolation rule, **When** the check runs, **Then** it fails and names the table.

---

### Edge Cases

- **Two customers on one order** (future): the owner rule may name more than one owner column (buyer or seller); the pattern must allow it.
- **A customer reading their own audit history**: not a feature today; customers get no read access to the audit log.
- **Audit and security logs written during a customer request** (for example the customer's own sign-in audit row): must still be writable.
- **Connection reuse after an error**: an aborted unit of work must not leave an identity behind.
- **Staff token on a customer route / customer token on a staff route**: already refused by authentication (spec 001); isolation adds nothing and must not change those responses.
- **Migrations and seeders**: run with an explicit elevation, never by silently disabling isolation for the whole database.
- **Tests**: the suite must still be able to create fixtures for many customers; isolation assertions must run against the real database engine, not a substitute.
- **Suspended customers**: isolation is about whose rows, not what status; a suspended customer still sees their own rows.

## Requirements *(mandatory)*

### Functional Requirements

**Isolation**

- **FR-001**: The database MUST restrict every read and write of a customer-owned table, in a customer context, to rows owned by the current customer. It MUST hold even if the application query has no owner filter.
- **FR-002**: The restriction MUST also apply to the application's own database account (the table owner); it MUST NOT be bypassable just because the application owns the tables.
- **FR-003**: Customer-owned tables covered now: `customer`, `customer_password`, `customer_trusted_device`, `identity_document`, and customer-owned rows of `one_time_token`.
- **FR-005**: In a customer context, `audit_log` MUST accept inserts only for rows whose actor is the current customer and MUST return no rows on read; `document_view_log` MUST be neither readable nor writable. `personal_access_tokens` is not row-restricted (it is read before any actor is known) and stays protected by authentication.
- **FR-004**: With no established actor and no elevation, customer-owned tables MUST return no rows and accept no writes (fail closed).

**Actor binding**

- **FR-010**: The current actor MUST be bound for one unit of work — one request, one queued job, or one console command — and MUST be restored/cleared automatically when that unit ends, whether it succeeds or fails. No identity may carry over to the next request or job on the same connection.
- **FR-011**: The actor MUST come only from the authenticated session (or, for jobs, the system actor); never from request input.
- **FR-012**: Every customer-authenticated request MUST run its database work under the customer's binding.

**Elevation (cross-customer access)**

- **FR-020**: Work that legitimately acts outside a single customer's identity MUST use an explicit, named elevation: auth bootstrap (registration, sign-in, new-device OTP, password reset), staff Dashboard requests, the system actor (scheduled jobs), and maintenance (migrations, seeders).
- **FR-024**: Every authenticated staff request MUST run with the staff elevation automatically (Clarification Q1). Auth bootstrap, system and maintenance elevations MUST be requested explicitly by the code that needs them.
- **FR-021**: Elevation MUST be scoped to the unit of work that asked for it and end with it.
- **FR-022**: Elevation MUST NOT grant any staff permission: what a staff member may do is still decided by spec 002 permissions.
- **FR-023**: System-actor and maintenance elevations MUST write an audit row naming the purpose (job or command) and the actor (the system actor). Staff and auth-bootstrap elevations are not separately audited (their own events are).

**Pattern and enforcement**

- **FR-030**: A documented pattern MUST describe how a new customer-owned table declares its owner rule, including tables with more than one owner column.
- **FR-031**: An automated check MUST fail the build when a table with a customer owner column has no isolation rule.
- **FR-032**: `docs/Database schema/05_schema_security.sql`, Part 1 §5.1 and the constitution references MUST match what is implemented.

**Compatibility**

- **FR-040**: All existing customer and Dashboard behaviour (specs 001 and 002) MUST be unchanged with isolation on.
- **FR-041**: Named-actor guarantees (Constitution I) and audit writes MUST keep working in every context.

### Key Entities

- **Customer-owned table**: a table whose rows belong to one customer (or, later, to the two parties of a transaction), identified by owner column(s).
- **Actor binding**: the identity (customer, staff, or system) attached to one unit of database work.
- **Elevation**: a named, scoped permission for one unit of work to act across customers, with a purpose (auth bootstrap, staff, system, maintenance).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every customer-owned table, a query without an owner filter run as customer A returns 0 rows of customer B (verified for 100% of covered tables).
- **SC-002**: With no actor and no elevation, 100% of covered tables return 0 rows and refuse writes.
- **SC-003**: 0 cases of an identity carrying over between consecutive units of work on the same connection, including after an aborted one.
- **SC-004**: 100% of existing automated tests for specs 001 and 002 pass with isolation switched on.
- **SC-005**: Adding a customer-owned table without an isolation rule fails the build 100% of the time.
- **SC-006**: Customer-facing response times do not measurably degrade (no customer flow slower by more than 10%).

## Assumptions

- Staff authorization stays application-level (spec 002, Constitution v2.0.0): no per-staff-role database accounts or grants.
- Uploaded identity images are stored outside the database; only their database rows are in scope.
- Registration holds its state outside the customer table until the final submit (spec 001), so only the submit and later steps touch customer rows.
- Future customer tables (listings, buy requests, orders, payout accounts, withdrawals, ledger accounts) are protected by their own modules using this feature's pattern; they are out of scope here.
- Customers do not read the audit log or staff-side logs through any endpoint.
