# Feature Specification: Dynamic Staff Authorization, Customer Verified Gate, System Actor

**Feature Branch**: `claude/friendly-bell-1365c8`

**Created**: 2026-09-26

**Status**: Draft

**Input**: User description: "Dynamic staff authorization + customer verified gate + system actor (foundation before Marketplace/Orders). Staff roles and role→permission mappings are fully dynamic, managed from the Dashboard; the six current roles are only the initial seed. No DB-level authorization — everything lives in the permission tables and is enforced in the application. Branch scoping driven from the Dashboard. One seeded non-login system actor for scheduled jobs. Unverified customers can sign in; every other customer action requires verification except a short allow-list."

## Context & Decisions

This feature changes decisions recorded in the Technical Spec. The product owner's decisions (2026-09-26) win over the documents below, and those documents are updated as part of this feature:

| Decision | Replaces |
|---|---|
| Staff roles are data: created, renamed, and deleted from the Dashboard. The six current roles (`ceo`, `coo`, `finance`, `operations`, `verification`, `igi_branch`) are only the initial seed. | Part 1 §3.1 "Roles are fixed … new roles are a schema change"; `dahab-dashboard-authorization.md` §3 "Roles are schema, not admin data". |
| Which permissions a role holds is data, edited from the Dashboard. The Part 1 §4 matrix becomes the **initial seed**, not a fixed rule. | Part 1 §4 "The permission matrix (authoritative, as data)" — stays as the seed. |
| **Staff** authorization is never enforced by database roles or grants. It is enforced by the application from the permission tables. | Part 1 §3.3 (database role per staff role), §4.4 (double enforcement of wallet actions), §5.2 (wallet visibility is a grant). |
| **Customer** data isolation stays enforced by the database (row-level security) as defense in depth, so one customer can never read or change another customer's rows even if application code has a bug. | Unchanged: Part 1 §5.1 stays in force. |
| Founder status cannot be changed from the Dashboard by anyone, including role managers. It is set only by the seeder or a controlled manual database operation. | New rule. |
| Creating, disabling, and enabling staff accounts is a separate, later feature. | — |
| Scheduled jobs act as one dedicated **system actor**. | Fills the gap in Part 2 §11 ("runs as a system actor") — no such actor exists today. |
| An unverified customer may sign in; everything else needs verification, except a short allow-list (FR-030). Wallet top-up needs verification. | Part 1 §2.2 (which let unverified customers top up and gated only the first trade action). |

`docs/dahabctoblueprint.md` is **not** a reference for this feature.

## Clarifications

### Session 2026-09-26

- Q: Can a staff member with `roles.manage` grant permissions they don't hold themselves, or edit the roles they themselves hold? → A: No. A role manager can only add or remove permissions they hold, cannot edit any role they hold, and cannot change their own role assignment.
- Q: Should founders always need MFA, whatever roles they hold, even if "requires MFA" is turned off on their roles? → A: Yes. Founders always need MFA; the role flag decides only for non-founders.
- Q: Is customer row-level security switched on in this feature? → A: No. It is a separate, small feature delivered right after this one and before the ledger. This feature must not weaken it.
- Q: Do role and permission changes need a written reason? → A: Required for changing a role's permissions or MFA flag, deleting a role, and changing a staff member's roles. Optional for creating a role or editing its display name or description.
- Q: Can a rejected customer sign in? → A: Yes. A rejected customer can log in and re-submit identity documents for another review. They are authenticated but remain unverified: `/me` and identity-document re-submission work, and every protected or trading action returns `403 verification_required`.
- Q: Is the wishlist on the unverified allow-list? → A: Not decided in this feature. Wishlist behavior is out of scope until the wishlist feature is implemented; it is removed from the FR-030 allow-list.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Manage roles and their permissions from the Dashboard (Priority: P1)

A staff member who may manage access (initially the CEO) opens "Staff and permissions" in the Dashboard. They see every role, how many staff hold it, and its permissions. They create a new role (for example "Customer support"), tick the permissions it should have from the full catalogue, and save. They also edit an existing role, for example removing a permission from Operations. The next request each affected staff member makes is decided with the new permission set.

**Why this priority**: This is the core ask. Every later module (listings, orders, inspection, wallet) adds permissions; they must land on an editable model, not a fixed one, or the work is redone.

**Independent Test**: With seeded staff, a role manager creates a role, grants it `customer.view`, assigns it to a staff member who lacked that permission, and that staff member can then open the customer list; removing the permission from the role makes the next request return `403 permission_denied`.

**Acceptance Scenarios**:

1. **Given** a staff member holding `roles.manage`, **When** they list roles, **Then** they see each role's name, display name, description, "requires MFA" flag, permission list, and number of staff holding it.
2. **Given** a staff member holding `roles.manage`, **When** they create a role with a unique name and a set of permissions from the catalogue, **Then** the role exists with exactly those permissions and the change is written to the audit log with the actor, before and after.
6. **Given** a role manager changing a role's permissions, **When** they submit without a reason, **Then** it is refused with `422 reason_required` and nothing changes.
3. **Given** an existing role, **When** a role manager replaces its permission set, **Then** every staff member holding that role is authorized with the new set on their next request, with no sign-out needed.
4. **Given** a staff member without `roles.manage`, **When** they call any role-management action, **Then** they receive `403 permission_denied` and the denial is audit-logged.
5. **Given** a request to grant a permission code that is not in the catalogue, **When** it is submitted, **Then** it is refused with a validation error and nothing changes.

---

### User Story 2 - Assign roles to staff members (Priority: P1)

A role manager opens a staff member's record and sets which roles that person holds (one or more). The staff member's effective permissions become the union of their roles' permissions.

**Why this priority**: Roles are useless until they can be given to people; together with Story 1 this is the minimum viable slice.

**Independent Test**: Assign an Operations staff member the Verification role in addition; their `/me` permissions now include `identity.review` and they can review a document; remove it and they cannot.

**Acceptance Scenarios**:

1. **Given** a role manager, **When** they list staff, **Then** they see each staff member's name, email, active status, roles, and branch, and the system actor is not in the list.
2. **Given** a role manager, **When** they set a staff member's roles, **Then** the staff member holds exactly those roles, the change is audit-logged, and it applies on the staff member's next request.
3. **Given** a change that would leave **no active staff member** holding `roles.manage`, **When** it is submitted (removing a role from a person, removing the permission from a role, or deleting a role), **Then** it is refused with `409 last_role_manager` and nothing changes.
4. **Given** a staff member, **When** they sign in or call `/me`, **Then** the response lists their roles (name and display name) and effective permissions.

---

### User Story 3 - Customer verified gate (Priority: P1)

A customer who registered but is not verified yet (pending, or rejected and trying again) can sign in, see their profile, upload or re-upload identity documents and follow their status, and browse the marketplace. Wishlist behavior is out of scope until the wishlist feature is implemented. Any other action, such as listing a piece, placing a buy request, or topping up the wallet, is refused with a clear "verify first" response.

**Why this priority**: Every trade module built next (listings, buy requests, wallet) must sit behind this gate. It has to exist before them.

**Independent Test**: An unverified customer signs in successfully and can call `/me` and upload a document; the same customer calling a gated endpoint receives `403 verification_required`; after approval the same call succeeds.

**Acceptance Scenarios**:

1. **Given** an unverified customer with correct credentials, **When** they sign in, **Then** sign-in succeeds exactly as for a verified customer.
2. **Given** an unverified customer, **When** they call an allow-listed action (FR-030), **Then** it succeeds.
3. **Given** an unverified customer, **When** they call any customer action not on the allow-list, **Then** it is refused with `403 verification_required` and nothing changes.
4. **Given** a verified but suspended customer, **When** they call a trade action, **Then** it is refused with `403 account_suspended`, as Part 1 §2.2 already specifies. Reading their own data still works.
5. **Given** a newly added customer endpoint, **When** it is registered without being placed on the allow-list, **Then** it is gated by default.

---

### User Story 4 - MFA and branch follow the staff member, not a fixed role name (Priority: P2)

Whether a staff member must use MFA is a flag on each role, so a new role can require MFA. A staff member may be tied to one branch. Permissions marked branch-scoped only let them act on records for that branch.

**Why this priority**: Without this, dynamic roles would silently drop MFA for new sensitive roles and lose the IGI branch restriction. It is needed before the inspection module, but not for the first role-management demo.

**Independent Test**: Create a role with "requires MFA" on and assign it to a staff member who had no MFA; their next sign-in requires MFA enrollment. Turn the flag off and the requirement is gone on the next sign-in, unless another role still requires it.

**Acceptance Scenarios**:

1. **Given** a founder, or a staff member holding at least one role with "requires MFA", **When** they sign in, **Then** MFA is required (enrollment if not yet enrolled), exactly as today for `ceo`, `coo`, `finance`.
2. **Given** a non-founder staff member none of whose roles require MFA, **When** they sign in, **Then** MFA is not forced. If they enrolled voluntarily, their MFA is still challenged, as today.
3. **Given** the seed, **When** the system is freshly installed, **Then** `ceo`, `coo`, `finance` require MFA and hold the same permissions they hold today, so existing behaviour is unchanged.
4. **Given** a permission declared branch-scoped in the catalogue, **When** a staff member holding it acts on a record of another branch, **Then** it is refused with `403 wrong_branch`. With no branch assigned, a branch-scoped permission grants nothing.

---

### User Story 5 - System actor for scheduled work (Priority: P2)

Scheduled jobs that change state record a single, clearly named "System" actor in the audit log and ledger. They never borrow a real employee's account and never lack an actor.

**Why this priority**: It is needed by the first deadline sweep (seller-reply expiry in the buy-request module), not by role management itself.

**Independent Test**: Run any action "as the system actor" in a test; the audit row names the system actor; the system actor cannot sign in and does not appear in staff lists or role assignment.

**Acceptance Scenarios**:

1. **Given** a fresh install, **When** seeding completes, **Then** exactly one system actor exists.
2. **Given** the system actor, **When** anyone tries to sign in as it, **Then** it is refused the same way as unknown credentials. It has no password and no MFA.
3. **Given** role and staff management, **When** listing or assigning, **Then** the system actor never appears and cannot be given roles.
4. **Given** a scheduled job, **When** it writes an audit or ledger entry, **Then** the entry is attributed to the system actor.

---

### Edge Cases

- **Deleting a role that staff still hold**: refused with `409 role_in_use`, which reports the count. Staff must be reassigned first.
- **Renaming a role**: allowed for the display name. The machine name is immutable once created, so audit history stays readable.
- **Deleting a seeded role** (for example `igi_branch`): allowed like any other role, subject to `role_in_use` and `last_role_manager`.
- **A staff member with zero roles**: allowed. They can sign in and see only screens needing no permission.
- **A role manager editing their own role or their own assignment** (including removing `roles.manage` from themselves): refused with `403 escalation_denied` (FR-026). Another role manager must make the change.
- **The COO (holding `roles.manage` but no wallet permissions) tries to add a wallet permission to any role, or to remove one from the `ceo` role**: refused with `403 escalation_denied` (FR-025).
- **Deactivated staff**: do not count as holders for `last_role_manager`.
- **Frozen or deactivated staff with a live session**: every request is refused before permissions are looked at (`403 account_frozen`, or `401 unauthenticated` for deactivated staff). Signing out still works (FR-056).
- **Two role managers editing the same role at once**: last write wins. Both changes are in the audit log with their before and after.
- **A permission code removed from the catalogue in a later release**: it disappears from every role and is no longer shown in the Dashboard.
- **Identity-document re-upload after rejection**: on the allow-list, because an unverified or rejected customer must be able to finish verification.
- **A customer whose verification is revoked or rejected after being verified**: the gate reads the live state on every request, so the next gated request is refused.

## Requirements *(mandatory)*

### Functional Requirements

**Permission catalogue**

- **FR-001**: The system MUST keep one catalogue of permission codes, defined by the product. Each entry has a code, a human label, a group (for Dashboard display), and whether it is branch-scoped. Dashboard users cannot create or delete permission codes, because each code protects a specific action.
- **FR-002**: The system MUST expose the catalogue to role managers so the Dashboard can render it grouped and labelled.
- **FR-003**: The catalogue MUST include a new permission, `roles.manage`, that gates every role, permission-assignment, and staff-role-assignment action in this feature, and a `staff.view` permission for listing staff.

**Roles**

- **FR-010**: Role managers MUST be able to create a role with: a unique machine name (immutable after creation), a display name, an optional description, a "requires MFA" flag, and a set of permissions from the catalogue.
- **FR-011**: Role managers MUST be able to edit a role's display name, description, "requires MFA" flag, and permission set.
- **FR-012**: Role managers MUST be able to delete a role that no staff member holds. Deleting a held role MUST be refused with `409 role_in_use`.
- **FR-013**: Role managers MUST be able to list roles with their permissions and holder counts, and view a single role.
- **FR-014**: A change to a role's permissions MUST take effect on each holder's next request. No new sign-in is required.

**Staff role assignment**

- **FR-020**: Holders of `staff.view` MUST be able to list and view staff members (excluding the system actor) with roles, active status, founder flag (read-only), and branch. Assigning roles needs `roles.manage`. The seed gives both to `ceo` and `coo`, and a role meant for role management should hold both, or its holders cannot find the staff to assign.
- **FR-021**: Role managers MUST be able to set the full list of roles a staff member holds (zero or more).
- **FR-022**: A staff member's effective permissions MUST be the union of the permissions of all roles they hold.
- **FR-023**: Any change that would leave zero active, non-system staff holding `roles.manage` MUST be refused with `409 last_role_manager`. (A backstop: with FR-026 the acting manager always keeps `roles.manage`, so today it cannot trigger through the API. It guards future paths such as staff deactivation.)
- **FR-025**: A role manager MUST only add or remove permissions they currently hold. This applies when creating a role and when editing one. Otherwise the change is refused with `403 escalation_denied` and nothing changes.
- **FR-026**: A role manager MUST NOT edit or delete any role they currently hold, and MUST NOT change their own role assignment. Otherwise the change is refused with `403 escalation_denied`. When giving roles to another staff member, a role manager may only assign or remove roles whose every permission they hold.
- **FR-024**: The authenticated staff profile (sign-in response and `/me`) MUST list the staff member's roles (machine and display names) and effective permissions.

**MFA, founders, branch**

- **FR-040**: MFA MUST be required for a staff member when they are a founder, or when at least one of their roles has "requires MFA". It MUST NOT depend on a hard-coded list of role names. No role edit can remove MFA from a founder.
- **FR-041**: A staff member MAY be assigned at most one branch. Permissions marked branch-scoped MUST only authorize actions on records of that branch, and MUST authorize nothing when no branch is assigned. The refusal is `403 wrong_branch`.
- **FR-042**: Founder status (used by founder new-device approval, freezing, and founder MFA re-enrollment on password reset) MUST be a property of the staff member, not derived from a role name.
- **FR-043**: Founder status MUST NOT be changeable through any Dashboard or API action, by anyone, including holders of `roles.manage`. It is set only by the seeder or a controlled manual database operation. The staff profile and staff list MAY show it read-only. Seed: the `ceo` and `coo` staff accounts are founders.
- **FR-044**: Assigning or removing roles MUST NOT change founder status. Founder protections (new-device approval by the other founder, freeze) keep applying to founders whatever roles they hold.

**Enforcement and audit**

- **FR-050**: Every staff endpoint that needs a permission MUST be checked by the application against the staff member's effective permissions. No **staff** authorization rule may depend on database roles or grants.
- **FR-054**: Customer data isolation MUST remain enforced by database row-level security as defense in depth (Part 1 §5.1). Switching the policies on is a separate feature, delivered right after this one and before the ledger. Nothing in this feature may weaken or bypass it, or make it harder to switch on. Staff access to customer data is decided by application permissions (FR-050), not by per-role database grants.
- **FR-051**: Wallet-related permissions (when their modules land) MUST be ordinary catalogue permissions. The seed MUST NOT grant them to `coo`, which keeps today's rule "COO has everything except wallets" as the starting state, now editable.
- **FR-052**: Every create, edit, or delete of a role, every change to a role's permissions, and every change to a staff member's roles MUST be written to the audit log with actor, time, IP and device, before and after values.
- **FR-055**: A written reason MUST be supplied when changing a role's permissions or its "requires MFA" flag, deleting a role, or changing a staff member's roles. Without it the change is refused with `422 reason_required` (Part 1 §6). The reason is optional when creating a role or editing only its display name or description. Any supplied reason is stored in the audit entry.
- **FR-056**: A frozen staff member (open account freeze) MUST be refused on **every** Dashboard request, not only at sign-in, with `403 account_frozen` (Part 1 §4.4, §8). A deactivated staff member MUST be refused with `401 unauthenticated`, and their current session revoked. Signing out stays allowed for a frozen account.
- **FR-053**: A denied permission check MUST keep returning `403 permission_denied` and be audit-logged, as today.

**Customer verified gate**

- **FR-030**: An unverified customer (status `pending_verification` or `rejected`) MUST be allowed only these actions: sign in, sign out, refresh session, new-device OTP verification, read their own profile (`/me`), upload identity documents and uploads needed for verification, see their verification status, and browse the public marketplace. A **rejected** customer can sign in, stays authenticated but unverified, can access `/me`, and can re-submit identity documents for another review; every protected or trading action returns `403 verification_required` until a review approves them. **Wishlist behavior is out of scope** for this feature: it is decided and allow-listed (or gated) when the wishlist feature is implemented.
- **FR-031**: Every other customer action MUST be refused for an unverified customer with `403 verification_required`. Gating MUST be the default for new customer endpoints; the allow-list is explicit.
- **FR-032**: A suspended customer MUST keep the Part 1 §2.2 behaviour: sign in and read own data allowed, trade actions refused with `403 account_suspended`.
- **FR-033**: The gate MUST read the customer's live verification and suspension state on each request, never a value cached in the session token.

**System actor**

- **FR-060**: Exactly one system actor MUST exist after seeding. It is a staff record flagged as system, with no usable credentials.
- **FR-061**: The system actor MUST NOT be able to sign in, MUST NOT appear in staff lists, and MUST NOT be assignable to roles.
- **FR-062**: Scheduled and background state changes MUST be attributable to the system actor, so audit and ledger "actor required" rules keep holding.

**Seed, migration, docs**

- **FR-070**: A fresh install MUST seed the six roles with today's permissions (the current `customer.view`, `customer.suspend`, `identity.view`, `identity.review` mapping), plus `roles.manage` and `staff.view` for `ceo` and `coo` (Part 1 §4.3 corrected reading: both founders may change permissions). MFA is flagged on `ceo`, `coo`, `finance`. The `ceo` role MUST be seeded with every permission in the catalogue, and every permission newly added to the catalogue in a later release MUST be given to `ceo` (and to its other seed roles) when it first appears, so there is always a role able to grant it (needed by FR-025). Seeding MUST NOT overwrite role permissions already edited from the Dashboard. It only adds permissions that are new to the catalogue and removes codes that left the catalogue.
- **FR-071**: Existing staff MUST keep their current effective permissions after the change: each keeps the role matching their former fixed role.
- **FR-072**: The rule that an IGI account must have a branch MUST no longer be tied to a role name. Branch is optional for everyone.
- **FR-073**: `docs/Technical Spec/dahab-dashboard-authorization.md` and the affected Part 1 sections (§2.2, §3.1, §3.2, §3.3, §4 intro, §4.4, §5.2) MUST be updated to state the new decisions. The Constitution is amended (v2.0.0): Principle II keeps row-level security for customer data isolation and drops database grants for staff/wallet visibility; Principle III no longer lists `dahabctoblueprint.md` as part of the contract.

**Out of scope**

- **FR-080**: Creating, disabling, and enabling staff accounts is NOT part of this feature. It is a separate future feature. This feature manages roles, role permissions, and role assignment for **existing** staff only.

### Key Entities

- **Permission**: A product-defined capability code (for example `identity.review`) with label, group, and branch-scoped flag. Fixed by the product, not editable in the Dashboard.
- **Role**: A named, Dashboard-managed bundle of permissions, with a machine name, display name, description, and "requires MFA" flag. Held by zero or more staff.
- **Staff member**: A Dashboard user. Holds zero or more roles, an optional branch, a founder flag (read-only from the Dashboard), an active flag, and a system flag (true only for the system actor).
- **System actor**: The single staff record representing automated work. It cannot sign in and is hidden from management.
- **Customer verification state**: The existing verified and suspended status the gate reads live.
- **Audit entry**: Existing append-only log, extended with role and assignment change events.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A role manager can create a new role with a chosen set of permissions and give it to a staff member in under 2 minutes, without any code change, deployment, or database access.
- **SC-002**: 100% of permission changes apply to the affected staff on their very next request. No staff member needs to sign out and in again.
- **SC-003**: After the migration, every existing staff member has exactly the same effective permissions and MFA requirement as before (verified for all seeded accounts).
- **SC-004**: No sequence of role or assignment edits can leave the platform with zero people able to manage roles. Every such attempt is refused.
- **SC-005**: 100% of customer endpoints not on the allow-list refuse unverified customers. The allow-list is an explicit, reviewable list, and new endpoints are gated unless added to it.
- **SC-006**: 100% of role and assignment changes appear in the audit log with actor and before/after values.
- **SC-007**: Automated state changes are attributed to the system actor in 100% of cases, and the system actor cannot be used to sign in.
- **SC-009**: No staff member can raise their own access. Every attempt to grant a permission you do not hold, or to edit a role you hold, is refused.
- **SC-008**: No Dashboard or API action, by any staff member, can make a staff member a founder or remove founder status.

## Assumptions

- The permission catalogue grows with each module (listings, orders, inspection, wallet). Each module's spec adds its codes and their seed role mapping, taken from the Part 1 §4 matrix.
- Staff branch assignment needs the branch reference data, which lands in the next feature (reference data and settings). This feature adds the optional branch on staff and the branch-scoped enforcement rule. The Dashboard action to pick a staff member's branch ships once branches exist.
- The account-freeze check and founder new-device approval keep working unchanged, reading the founder flag instead of role names.
- Customer data isolation stays a database-enforced rule (row-level security) per the amended Constitution Principle II. Today only the helper functions exist and no policy is switched on. Switching them on is the next feature, before the ledger.
- New staff accounts keep being created by the seeder until the staff-account feature ships.
- The Dashboard currently shows the single `role` field of the staff profile (sidebar and layout labels). The field stays, deprecated, until the Dashboard switches to `roles`. This is reported as Dashboard impact.
- Role display names are single-language in this feature. Arabic labels can be added later.
- Role and assignment changes are rare, so "last write wins" is acceptable for concurrent edits.
