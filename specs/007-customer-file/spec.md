# Feature Specification: Customer File v1

**Feature Branch**: `feature/customer-file` (backend and dashboard; the Customer App has no repository yet)

**Created**: 2026-09-28

**Status**: Draft

**Input**: User description: "Customer file v1 (feature name: customer-file, spec 007). Dashboard People → Customer file page: everything staff need about one customer in one place. Scope: (1) profile — identity fields, contact, status, verification state and every identity document (reusing the existing verification details); opening the file is audited; (2) suspend with a reason from the fixed SuspendedReason list plus a note, and reinstate — Part 2 §543, permission customer.suspend, audited, reason required, idempotent; a suspended customer can still sign in and read but trade actions are refused with 403 account_suspended (Part 1 §4.3); (3) activity — two lists: audit events about the customer (reusing spec 006 audit log) and the customer's own sign-ins/sessions/devices. Out of scope: orders, listings, wallet history, payout account, weight-difference stats — omitted entirely (no placeholders), added by their own features later. Multi-project: Backend + Dashboard; Customer App impact to be analysed (suspended state display)."

## Context

- **Sources**:
  - Technical Spec Part 1 §2.2 and §4.3: a suspended customer can still sign in and read their own data (to see why, withdraw a remaining balance and wind down open orders); every trade action is refused. Suspension always carries a reason from a fixed list and a named staff actor. Both founders (CEO, COO) may suspend and reinstate.
  - Part 2 §543: suspend / reinstate — permission "Suspend / reinstate a user account", audited, reason required, idempotent.
  - Part 1 §6: privileged actions are audited; viewing identity documents is audited.
  - `docs/Technical Spec/dahab-dashboard-authorization.md`: `customer.view` (CEO, Verification), `customer.suspend` (CEO, COO).
  - Dashboard design reference, screen "Customer file" (People section).
  - `docs/dahabctoblueprint.md` is not a reference.
- **What exists**:
  - the "Users and verification" list and a customer's verification details (every identity document), opening which is already audited;
  - the customer lifecycle states (waiting for verification, verified, rejected, suspended) and the suspended trade block;
  - the permission to suspend and the audit kinds "Account suspended" / "Account reinstated" — but no way for staff to actually suspend or reinstate;
  - the audit log viewer (spec 006), trusted devices recorded at sign-in, and session tokens per sign-in.
- **Why now**: staff have no single place to understand a customer, and the founders cannot act on a problem account. Every later feature (orders, listings, wallet) adds its panel to this file.
- **Out of scope** (omitted entirely, no placeholders): orders, listings, wallet balances and history, payout account, weight-difference statistics, customer type (ordinary / market maker), the "Full case file" export; automatic suspension by the platform (karat/counterfeit rules, cancellation threshold) — those come with the order features; ending a customer's sessions from the Dashboard.

## Clarifications

### Session 2026-09-28

- Q: Include suspend / reinstate? → A: Yes.
- Q: What does the activity section show? → A: Both — audit events about the customer, and their sign-ins, sessions and devices.
- Q: Panels for features not built yet? → A: Omitted entirely, no placeholders.
- Q: Which suspension reasons? → A: The design's seven, replacing the five existing codes.
- Q: Which customers may be suspended? → A: Any state; reinstate restores the pre-suspension state.
- Q: (found while building US3) Sign-out and revocation delete the session tokens, so ended sessions leave no row. What does "Sign-ins and devices" show? → A: The sessions still open, plus every device. Past sign-ins, sign-outs and revocations are in History (the audit log). No session history table in this feature.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Staff open one customer's file (Priority: P1)

A staff member with the customer view permission opens a customer from the list (or by their reference) and sees, on one page: who the customer is (full name, reference, phone, email, where they live, when they joined), their current state (waiting, verified, rejected or suspended — and for suspended, the reason, who suspended them and when), and every identity document they ever submitted with its review outcome. Opening the file is recorded in the audit log, because it shows identity documents.

**Why this priority**: every other part of the file (and every later feature's panel) hangs off this page.

**Independent Test**: As Verification, open a verified customer with two submitted documents; the page shows the profile, "Verified", both documents with their outcomes, and the audit log gains one "file opened" entry naming the staff member and the customer.

**Acceptance Scenarios**:

1. **Given** a customer, **When** a permitted staff member opens their file, **Then** the profile, current state and all identity documents (newest first) are shown.
2. **Given** a suspended customer, **When** the file is opened, **Then** the state shows the suspension reason, the note, who suspended them and when.
3. **Given** any file opening, **Then** exactly one audit entry records who opened which customer's file, from where and when.
4. **Given** a staff member without the customer view permission, **When** they try to open a file, **Then** it is refused and the Customer file item is not in their menu.
5. **Given** an identifier that does not match a customer, **When** it is opened, **Then** a "not found" result is shown, not an error page.

---

### User Story 2 - A founder suspends or reinstates an account (Priority: P1)

A founder (or anyone holding the suspend permission) opens a customer's file and presses Suspend. They pick a reason from the fixed list, write a note for the record, and confirm. From then on the customer can still sign in and see their data, but every trade action is refused as "account suspended". Later the founder reinstates the account, again with a note, and the customer returns to the state they had before.

**Why this priority**: the founders cannot act on a problem account today; the permission exists with nothing behind it.

**Independent Test**: As the COO, suspend a verified customer with a reason and note; the customer's next trade-gated request is refused with "account suspended" while sign-in and reading still work; the audit log shows "Account suspended" with the reason and note. Reinstate; trade-gated requests work again and the log shows "Account reinstated".

**Acceptance Scenarios**:

1. **Given** a customer who can be suspended (see FR-006), **When** a permitted staff member suspends them with a reason and a note, **Then** the customer becomes suspended with that reason, the staff member as actor and the time, and one audit entry records before/after state, reason and note.
2. **Given** a suspension request with no reason, a reason outside the fixed list, or no note, **Then** it is refused with a validation error and nothing changes.
3. **Given** a suspended customer, **When** they sign in or read their own data, **Then** it works; **When** they attempt a trade action, **Then** it is refused with "account suspended".
4. **Given** a suspended customer, **When** a permitted staff member reinstates them with a note, **Then** they return to their pre-suspension state, the suspension details are cleared from the live record, and one audit entry records it.
5. **Given** the same suspend (or reinstate) request sent twice (a retry), **Then** the second changes nothing and returns the same result; only one audit entry exists.
6. **Given** a staff member without the suspend permission, **Then** the Suspend / Reinstate controls are not shown and a direct request is refused.
7. **Given** a customer who is already suspended, **When** a different suspend request arrives, **Then** it is refused as a conflict (the account must be reinstated first); **Given** a customer who is not suspended, **When** reinstate is requested, **Then** it is refused as a conflict.

---

### User Story 3 - Staff see what happened on the account (Priority: P2)

On the same file, staff see two activity lists: **History** — what happened to and by this customer as recorded in the audit log (registration, identity submissions and reviews, suspensions, sign-ins, staff opening the file), newest first, in the same plain language as the Audit log; and **Sign-ins and devices** — the devices the customer has used (first and last seen) and the sessions still open (when started, last active, when they expire). Past sessions appear in History as sign-in and sign-out entries.

**Why this priority**: staff need context before deciding to suspend, and to answer "was that really me?" calls; it depends on the file (US1) existing.

**Independent Test**: For a customer who registered, submitted a document, was reviewed and signed in on two devices, History lists those entries in order with labels, and Sign-ins and devices shows two devices and their sessions.

**Acceptance Scenarios**:

1. **Given** a customer with recorded activity, **When** the file is opened, **Then** History lists audit entries where the customer is the actor or the subject, newest first, paged, with the Audit log's labels.
2. **Given** a staff member viewing History, **Then** the entries shown follow the viewer's audit visibility (FR-011).
3. **Given** a customer who signed in on several devices, **Then** Sign-ins and devices lists each device once with first-seen and last-seen times, and each session still open with start, last-active and expiry; a signed-out session is no longer listed, and its sign-out is in History.
4. **Given** a customer with no sessions yet (never signed in after registering), **Then** the list shows an empty state, not an error.

---

### User Story 4 - The customer sees they are suspended (Priority: P3)

A suspended customer who signs in to the Customer App is told plainly that the account is suspended and why (the reason in plain words, not the staff note), and that they can still see their data. When they try a trade action they get the same message rather than a generic error.

**Why this priority**: Part 1 §2.2 says they must be able to see why; today the app ignores the suspended flag. Lower priority because only trade actions (not built yet) are blocked.

**Independent Test**: Sign in as a suspended customer in the Customer App; a notice shows the account is suspended with the plain-language reason; reinstate the customer and, after the next session refresh, the notice disappears.

**Acceptance Scenarios**:

1. **Given** a suspended customer, **When** they sign in or their session is restored, **Then** a suspended notice with the plain-language reason is shown.
2. **Given** the customer is reinstated, **When** their profile is next loaded, **Then** the notice is gone.

---

### Edge Cases

- **Suspending a customer who is waiting for verification or rejected**: allowed; reinstating returns them to waiting or rejected (FR-006).
- **A customer suspended before this feature** (seeded or legacy data with no note): the file shows the reason and "—" for the note.
- **The suspending staff member is later disabled or renamed**: the file and History still show who they were at the time.
- **Two founders suspend the same customer at the same moment**: one succeeds, the other gets the conflict of US2 scenario 7 (or the idempotent replay if it is the same request); the state is never written twice.
- **Suspending during an identity review**: a pending identity document stays pending; reinstating returns the customer to waiting (FR-006).
- **Very active customers**: History and sessions are paged; the file itself opens fast regardless of how much activity there is.
- **Token refreshes** are frequent: History leaves raw token refreshes out by default (as the Audit log's "Everything" does) but keeps sign-ins, sign-outs and new-device approvals.
- **Time zone**: all times in Cairo time.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Staff with the customer view permission MUST be able to open one customer's file showing: full name, customer reference, phone, email, governorate, joined date, preferred language, current state, and — when suspended — reason, note, suspending staff member and time.
- **FR-002**: The file MUST include every identity document the customer ever submitted, newest first, with its type, submission time, review outcome, reviewer and rejection reason, as the existing verification details do. Document images open through the existing audited image view.
- **FR-003**: Opening a file MUST write exactly one audit entry (actor, customer, time, IP, device). It MUST reuse the existing "verification details viewed" record rather than add a second entry for the same opening.
- **FR-004**: Staff with the suspend permission MUST be able to suspend a customer by choosing a reason from the fixed list (FR-005) and writing a note (required, 1–1000 characters).
- **FR-005**: Suspension reasons MUST come from one fixed, platform-defined list — the design's seven: piece not as described, dealing outside Dahab, repeated disputes, reported by other users, identity could not be confirmed, customer asked to close, something else. Each has a plain-language label for staff and a customer-facing wording. They replace the five earlier reason codes, which no record uses (nothing could suspend before this feature).
- **FR-006**: Customers in any state (waiting for verification, verified, rejected) MAY be suspended. A suspension MUST remember the customer's state before it, and reinstating MUST return them to exactly that state.
- **FR-007**: Staff with the suspend permission MUST be able to reinstate a suspended customer with a required note. Reinstating clears the live suspension details; the history stays in the audit log.
- **FR-008**: Suspend and reinstate MUST be idempotent: a retried request with the same idempotency key returns the original result and changes nothing further. A conflicting request (suspend an already-suspended customer, reinstate one who is not suspended) MUST be refused with a conflict error.
- **FR-009**: Suspend and reinstate MUST each write one audit entry with the before and after state, the reason code (suspend), the note, and the staff actor.
- **FR-010**: A suspended customer MUST still be able to sign in, refresh and end their session, and read their own profile; every trade-gated action MUST be refused with the existing "account suspended" error. (Existing behaviour — this feature verifies it end to end.)
- **FR-011**: The file's History MUST list audit entries where the customer is the actor or the subject, newest first, paged, labelled and categorised as in the Audit log, excluding raw token refreshes. It MUST follow the viewer's audit permissions: holders of "view everything" see all such entries; holders of "view own actions" see only those they performed; staff with neither see no History panel.
- **FR-012**: The file MUST list the customer's devices (first seen, last seen) and their open sessions (started, last active, expires), newest first, paged. Ended sessions are not kept by the platform (sign-out deletes them); their sign-in and sign-out entries are in History. It MUST NOT expose token values or raw device fingerprints — only a short device identifier.
- **FR-013**: The Dashboard "Customer file" page MUST follow the design's profile, identity, History and Suspend sections, omitting every panel whose feature is not built (orders, listings, wallet, payout account, weight difference, customer type, full case file). It is reachable from the "Users and verification" list and from the People → Customer file menu item (search by reference or phone), shown only to staff with the customer view permission.
- **FR-014**: The Customer App MUST show a suspended customer a notice with the plain-language reason (never the staff note) after sign-in and session restore, and MUST show the same message when a request is refused as "account suspended".

### Key Entities

- **Customer** (existing): identity and contact fields, lifecycle state, suspension reason / actor / time; gains the suspension note and the state held before suspension.
- **Suspension reason** (existing list, see FR-005): code, staff label, customer-facing wording.
- **Identity document** (existing, unchanged).
- **Audit entry** (existing, unchanged): read through the Audit log's labels and categories.
- **Trusted device** (existing): per customer, a device fingerprint with first and last seen.
- **Session** (existing): one per sign-in, rotated on refresh; start, last activity, expiry. Deleted when it ends.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A staff member can find a customer and see their state, identity documents and recent activity in under 30 seconds.
- **SC-002**: A founder can suspend or reinstate an account in under 1 minute, and the effect applies to the customer's very next trade attempt.
- **SC-003**: 100% of file openings, suspensions and reinstatements produce exactly one audit entry each (verified by tests).
- **SC-004**: 0 cases in tests where a staff member without the suspend permission can suspend or reinstate, or a "view own actions" holder sees someone else's History entries.
- **SC-005**: The file opens in under 2 seconds for a customer with 10,000 audit entries and 1,000 sessions.

## Assumptions

- The design shows the customer's reference as the "Seller 4417" style short reference; the file uses the existing customer display reference.
- "Where" in the design is the customer's governorate.
- The note is kept on the customer record while suspended (so the file can show it) and in the audit entry permanently.
- The customer-facing wording of each reason is short and neutral; the staff note is never shown to the customer.
- Suspension does not end the customer's open sessions (they may still sign in, Part 1 §2.2).
- Only staff can suspend in this feature; the platform's automatic suspensions arrive with the order features and will record the system as actor.
- Devices are identified by a short form of the recorded fingerprint; the platform records no device model or location (as in spec 006).
- The Customer App reads the suspended state from the profile it already loads; it needs no new endpoint.
- Times are shown in Cairo time.
