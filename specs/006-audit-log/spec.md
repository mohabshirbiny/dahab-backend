# Feature Specification: Audit Log Viewer

**Feature Branch**: `feature/audit-log` (backend and dashboard; the Customer App has no repository yet)

**Created**: 2026-09-27

**Status**: Draft

**Input**: User description: "Audit log viewer (feature 006): the staff-facing read side of the existing append-only audit log (plus one audit entry per export, analysis S1). List entries newest first with filters and pagination, open one entry with its full before/after, reason, IP and device. Permissions from the Part 1 §4.3 matrix (CEO everything; COO, Finance, Operations, Verification own actions; IGI none) as dynamic catalogue codes. Backend-defined labels and categories per event. Dashboard 'Audit log' page per the design. No new writes; no change to what is audited."

## Context

- **Sources**:
  - Technical Spec Part 1 §6: every privileged action records who, when, from which device and IP, what changed (before/after) and a reason where required. The log is append-only; nobody can change or delete an entry, founders included.
  - Part 1 §4.3, matrix row "View the audit log": CEO everything; COO, Finance, Operations and Verification their own actions only; IGI none.
  - `docs/Database schema/05_schema_security.sql` (`audit_log`).
  - Dashboard design reference, screen "Audit log".
  - `docs/dahabctoblueprint.md` is not a reference.
- **What exists**: every feature so far already writes audit entries (about 50 kinds). They cover sign-in and sessions, identity review, access control, reference data and pricing. Nobody can read them yet except through the database.
- **Why now**: the platform now has people changing roles, karats, branches, prices and rates. The founders need to see who did what and why, and staff need to see their own trail. Every later money feature will add entries here.
- **Out of scope**:
  - writing new kinds of entries, or changing what existing features record;
  - real-time alerts to founders (a separate feature, pending the channel decision OI-1.3);
  - the Customer App (customers have no audit screen);
  - device names and locations (the platform stores an IP address and a device fingerprint, not a device model or city — see Assumptions).

## Clarifications

### Session 2026-09-27

- Q: Should "Everything" include sign-in and session entries? → A: No. "Everything" shows every category except **Sign-ins and sessions**, which has its own chip (token refreshes would otherwise dominate the list).
- Q: Is an export recorded in the audit log? → A: Yes (analysis S1). Reading the log on screen is not recorded, but each export writes one entry ("Audit log exported") with the filters used and the number of rows, because it takes data off the platform.
- Q: Is "Export to Excel" in scope? → A: Yes. A downloadable spreadsheet-readable file (CSV) of the entries matching the current filters, obeying the same visibility rules as the list.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The CEO reviews what happened (Priority: P1)

The CEO opens the Audit log and sees every recorded action, newest first. For each one it shows when it happened, who did it (a staff member, a customer or the system), what happened in plain words with its subject, the value before and after, and where it came from. The CEO narrows it by period and category, or by one person, and opens an entry to read the full details and the reason.

**Why this priority**: accountability is the reason the log exists; without a viewer the founders cannot check anything.

**Independent Test**: As the CEO, after a price entry, a role change and a karat toggle, the log lists all three with the right people, plain-language descriptions, before/after values and reasons. Filtering by "Pricing" keeps only the price entry.

**Acceptance Scenarios**:

1. **Given** entries from several staff members and customers, **When** the CEO opens the log, **Then** all entries appear newest first, with when, who, what (plus subject), before, after and origin (IP address).
2. **Given** the category chips of the design, **When** the CEO picks one, **Then** only entries of that category remain, and the count shows how many match.
3. **Given** a period (the last 7 days by default, or a chosen range), **When** it is applied, **Then** only entries inside it appear.
4. **Given** a person, an action kind or a subject, **When** the CEO filters by it, **Then** only matching entries appear.
5. **Given** an entry, **When** the CEO opens it, **Then** the full before and after values, the reason, the IP address and the device fingerprint are shown exactly as recorded.
6. **Given** more entries than fit on one page, **When** the CEO moves through pages, **Then** no entry is missed or shown twice, even while new entries arrive.

---

### User Story 2 - Staff see their own trail (Priority: P1)

A COO, Finance, Operations or Verification user opens the Audit log and sees only the actions they performed themselves, with the same filters and details.

**Why this priority**: the matrix gives these roles their own actions only. Showing more would leak other people's activity; showing nothing would hide their own record.

**Independent Test**: As Finance, after Finance and the CEO have both acted, the log lists only Finance's entries. Asking for someone else's entries, or opening one of their entries directly, is refused.

**Acceptance Scenarios**:

1. **Given** a staff member who may see their own actions only, **When** they open the log, **Then** only entries where they are the actor appear.
2. **Given** that staff member, **When** they filter by another person or open another person's entry by its identifier, **Then** it is refused or returns nothing. It never shows the other person's entry.
3. **Given** an IGI user (no audit permission), **When** they try to open the log, **Then** it is refused and the Audit log item is not in their menu.

---

### User Story 3 - Plain language, not codes (Priority: P2)

Every entry reads as a sentence staff understand ("Manual gold price entered", "Karat turned off", "Role permissions changed"), with a short subject ("21K", "IGI Nasr City", "Finance role"). The wording is defined once by the platform, so every screen and any future export says the same thing.

**Why this priority**: an audit trail people cannot read is not used.

**Independent Test**: Every kind of entry recorded today has a label and a category; a test fails if a new kind is added without one.

**Acceptance Scenarios**:

1. **Given** any recorded kind of action, **Then** it has a plain-language label and belongs to exactly one category.
2. **Given** a kind of action added in the future without a label, **Then** the platform's checks fail before release.

---

### Edge Cases

- **Entries with no before/after** (a sign-in, a document view): shown as "—".
- **Very large before/after values** (a whole weekly timetable, a permission list): the list shows a short summary; the full value is in the entry's details.
- **An actor that is the system** (scheduled work, maintenance): shown as "System".
- **An actor that is a customer**: shown with the customer's reference, not their personal details.
- **A staff member who was later disabled or renamed**: their past entries still show who they were.
- **Categories with no entries yet** (Money, Promo codes): the chip exists and shows an empty result, not an error.
- **Sign-in and session entries** are frequent (every token refresh is recorded): "Everything" leaves them out; the "Sign-ins and sessions" chip shows them (Clarification 1).
- **Viewing the log** on screen is not recorded as an audit entry (it would flood the log with reads); **exporting** it is, once per export (FR-011).
- **Time zone**: all times are shown in Cairo time.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST let permitted staff list audit entries, newest first, with pagination that is stable while new entries arrive.
- **FR-002**: Each listed entry MUST show: when; who (staff name, "System", or a customer reference); a plain-language description and a short subject; a summary of the before and after values; and the IP address it came from.
- **FR-003**: The list MUST be filterable by period (default: the last 7 days), category, actor (a staff member or System; customer activity is reached through the subject filter), action kind and subject (the kind and identifier of the thing changed), and MUST report how many entries match.
- **FR-004**: A permitted staff member MUST be able to open one entry and see everything recorded for it: full before and after values, reason, IP address, device fingerprint, actor and time.
- **FR-005**: Two permissions MUST govern access, both ordinary Dashboard-managed codes: **view everything**, seeded to the CEO; and **view own actions**, seeded to the COO, Finance, Operations and Verification. IGI gets neither. A holder of only "own actions" MUST NOT see, filter to, count, or open anyone else's entries.
- **FR-006**: Every kind of recorded action MUST have a plain-language label and exactly one category, defined by the platform. The categories are:
  - the design's: Money, Pricing, Accounts, Identity documents, Promo codes;
  - plus Reference data, Sign-ins and sessions, and System, for kinds the design does not place.

  A check MUST fail when a kind lacks either.
- **FR-007**: The log MUST remain read-only. Nothing in this feature can change or delete an entry. The only entry it adds is the export record (FR-011).
- **FR-008**: The Dashboard "Audit log" page MUST follow the design:
  - the lead sentence;
  - the category chips (the design's five plus the three extra, each shown even when empty);
  - the "Log" panel with the period and the entry count;
  - the table with When, Who, What (+ subject), Before, After, and Origin (the design's "Device");
  - an entry detail view.

  The menu item appears only for staff holding one of the two permissions.
- **FR-009**: "Export to Excel" MUST download a spreadsheet-readable file (CSV, UTF-8) of all entries matching the current filters — not just the current page — with the same columns as the list plus reason and actor, under the same visibility rules (a holder of "own actions" exports only their own). The export is capped (see Assumptions) and says so when the cap is reached.
- **FR-010**: The "Everything" view MUST exclude the Sign-ins and sessions category; every other category is included.
- **FR-011**: Each export MUST write one audit entry ("Audit log exported", category System) attributed to the staff member, holding the filters used, the number of rows, and whether the cap cut it short.

### Key Entities

- **Audit entry** (existing, unchanged): actor (staff or customer), action kind, subject kind and identifier, before and after values, reason, IP address, device fingerprint, time.
- **Action kind catalogue**: for each kind of recorded action, its label and category (defined by the platform).
- **Audit category**: Money, Pricing, Accounts, Identity documents, Promo codes, Reference data, Sign-ins and sessions, System.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: The CEO can find who changed a given setting, price or role in the last 7 days, and why, in under 1 minute.
- **SC-002**: 100% of kinds of recorded actions have a label and a category (enforced by a check).
- **SC-003**: 0 cases in tests where a staff member with "own actions" sees, counts or opens another person's entry.
- **SC-004**: A page of entries appears in under 2 seconds with up to 1 million entries in the log.
- **SC-005**: Nothing in the feature can modify an entry (verified by the existing database refusal and by tests).

## Assumptions

- "Device" in the design shows a device model and city. The platform records an IP address and a device fingerprint only, so the column is shown as **Origin** (IP address), with the fingerprint in the details. Adding device models or places is a separate change to what is recorded.
- Customer actors are shown by a customer reference (for example their display reference), not their name or phone. Personal details stay in the customer screens.
- Money and Promo codes have no entries until those features exist; their chips are shown and empty.
- The default period is the last 7 days, as in the design; any range can be chosen.
- One export holds at most 50,000 entries; a wider selection is narrowed by period.
- Reading the log on screen is not itself logged; exporting it is (FR-011).
- Customer IP addresses are visible to "view everything" holders, as recorded in the audit trail (Part 1 §6).
- Times are shown in Cairo time (the platform's time zone).
- The Customer App is not affected.
