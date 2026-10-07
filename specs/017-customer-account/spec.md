# Feature Specification: Customer account — contact changes, password, devices, notifications, saved pieces, help & legal, close account, report a listing

**Feature Branch**: `feature/customer-account` in all three repositories — backend worktree `spec-017-customer-account-662fe8` (from `main` at `3df0a58`), dashboard from `main` at `82535f7`, Customer App from `main` at `4de46fe`.

**Created**: 2026-10-06

**Status**: Draft (clarified)

**Input**: User description: "Spec 017 — Customer account (contact changes, password, devices, notifications, saved pieces, help & legal, close account, report a listing) — all three projects." (full brief in the session).

## Context

- **Sources**:
  - Technical Spec Part 1 §2 (factor table): *OTP (SMS) — new-device sign-in; phone-number change*; *Email confirmation link — second check on each withdrawal; email change* ("one-time signed token, single use, short TTL"). Part 1 §2.3: new device holds the session behind an SMS OTP.
  - Customer prototype: `#s-account` (Phone confirmed / Email confirmed), `changeContact()` — phone: *"We send a code to the new number and tell the old one. Withdrawals pause for 48 hours after the change."*; email: *"We send a link to the new address and tell the old one. Your email is one of the two checks on withdrawals."*; `#s-security` (Password: current + new; Devices signed in with *This device* / *Sign out*; *"If someone signs in from a new device, we tell you on your number and email."*); `#s-notif` (four settings: *A buyer requests my piece* — always on; *Price of my listed pieces moves*; *Deadline reminders*; *New pieces I might like* — off; *"Deadline and request alerts stay on because money depends on them."*); `#s-inbox` (event rows with a time and *Notification settings*); `#s-saved` (*"Saved pieces don't lock a price."*) and the piece page's Save button; `#s-help` (FAQ, *Contact us*, Terms of use, Privacy policy, Selling rules and deadlines, How we handle your ID and documents); `#s-support` (phone 16000 and hours, chat, WhatsApp, email, social, *"Dahab will never ask for your password or your code"*); `#s-delete` (blockers *"Finish or cancel these, and withdraw your balance, before you can close the account"*, six leaving reasons, *What happens to your data*); `#s-report` (six reasons, *Anything to add*, *"The seller is never told who reported the listing"*).
  - Dashboard design: *Disputes and reports* lists `RPT-2291 · Photos look taken from elsewhere · Listing L-8842-03 · Anonymous · 3h · Open` next to disputes; the suspension reasons include *Reported by other users* and *They asked us to close it* (built as `reported_by_users`, `customer_request` in spec 007). No staff actions are drawn for a report.
  - Terms draft §2.4: *"Photographs are deleted when you close your account; a short record that verification took place is kept for the period the law requires."* Terms "What is not covered here": the privacy policy is not drafted. Open questions §1: data retention needs the legal clinic. Blueprint: *"Nothing that … closes an account happens without a named person attached to it."*
  - Terms draft §9 and spec 013: changing the payout account cancels withdrawals not yet left and pauses new ones for `withdrawal.account_change_pause_hours` (48).
- **What exists** (specs 001–016): sign-in with new-device OTP, trusted devices and token families, `logout` / `logout-all`; the Dashboard Customer file already lists a customer's trusted devices and open sessions (spec 007); `withdrawal_pause` (opened by a payout-account change) and the email withdrawal confirmation (spec 013); SMS + email notifications for every event of specs 001–016; `legal_document` with only `terms` published; suspension with seven reasons; listing take-down (spec 010); audit log; `Idempotency-Key` layer; RLS per customer.
- **What does not exist**: no way to change phone, email or password; no customer view of their own devices; no in-app inbox or notification settings; no saved pieces; no FAQ, contact or privacy content; no closed-account state; no listing report.

## Clarifications

### Session 2026-10-06 (clarify — all recommended options accepted)

- Q1: Which pause does a phone change use? → A: The spec 013 `withdrawal_pause` and `withdrawal.account_change_pause_hours` (48), with a trigger kind on the pause row.
- Q2: Withdrawals not yet released at a phone change? → A: Cancelled (back to available), as at a payout-account change.
- Q3: Other devices at a phone change? → A: Every other session signed out and every other trusted device forgotten.
- Q4: Does an email change pause withdrawals and cancel confirmation links? → A: Both, when an email existed before; adding a first email does neither.
- Q5: Unreleased withdrawals at an email change? → A: Cancelled, as in Q2.
- Q6: Other sessions at a password change? → A: Signed out; trusted devices kept.
- Q7: Who may change phone, email, password? → A: Every signed-in customer, whatever the state (pending, rejected, suspended).
- Q8: New contact already held by someone else? → A: Plain refusal `contact_taken` (409) when the code/link is requested.
- Q9: Forget a trusted device? → A: Yes — signing a device out ends its sessions and forgets it.
- Q10: New-device sign-in alert? → A: SMS + email + inbox after the OTP succeeds.
- Q11: Inbox text? → A: The backend renders EN and AR title/body at send time from the same messages as SMS/email, and stores type + parameters + link too.
- Q12: Do settings stop SMS/email? → A: Every channel of that category (moot while no switchable setting exists, see Q14).
- Q13: Deadline reminders switchable? → A: No — requests, deadlines, security and money are always on.
- Q14: Price-moves and New-pieces events? → A: Deferred; the settings screen shows only the always-on rows.
- Q15: Inbox retention? → A: Kept, paged, no auto-delete.
- Q16: Customer file shows notifications sent? → A: Yes, read-only, `customer.view`.
- Q17: Saved pieces? → A: Any signed-in customer; live and reserved show the price; any other state shows "No longer available" without a price and can be removed; no notification.
- Q18: Help and contact content? → A: Contact details in a Backend config file (prototype values as demo values, "replace before production"); the FAQ waits for spec 019 App text and keeps its MOCK flag.
- Q19: Unpublished legal documents? → A: Listed with "not published yet"; nothing seeded.
- Q20: What blocks closing? → A: An order not terminal, an active buy request, a listing reserved or in a sale, any wallet money (available or held), a withdrawal not final, an open dispute, a pending extension request, a top-up notice not final.
- Q21: Listings and data at closing? → A: Live/draft/in-review/changes-requested listings withdrawn through the state machine; every record kept unchanged (no delete, no anonymisation, ID photos kept until the legal clinic decides); phone stays reserved. The prototype's "removed straight away" copy is adapted.
- Q22: What is "closed"? → A: A new closed state (closed time + reason), separate from suspension; sign-in refused with `account_closed`; no reopening in this spec.
- Q23: Leaving reasons? → A: The prototype's six as codes; optional note up to 500 characters with "Another reason".
- Q24: Who reports what? → A: Verified, not suspended; another seller's live or reserved piece; one open report per reporter per piece; 10 a day; note up to 1,000 characters; the six prototype reasons as codes.
- Q25: Permission? → A: New `listing_report.handle`, seeded to the roles holding `listing.takedown`; take-down also needs `listing.takedown`.
- Q26: Staff actions? → A: Dismiss with a note, or take down (spec 010 take-down, resolving every open report on the piece); suspension stays in the Customer file; a piece leaving the market otherwise resolves its open reports as "listing gone".
- Q27: Reporter told? → A: A generic inbox note, no detail.
- Q28: Limits? → A: Change requests 3/hour; confirmations and password changes 5 per 15 minutes; sign-outs 10/minute; codes 10 minutes and 5 tries (spec 001); email links 30 minutes (spec 013).

### Session 2026-10-06 (analysis approvals)

- Q: Cap on saved pieces? → A: Yes, as a Dashboard setting `saved.max_per_customer` (default 200), changeable later with history like every setting.
- Q: Legal document codes for the prototype's four documents? → A: `terms`, `privacy`, `selling_rules`, `id_handling`.
- Q: Does a piece still at a branch block closing? → A: Yes — blocker `piece_at_branch` (the seller's piece awaiting return or unclaimed; the buyer's paid piece not collected).

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Change phone number (Priority: P1)

A signed-in customer enters a new number, receives a code on it, and confirms. The number changes, the old number is told by SMS, the change is audited, and withdrawals pause for the configured window.

**Why this priority**: The phone is the sign-in identity and the SIM-swap path (blueprint risk table); a customer who loses their number has no other way to keep their account.

**Independent Test**: Request a change to a free number, confirm with the code — `customer.phone` is the new number, the old number received the notice, an audit row and a withdrawal pause exist; a second use of the same code fails.

**Acceptance Scenarios**:

1. **Given** a customer and a number no one holds, **When** they request the change and enter the code within its lifetime, **Then** the number changes, the old number is told, and new withdrawals are refused until the pause ends.
2. **Given** a number held by another customer, **When** a change to it is requested, **Then** it is refused with `contact_taken` (409) and no code is sent.
3. **Given** a wrong code entered past the try limit, **Then** the challenge is locked and a new one must be requested (rate-limited).
4. **Given** two change requests from the same customer at once, **Then** at most one challenge is live and at most one change commits.
5. **Given** a phone change and a withdrawal submitted at the same moment, **Then** either the withdrawal is created before the change (and is cancelled by it) or it is refused by the pause — never created after the change outside the pause.

---

### User Story 2 — Change email (Priority: P1)

The customer enters a new address and receives a single-use link there; opening it confirms the change. The old address (if any) is told. Because email is one of the two withdrawal checks, open withdrawal confirmation links stop working.

**Independent Test**: Request a change, open the link — the email changes, the old address got the notice, earlier withdrawal-confirmation links are refused, unreleased withdrawals are cancelled and a pause is open; opening the link again fails.

**Acceptance Scenarios**:

1. **Given** a pending change link, **When** it is opened within its lifetime, **Then** the email changes, the old address is told, and the link cannot be used again.
2. **Given** the link opened twice at once, **Then** exactly one change commits.
3. **Given** an address held by another customer, **Then** the change is refused.
4. **Given** a customer with no email yet, **When** they add one, **Then** the same link flow applies and no "old address" notice is sent.

---

### User Story 3 — Change password and manage devices (Priority: P1)

The customer changes their password with the current one and a new one meeting the spec 001 rules. They see the devices and sessions signed in to their account, which one is this device, and can sign any other one out. When a sign-in from a new device succeeds, they are told on their number, email and inbox.

**Independent Test**: Change the password — the old password no longer signs in; list sessions — the current one is marked; sign another session out — its tokens stop working at once.

**Acceptance Scenarios**:

1. **Given** a wrong current password, **Then** the change is refused and counted against the sign-in rate limit.
2. **Given** two open sessions, **When** the customer signs out the other one, **Then** that session's tokens are revoked and the list no longer shows it.
3. **Given** a customer tries to sign out a session that is not theirs, **Then** it is not found.
4. **Given** a successful new-device sign-in, **Then** an alert reaches the phone, the email (if any) and the inbox.

---

### User Story 4 — In-app notifications and settings (Priority: P1)

Every event that specs 001–016 already send by SMS or email, and every new event of this spec, also lands in an in-app inbox with a link to the thing it is about (order, invoice, wallet, listing…). The header bell shows the unread count; the customer can open the inbox, mark items read, and choose the optional notification settings. Codes and withdrawal-confirmation links never enter the inbox.

**Independent Test**: Trigger an order event — an inbox item exists with its type, parameters and link, unread; reading it changes the unread count; triggering an OTP adds nothing to the inbox.

**Acceptance Scenarios**:

1. **Given** any existing notification of specs 001–016 (except OTPs and the withdrawal confirmation), **When** it is sent, **Then** one inbox item is created for the customer, and the feature's own logic and its SMS/email are unchanged.
2. **Given** unread items, **When** the customer marks one or all read, **Then** the unread count drops accordingly.
3. **Given** a customer reading the inbox, **Then** they only ever see their own items.
4. **Given** a mandatory category (security, money, requests, deadlines), **Then** no setting can turn it off.

---

### User Story 5 — Saved pieces (Priority: P2)

From the piece page the customer saves or unsaves a piece. *Saved* lists their saved pieces with the current indicative price. A saved piece never locks a price.

**Acceptance Scenarios**:

1. **Given** a live piece, **When** the customer saves it twice, **Then** it is saved once.
2. **Given** a saved piece that is later sold, reserved or taken down, **Then** it shows "No longer available" without a price and can be removed (a reserved piece still shows its price).
3. **Given** a saved list, **Then** prices come from the spec 005 calculator, as on the market.

---

### User Story 6 — Help, contact, terms and privacy (Priority: P2)

The customer opens Help (FAQ), Contact us and the legal documents from the account and from sign-up's *Read the full terms*. Only published content is shown; nothing is invented.

**Acceptance Scenarios**:

1. **Given** the published terms, **When** *Terms of use* is opened, **Then** the current version is shown in the app language.
2. **Given** a legal document not yet published (privacy, selling rules, ID handling), **Then** the app says it is not published yet rather than showing placeholder text.
3. **Given** Contact us, **Then** the details come from the Backend; the FAQ stays mock until spec 019.

---

### User Story 7 — Close my account (Priority: P2)

The customer chooses a reason and closes their account. Closing is refused while anything is open (per FR-050), listing what blocks it. Once closed, every session ends, sign-in is refused, live pieces leave the market, and records the law may require are kept untouched.

**Acceptance Scenarios**:

1. **Given** an open order, active buy request, money in the wallet, a pending withdrawal, an open dispute or a piece still at a branch, **When** the customer tries to close, **Then** it is refused with the list of what blocks it.
2. **Given** nothing open, **When** they close with a reason, **Then** the account is closed, all tokens revoked, the closure audited, and SMS + email confirm it.
3. **Given** a closed account, **When** someone signs in with its phone, **Then** sign-in is refused with a dedicated code.
4. **Given** a close request racing a buy request or top-up credit, **Then** either the close is refused or the other action is refused — never both succeed.

---

### User Story 8 — Report a listing and the staff reports queue (Priority: P2)

A verified customer reports a piece with one of the prototype's six reasons and an optional note. The seller is never told who reported. Staff see open reports in *Disputes and reports* with the listing, the reason and the note, and resolve each one by dismissing it or taking the piece down through the spec 010 take-down.

**Acceptance Scenarios**:

1. **Given** a live piece of another seller, **When** the customer reports it, **Then** a report `RPT-n` is open and the reporter cannot open a second one on the same piece while it is open.
2. **Given** their own piece, **Then** the report is refused.
3. **Given** an open report, **When** staff with the report permission take the piece down, **Then** the spec 010 take-down runs (with its reason and seller notice) and every open report on that piece is resolved.
4. **Given** an open report, **When** staff dismiss it with a note, **Then** it is closed and audited; the seller sees nothing.
5. **Given** staff without the permission, **Then** the queue and actions are refused.

---

### User Story 9 — Dashboard: the customer's account in the Customer file (Priority: P3)

The Customer file shows the customer's contact-change history (from the audit log), their devices (already built in spec 007) and, read-only under `customer.view`, the inbox items they were sent.

### Edge Cases

- A phone/email change challenge outlives a closed or suspended account → refused at confirmation.
- The new number equals the current one → refused before any SMS is sent.
- A challenge is requested, then a second one: the first stops working.
- Password change while another device is mid-refresh → that device's refresh fails after the change if other sessions are signed out.
- A notification sent while the customer is mid-RLS scope of another actor (sweeps, staff actions) → the inbox write still lands on the right customer only.
- A saved piece whose listing is later rejected/withdrawn (final states).
- A report on a piece that leaves the market for another reason before staff look at it → resolved automatically as "listing gone".
- Closing an account with a draft or in-review listing (withdrawn), with a payout account in use or a withdrawal pause running (neither blocks).

## Requirements *(mandatory)*

### Functional Requirements

**Contact changes and password** (security events: audited, rate-limited, single-use, short-lived, old contact always told; allowed in every customer state except closed)

- **FR-001**: A customer MUST be able to request a phone change to a number in the registration format; a 6-digit code goes to the new number only (10 minutes, 5 tries, spec 001 hashing); a new request cancels the previous one; a number held by another customer is refused with `contact_taken` (409) before any code is sent; the same number as now is refused.
- **FR-002**: On confirmation, in one transaction: the number changes (unique), every withdrawal not yet released is cancelled (money back to available, as spec 013), a `withdrawal_pause` opens for `withdrawal.account_change_pause_hours` with trigger kind `phone_change`, every other session is signed out and every other trusted device forgotten. After commit the old number (and the email, if any) is told.
- **FR-010**: A customer MUST be able to request an email change; a single-use link (30 minutes) goes to the new address; opening it confirms; an address held by another customer is refused with `contact_taken`.
- **FR-011**: When an email existed before, confirming MUST in one transaction: cancel every open withdrawal confirmation link, cancel withdrawals not yet released, open a `withdrawal_pause` (trigger kind `email_change`); after commit the old address is told. Adding a first email does none of these.
- **FR-012**: A customer MUST be able to change their password with the current one; the new one follows spec 001 rules and differs from the current one; every other session is signed out (trusted devices kept); SMS + email + inbox tell them.
- **FR-013**: Every contact or password change and every self sign-out MUST be audited with before/after (contacts masked as elsewhere) and appear in the Customer file history.
- **FR-014**: Limits: change requests 3 per hour; confirmations and password changes 5 per 15 minutes; sign-outs 10 per minute. A wrong current password counts against the sign-in limiter.

**Devices**

- **FR-020**: A customer MUST be able to list their own trusted devices and open sessions (same data as the spec 007 Customer file view), with the current one marked, and sign out any device other than the current one — ending its sessions and forgetting it, so the next sign-in there needs an OTP.
- **FR-021**: A successful new-device sign-in (after the OTP) MUST alert the customer by SMS, email (if any) and inbox.

**Notifications**

- **FR-030**: Every customer notification of specs 001–016 except OTP codes and the withdrawal confirmation link MUST also write one inbox item, through a third channel next to SMS/mail, in the same after-commit step; no feature's logic or SMS/email changes.
- **FR-031**: An inbox item stores a type code, parameters, a deep link (kind + id), the EN and AR title and body rendered at send time from the same messages as SMS/email, created time and read time. Types are open for later specs. Items are kept (no auto-delete).
- **FR-032**: The customer MUST be able to list the inbox (newest first, keyset paged), get the unread count, and mark one or all read.
- **FR-033**: Notification settings show the prototype's always-on rows only (requests, deadlines; security and money are mandatory too); *Price of my listed pieces moves* and *New pieces I might like* are deferred (no event exists), so nothing is switchable in this spec.
- **FR-034**: No push, no WhatsApp.
- **FR-035**: Staff with `customer.view` MUST see a customer's inbox items, read-only, in the Customer file.

**Saved pieces**

- **FR-040**: Any signed-in customer MUST be able to save (idempotent) and unsave a piece that is on the market (live or reserved), up to the setting `saved.max_per_customer` (default 200; `saved_limit_reached`), and list saved pieces newest first: live and reserved with the spec 005 indicative price; any other state as "No longer available" without price or private media, removable. No notification is sent about saved pieces. A saved piece never locks a price.

**Help & legal**

- **FR-045**: The app MUST list the legal documents (terms, privacy, selling rules, ID handling) and show the published version from `legal_document`, or "not published yet". Contact details (phone, hours, WhatsApp, email, social handles) come from a Backend config file served publicly, marked as demo values to replace before production. The FAQ is not built (spec 019) and stays mock.

**Close account**

- **FR-050**: A customer MUST be able to close their account with one of six reasons (`finished`, `fees_too_high`, `too_slow_to_sell`, `data_trust`, `something_went_wrong`, `other` — note ≤ 500 characters with `other`). Closing is refused with `account_has_open_items` (409) listing each blocker: an order not terminal, an active buy request, a listing reserved or in a sale, wallet available or held > 0, a withdrawal not final, an open dispute, a pending extension request, a top-up notice not final, a piece still at a branch (`piece_at_branch`: their piece awaiting return or unclaimed as seller, or a paid piece not collected as buyer). A payout account (in use or not) and a running withdrawal pause do not block; payout accounts stay as records. The check and the close run under locks so a racing buy request, credit or withdrawal cannot slip in.
- **FR-051**: Closing MUST, in one transaction: set the closed state (closed time, reason, note), withdraw every live/draft/in-review/changes-requested listing through the listing state machine, cancel pending contact-change challenges, revoke every session and trusted device; after commit tell SMS + email. Sign-in, refresh and OTP for a closed account are refused with `account_closed`. No record is deleted or anonymised (retention waits for the legal clinic); the phone stays reserved; no reopening in this spec. The prototype's data copy is adapted to say records are kept as the law requires.

**Listing reports**

- **FR-052**: A verified, not suspended customer MUST be able to report another seller's live or reserved piece with one of six reasons (`photos_not_genuine`, `price_or_weight_wrong`, `description_mismatch`, `not_theirs_to_sell`, `off_platform_dealing`, `other`) and an optional note ≤ 1,000 characters; one open report per reporter per piece (`report_already_open` 409); 10 per day. The report gets `RPT-n`. The seller is never shown the reporter.
- **FR-053**: Staff with the new `listing_report.handle` (seeded to the roles holding `listing.takedown`) MUST see reports in *Disputes and reports* (list with counts and filters, detail with the listing and the reporter's reference), and either dismiss with a note or take the piece down — which also needs `listing.takedown`, runs the spec 010 take-down with its reason and seller notice, and actions every open report on that piece. A piece leaving the market any other way resolves its open reports as `listing_gone`. Every action is audited; each reporter gets a generic inbox note.

**Cross-cutting**

- **FR-060**: Every new customer and staff POST takes an `Idempotency-Key`; every new table holding customer data is under forced RLS; every new staff action is gated on a Backend permission string and audited.
- **FR-061**: Dashboard and Customer App screens go live with EN/AR, replacing the mocks they supersede; prototype screens duplicating a live one route to it.

### Key Entities

- **Contact change challenge**: customer, kind (phone/email), new value, code hash or link token hash, expiry, tries, used / cancelled time — kept in the encrypted cache (phone) and `one_time_token` (email), not a new table (research R2).
- **Withdrawal pause** (existing): gains a trigger kind (`payout_account` / `phone_change` / `email_change`).
- **Inbox item**: customer, type, parameters, link kind + id, title/body EN and AR, created, read.
- **Saved piece**: customer, listing, saved time (unique per pair).
- **Customer** (existing): gains closed time, closed reason, closed note.
- **Listing report**: `RPT-n`, listing, reporter, reason, note, state (`open` / `dismissed` / `actioned` / `listing_gone`), handled by, handled at, staff note.

## Success Criteria *(mandatory)*

- **SC-001**: A customer changes phone, email or password in under 2 minutes, and the old contact receives a notice every time (100% of changes).
- **SC-002**: No code or link works twice or after its lifetime; racing requests produce at most one change (verified by concurrency tests).
- **SC-003**: 100% of customer notifications of specs 001–016 (except codes and confirmation links) appear in the inbox; 0 codes do.
- **SC-004**: A signed-out session cannot make any further request.
- **SC-005**: No account with money or any FR-050 blocker can be closed, including under racing requests.
- **SC-006**: Every report reaches the staff queue; the seller can never learn the reporter.
- **SC-007**: Every screen listed in the brief is live in both apps with EN/AR, and the remaining mocks are listed.

## Assumptions

- Phone format, code length, lifetimes and try limits reuse spec 001's OTP settings; email link lifetime reuses spec 013's 30-minute single-use pattern.
- The Customer file's devices view (spec 007) already exists and is reused, not rebuilt.
- Inbox items are created after commit, alongside the existing SMS/email, so a failed send never rolls back business logic.
- The prototype's FAQ answers and support details are product copy, not confirmed facts (some disagree with built rules, e.g. commission figures come from settings); none are shipped without approval.
- Forgot-password (reset link on the sign-in page) is not in this spec's brief and stays out.
- Data retention periods are undecided (open questions §1); nothing is deleted or anonymised in this spec.
- Notification settings with a switch, the price-move and recommendation events, the FAQ content, publishing legal documents and reopening a closed account are out of scope.
