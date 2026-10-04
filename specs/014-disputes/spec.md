# Feature Specification: Disputes and freeze, proxy collection, and the seller's request for more time

> Staff approval of inspection messages was in the request and is deferred (Clarification Q3).

**Feature Branch**: `feature/disputes` (backend worktree `spec-014-disputes-proxy-extend-4384da`, from `main` at `113815e`; the dashboard gets `feature/disputes` from its `main` when implementation starts; the Customer App has no repository — edits on disk only).

**Created**: 2026-10-03

**Status**: Draft (clarified)

**Input**: User description: "Spec 014 — Disputes and freeze, with proxy collection, the seller's extension request and staff approval of customer messages — all three projects. (1) Disputes: the `dispute` table, a customer opens one from the order screen (reason, description, photos), opening freezes the order into `disputed` and stops every deadline/sweep; staff Disputes page: queue, detail with order/ledger/timeline, reply, pass on to a named colleague, resolve — resume or against the sale (buyer refunded from escrow), optional compensation, the `repeated_disputes` suspension reason; permissions from Part 1 §4, dynamic. (2) Proxy collection: the buyer names someone else to collect (name, phone, ID photo, the proxy authorisation accepted); the branch handover checks the code and the proxy's ID. (3) Seller extension request: reason and new time; staff approve/decline in Orders with the existing permission and extend action; customer told on phone + email. (4) Staff approval of customer messages: find what the docs and the Dashboard design define and ask before inventing anything. Dashboard Disputes page live, dispute + extension request + proxy details in the order detail. Flutter: Report a problem with photos, dispute status and replies on the order screen, Someone else collects, Ask for more time, EN/AR, live API, fake backend + flow tests."

## Context

- **Sources**:
  - Schema: `05_schema_security.sql` §16 (`dispute`: `dispute_ref`, `order_id`, `raised_by` customer, `reason`, `detail`, `state` `open | passed_on | resolved`, `assigned_to` staff, `resolution_reply`, `resolved_by`, `resolved_at`; CHECK `resolved_needs_reply` — "a dispute cannot be closed silently") and §18 (`order_transition`: `at_inspection | awaiting_balance | ready_to_collect → disputed`; `disputed → awaiting_balance | ready_to_collect` "resolved, resume"; `disputed → cancelled_inspection` "resolved against sale" — already in the database since spec 011); `01_schema_core.sql` (order state `disputed` "frozen while a dispute is open", ledger kind `compensation` "goodwill / dispute compensation to a wallet", settings `compensation.cap_per_payment_egp` = 2000 and `compensation.cap_per_day_egp` = 5000); `02_schema_identity.sql` (suspension reason `repeated_disputes`; `agreement_acceptance` context `collection_proxy`); `04_schema_market.sql` §10–11 (`order_deadline_extension` — staff-granted, reason 10–1000 chars, moves forward only; `collection.is_proxy`, `proxy_name`, `proxy_phone`, `proxy_id_storage_ref`, CHECK `proxy_needs_details`; "Dahab does not verify the relationship").
  - Technical Spec Part 1 §4.1 ("Freeze an order during a dispute" and "Extend a deadline on request" — CEO, COO, Operations; "Check the ID of someone collecting for another" — IGI), §4.2 ("Pay compensation to a wallet" — CEO, Finance up to the caps; "Refund a buyer in full" — CEO, Finance; the COO never on a wallet-touching code), §6 (compensation requires a reason).
  - Technical Spec Part 2 §5 ("extend a deadline on request"), §7 handover (proxy: name, phone, ID upload, the prior `collection_proxy` acceptance; `proxy_details_missing` 422), §9 (`compensation`, `compensation_cap_exceeded` 403; "Refund a buyer in full (dispute/quality)"), §10 "Disputes & legal" (resolve needs a reply, or pass to a named colleague; audited; reason required).
  - Technical Spec Part 3 §1.4 (an extension is an explicit new instant; the resolver is not re-run), §10.3 (any post-payment refund for a dispute draws from `escrow`; "a deliberate reversal the money service constructs … always balanced, always with a named actor and reason"; disputes freeze the order so no deadline runs and no money moves until resolved).
  - Customer prototype: order card *Ask for more time* and *Report a problem*; `#s-extend` (reasons: travelling, health or family emergency, the branch was closed, *I already sold it elsewhere — this cancels the sale*, another reason; *Tell us briefly*; "The buyer's price stays locked while we review this"); `#s-dispute` (six reasons, the fifth buyer-only; *Tell us what happened*; *Add a photo* optional; "The order is frozen while we look at it. No money moves and no deadline runs against you."); the inspection result's *I disagree with this result*; the collection screen's *Someone else will collect it*; `#s-proxy` (full name as on their ID, phone, front of their ID, the warning that Dahab does not verify the relationship, the authorisation tick, *Send them the collection code*).
  - Dashboard design: navigation *Disputes and reports*; `p-disputes` ("While a dispute is open the order is frozen"; list *Reference · What · Raised by · Age*, disputes `DSP-…` and listing reports `RPT-…`; detail with raised by, reason, order value, deposit held, what they wrote; *What you are doing about it*: re-weigh, pay compensation, cancel and refund, pass it on; *Reply to the person who raised it* with canned replies and *Write your own*; "Every dispute ends with a reply. There is no way to close one silently."; *Pass it to someone else* — "The dispute stays open and becomes theirs. Nothing is sent to the customer."); Orders → *More time requested* (deadline now, time left, reason chosen, extensions before, what they wrote; *Extend by* 6 / 12 / 24 / 48 working hours; *Note to both sides*; *Accept and extend* / *Refuse*) and *Extension requests* (this month: order, reason, outcome — Waiting, Extended 24h, Cancelled instead); Inspections → the result with *Message to the seller* / *Message to the buyer* and "IGI records the measurement. What the customer reads is written here, by you. Nothing is sent until you approve it." (*Approve and send both* / *Hold*); the Customer file's suspension reasons ("Repeated disputes against them") and history ("Raised a dispute, DSP-4417").
  - Follow-ups listed by spec 012's plan: disputes and freezing, proxy collection, seller-initiated extension requests, "Staff approval of inspection messages", the manual post-window refund from escrow.
- **What exists** (specs 001–013): orders through their whole life (spec 012) with deadlines, the per-minute `orders:sweep`, the staff extend action (`order.extend_deadline`) writing `order_deadline_extension`, the handover against a 6-digit code with a five-try lock, the collection row and code, history in `order_state_change`, the guarded `order_transition` with the dispute rows, the deferred "each ending carries its money" check; the money service, escrow pass-through settlement at pay-balance, deposits; customer uploads (encrypted, by purpose); legal documents and agreement acceptances; suspension with the seven reasons; idempotency, audit, system actor, forced row-level security; SMS + email after commit; the Dashboard Orders, Inspections and Customer file; the Customer App order screen on the live API.
- **What does not exist**: no `dispute` table and no way to reach `disputed`; no `proxy_id` upload purpose, no proxy authorisation document, proxy fields unused at handover; no record of a seller's request for more time (only staff-granted extensions); no compensation or post-payment refund; the Dashboard *Disputes and reports* page is a placeholder; the Customer App's Report a problem, Someone else collects and Ask for more time screens are mock.

## Clarifications

### Session 2026-10-03 (specify)

- Q: Who may open a dispute, and from which order states? → A: Buyer or seller, from `at_inspection`, `weight_adjust_pending`, `awaiting_balance`, `ready_to_collect` (adds `weight_adjust_pending ↔ disputed`); not from `awaiting_delivery`.
- Q: How is a paid order unwound when a dispute goes against the sale? → A: It is not, in this spec — "against the sale" only before payment (deposit refunded in full); a paid order can only be resumed, optionally with compensation. The paid unwind is deferred.
- Q: Is the staff approval of inspection messages in this spec? → A: No, deferred; results keep notifying automatically (spec 012).

### Session 2026-10-03 (clarify)

- Q: When staff resume a disputed order, do its deadlines get back the time spent frozen? → A: Yes — every deadline that was running is pushed forward by exactly the frozen time, automatically; staff do not set them by hand.
- Q: When a seller asks for more time, who picks the new deadline? → A: Staff. The seller gives only a reason and a line; staff choose 6, 12, 24 or 48 working hours (from the current deadline, via the working-hours resolver) or refuse.
- Q: Does Dahab send the proxy the collection code by SMS? → A: No. The buyer shares the code themselves; the proxy gets one SMS without the code naming the branch and asking them to bring their ID. The app button reads *Save and tell them* rather than *Send them the collection code*.
- Q: After a dispute is resolved, can another be opened on the same order? → A: One dispute per party per order (the buyer once, the seller once), never two unresolved at the same time.
- Q: Is the seller suspended automatically when a dispute is resolved against the sale? → A: No. The resolve form offers an optional *Suspend the seller* with a reason from the fixed list; it needs `customer.suspend` and runs in the same step through the existing suspend action.

### Session 2026-10-03 (analyze)

- Q: Only the CEO held both the dispute code and a money code — who resolves with money? → A: Option A — Finance is also seeded `dispute.handle`; Operations and the COO freeze, follow up and resume; Finance refunds and compensates.
- Analysis C2 (Constitution II): dispute, compensation and extension-request rows are readable at the database only by the customer they belong to; the other party reads the order's state and history.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A customer reports a problem and the order freezes (Priority: P1)

A party to an order (the buyer, or the seller — the design shows disputes raised by both) opens *Report a problem* on the order, picks what went wrong from the fixed list, writes what happened, optionally adds photos, and sends it. The order freezes at once: no deadline runs, no sweep touches it, no money moves, and neither party can pay, decide or cancel on it. Both parties see the order as frozen; the one who raised it sees their dispute reference, its state and, once given, Dahab's reply.

**Why this priority**: Freezing is the protection the docs promise ("no money moves and no deadline runs against you"); without it nothing else in this spec has a case to work on.

**Independent Test**: Open a dispute on an order awaiting balance; confirm the order is `disputed`, the balance deadline no longer cancels it, pay-balance is refused, and the dispute shows on the order for its raiser.

**Acceptance Scenarios**:

1. **Given** an order the customer is party to in a state a dispute may be opened from, **When** they submit a reason, a description and up to the allowed number of photos, **Then** a dispute with a unique `DSP-…` reference is opened, the order moves to `disputed` with a history row naming the customer, and both parties are told (SMS + email) that the order is on hold.
2. **Given** a frozen order whose balance or collection deadline has passed, **When** the sweep runs, **Then** it does not cancel, forfeit, remind or expire anything on that order.
3. **Given** a frozen order, **When** either party tries to pay the balance, accept or decline an adjusted price, cancel, or open a second dispute, **Then** the request is refused with a clear conflict code and nothing changes.
4. **Given** an order in a state disputes may not be opened from, or that the customer is not party to, **When** they try to open one, **Then** it is refused (`illegal_order_transition` 409, or not found) and nothing is written.
5. **Given** a suspended customer, **When** they open a dispute on their own open order, **Then** it is allowed (a dispute is a complaint, not a trade).

---

### User Story 2 - Staff work the dispute queue and resolve each case with a reply (Priority: P1)

A staff member with the dispute permission opens *Disputes and reports*, sees open and passed-on disputes oldest first with reference, what, raised by, order and age, opens one to see the customer's words and photos, the order with its ledger and timeline, and either passes it to a named colleague with a note (nothing sent to the customer) or resolves it with a reply to the customer. Resolving either resumes the sale (the order returns to where it was, its deadlines given back the time it was frozen) or ends it against the sale (the order is cancelled, the buyer refunded through the money service, the piece returned to the seller). A resolution may also pay compensation to either party's wallet.

**Why this priority**: A frozen order must be unfrozen by a person, with a reply and a named actor; this is the other half of Story 1.

**Independent Test**: Resolve an open dispute "resume" on an order frozen from awaiting balance and confirm the order is back in awaiting balance with its deadline extended by the frozen time, the reply is stored and shown to the raiser, and the audit log names the staff member.

**Acceptance Scenarios**:

1. **Given** an open dispute, **When** a staff member with the permission passes it on to an active colleague who also holds it, with a note, **Then** the dispute becomes `passed_on`, assigned to that colleague, the move is audited, and the customer is told nothing.
2. **Given** an open or passed-on dispute, **When** staff resolve it without a reply, **Then** it is refused (`reason_required` / validation 422); there is no silent close.
3. **Given** a dispute on an order frozen from `weight_adjust_pending`, `awaiting_balance` or `ready_to_collect`, **When** staff resolve it "resume", **Then** the order returns to exactly the state it was frozen from, each deadline that was running is pushed forward by the time spent frozen, the raiser receives the reply (in the app, SMS + email) and the other party is told the order is moving again.
4. **Given** a dispute on an order frozen from `at_inspection`, **When** staff resolve it "resume", **Then** the order returns to `at_inspection` (a transition this spec adds — FR-012).
5. **Given** a dispute on an order frozen before payment, **When** staff resolve it "against the sale", **Then** the order becomes `cancelled_inspection`, the buyer's held deposit is refunded in full through a balanced ledger entry with the staff member as actor, the piece goes back to the seller through the existing return handover, and the reply is sent.
5a. **Given** a dispute on an order frozen from `ready_to_collect` (paid), **When** staff try "against the sale", **Then** it is refused (`dispute_outcome_not_allowed`, 409); they may resume, optionally with compensation.
6. **Given** a resolution with compensation, **When** the staff member holds the compensation permission and the amount is within their caps, **Then** a balanced `compensation` entry credits the chosen party's available wallet; over a cap → `compensation_cap_exceeded` (403) and nothing at all is resolved.
7. **Given** a staff member without the refund or compensation permission (e.g. the COO), **When** they try a resolution that moves money, **Then** it is refused (403) — they can only resume or pass it on.

---

### User Story 3 - The buyer names someone else to collect (Priority: P2)

A buyer whose piece is ready to collect opens *Someone else will collect it*, enters the person's full name as on their ID, their phone and a photo of the front of their ID, ticks the authorisation, and sends it. At the counter, IGI enters the collection code and, because the order has a proxy, checks the person's ID against the stored name and photo before handing over.

**Why this priority**: A real convenience the prototype and handover contract both describe, but the sale works without it.

**Independent Test**: Name a proxy on a ready-to-collect order, then confirm the handover refuses without the proxy check (`proxy_details_missing`) and succeeds with the code and the confirmed ID, recording the collector as the proxy.

**Acceptance Scenarios**:

1. **Given** a buyer's order ready to collect, **When** they submit name, phone, an uploaded ID photo (purpose `proxy_id`) and tick the authorisation, **Then** the proxy is stored on the collection, the acceptance of the current proxy-authorisation document is recorded with context `collection_proxy`, and the buyer sees the proxy on the order.
2. **Given** no tick, or a missing name or ID photo, **When** they submit, **Then** it is refused (422) and nothing is stored.
3. **Given** an order with a proxy, **When** IGI hands over with the correct code but without confirming the proxy's ID, **Then** it is refused `proxy_details_missing` (422); with the confirmation, the handover completes and is recorded as a proxy collection.
4. **Given** the staff order detail, **When** a staff member with handover rights opens it, **Then** they see the proxy's name, phone and can view the ID photo (each view audited).
5. **Given** a buyer who changes their mind, **When** they remove or replace the proxy before collection, **Then** the latest choice applies at the counter.

---

### User Story 4 - The seller asks for more time and staff decide (Priority: P2)

A seller whose order is awaiting delivery and whose reach-the-branch deadline has not passed opens *Ask for more time*, picks a reason and writes a line. Staff see the request in Orders (*More time requested*) with the deadline, time left, the reason, what they wrote and how many extensions came before, and either accept with a chosen extension and a note to both sides, or refuse with a note. The seller (and on accept, the buyer) is told on phone and email. "I already sold it elsewhere" is not a request: it leads to the existing seller cancellation.

**Why this priority**: The design and prototype both have it; without it the seller can only cancel or miss the deadline.

**Independent Test**: Send a request on an awaiting-delivery order, accept it with 12 working hours, and confirm a staff-granted extension row exists, the reach-branch deadline moved, the request shows *Extended*, and both parties were notified.

**Acceptance Scenarios**:

1. **Given** the seller's order awaiting delivery with time left and no request waiting, **When** they send a reason and a line, **Then** a pending request is stored and shown on their order as *waiting for an answer*; the deadline keeps running.
2. **Given** a request already waiting, **When** they send another, **Then** it is refused (409).
3. **Given** a waiting request, **When** staff with *Extend a deadline on request* accept it with a new deadline, **Then** the spec 012 extend action writes the extension (reason = the note), the request is marked accepted and linked to it, and both parties are told the new deadline.
4. **Given** a waiting request, **When** staff refuse it with a note, **Then** the request is marked refused, the deadline is unchanged, and the seller is told.
5. **Given** a waiting request whose deadline passes, **When** the sweep cancels the order, **Then** the request is closed as lapsed.

---

### Edge Cases

- A dispute opened at the same moment the sweep cancels the order for a missed deadline: exactly one wins; if the sweep wins, the dispute is refused with `illegal_order_transition`.
- A dispute opened at the same moment the buyer pays the balance: exactly one wins; the order never ends up both settled and frozen-from-awaiting-balance.
- Resolve and handover at the same moment on a ready-to-collect order: the handover is refused while frozen; if the handover committed first, the dispute could not have been opened (completed is final).
- A resolution against the sale retried with the same Idempotency-Key replays the stored response and refunds once.
- A dispute cannot coexist with a waiting request for more time: requests exist only while awaiting delivery, and disputes cannot be opened there.
- The buyer's dispute was resolved "resume"; the seller then raises their own on the same order: allowed (their one), the order freezes again and the second freeze also gives the time back on resume. A party's second attempt is refused even after their first was resolved.
- Compensation from a staff member whose day total plus this payment exceeds the daily cap is refused even if this payment alone is under the per-payment cap; days are Cairo days.
- A staff member resolves a dispute passed on to someone else: allowed for anyone with the permission (passing on assigns, it does not lock) — the audit records who acted.
- The proxy's ID photo and the dispute photos are private: never in lists, never to the other party, viewed by staff only with an audit row.
- A customer suspended for repeated disputes keeps every open order and dispute; suspension blocks new trades only.
- A dispute on an order whose buyer has a first-sale advance — not reachable (advances are not built).

## Requirements *(mandatory)*

### Functional Requirements

**Disputes — opening and freezing**

- **FR-001**: A customer party to an order MUST be able to open a dispute on it with a reason from the fixed list (`not_as_listed`, `disagree_inspection`, `money_wrong`, `other_side_unresponsive`, `not_theirs_to_sell` — buyer only, `other`), a description (required, 10–2000 characters) and 0–5 photos uploaded with purpose `dispute_photo`.
- **FR-002**: Opening MUST, in one transaction: create the dispute (`open`, reference `DSP-<number>`, raised by the customer), move the order to `disputed` through the guarded transition with a history row naming the customer, record the state it was frozen from and the moment; running deadlines stop counting from that moment (they keep their values and are pushed forward by the frozen time on resume, FR-011).
- **FR-003**: Either party (buyer or seller) may open a dispute only while the order is `at_inspection`, `weight_adjust_pending`, `awaiting_balance` or `ready_to_collect` (Clarification Q1: B); anything else — including `awaiting_delivery` — is refused `illegal_order_transition` (409), and the Customer App does not offer *Report a problem* there. Each party may raise at most one dispute per order, ever (the buyer once, the seller once), and an order never has two unresolved disputes at once; a second attempt is refused `dispute_already_raised` (409) or, while one is open, `order_frozen` (409).
- **FR-004**: While an order is `disputed` the sweep MUST skip it entirely (deadlines, reminders, the collection window) and every customer and staff action on the order other than viewing and dispute handling (naming or removing a proxy included) MUST be refused with a conflict code (`order_frozen`, 409).
- **FR-005**: Opening a dispute MUST be allowed to a suspended customer and is not gated by the trade gate (it is a complaint, not a trade); it requires a verified customer.
- **FR-006**: Both parties MUST be told (SMS, plus email when they have one, after commit) that the order is on hold; the other party is not shown the dispute's content.
- **FR-007**: The raiser MUST see on their order the dispute reference, its state (open / being looked at / resolved), and, once resolved, Dahab's reply; the other party sees only that the order is frozen and, once resolved, the outcome (resumed or cancelled) — the other party's view comes from the order itself — its state (`disputed` = on hold) and its history (the move out of `disputed`: resumed or cancelled) — never from the dispute rows.

**Disputes — staff handling**

- **FR-008**: Staff with the dispute-handling permission MUST see a queue of disputes (filter by state; oldest first; reference, reason, raised by — buyer or seller with their display reference, order reference, assignee, age) and a detail with the customer's words and photos (photo views audited), the order, its ledger entries and its timeline.
- **FR-009**: Staff MUST be able to pass a dispute to a named, active colleague who holds the same permission, with a note (10–1000 characters); state `passed_on`, `assigned_to` set, audited; nothing is sent to the customer.
- **FR-010**: Staff MUST be able to resolve a dispute only with a reply to the raiser (10–2000 characters, stored as the resolution reply, sent in-app + SMS + email) and an outcome: `resume` or `against_sale`; `resolved_by` and `resolved_at` set; audited with the reason.
- **FR-011**: `resume` MUST return the order to the state it was frozen from and push every deadline that was running forward by exactly the time spent frozen (from the freeze moment to the resolve moment, database clock), automatically — staff do not choose the new deadlines. Each push is an `order_deadline_extension` row granted by the resolver with the dispute reference as reason, so the existing guards and history apply. Reminders that fell inside the frozen window are re-armed against the new deadline.
- **FR-012**: The schema's transitions lack three this spec needs; they are added as reviewed `order_transition` rows and recorded in the schema docs: `weight_adjust_pending → disputed` (dispute opened), `disputed → weight_adjust_pending` (resolved, resume) and `disputed → at_inspection` (resolved, resume). Existing rows cover the rest.
- **FR-013**: `against_sale` is allowed only on an order frozen **before payment** — from `at_inspection`, `weight_adjust_pending` or `awaiting_balance` (Clarification Q2: C). It MUST move the order to `cancelled_inspection`, refund the buyer's held deposit in full (`deposit_release` through `PostLedgerEntryAction`, the resolving staff member as actor — the same money an inspection cancel moves today), and return the piece to the seller through the existing return path (no compensation to the seller unless chosen in FR-015). On an order frozen from `ready_to_collect` (paid and settled) `against_sale` MUST be refused (`dispute_outcome_not_allowed`, 409); staff may only resume, optionally with compensation. Unwinding a paid order is deferred to a later spec.
- **FR-014**: `against_sale` MUST require the *Refund a buyer in full* permission (CEO, Finance) in addition to the dispute permission; `resume` and pass-on require only the dispute permission. The dispute permission is seeded to the CEO, COO, Operations **and Finance** (analysis C1: Operations and the COO freeze, follow up and resume; Finance refunds and compensates), editable from the Dashboard.
- **FR-015**: A resolution MAY include compensation to the buyer or the seller (amount > 0, a reason from the design's list: IGI delay, Dahab's mistake, wasted trip to IGI, settlement of a dispute, goodwill), posted as a balanced `compensation` entry (`external_equity −X`, the customer's available `+X`) with the staff member as actor; it requires *Pay compensation to a wallet*; a holder whose permission is capped (Finance) is held to `compensation.cap_per_payment_egp` and `compensation.cap_per_day_egp` (per staff member, per Cairo day) → `compensation_cap_exceeded` (403); the whole resolution is one transaction.
- **FR-016**: No dispute outcome suspends anyone automatically. An `against_sale` resolution MAY include *Suspend the seller* with a reason from the fixed list (e.g. `piece_misrepresented`, `off_platform_dealing`, `repeated_disputes`) and an optional staff note; it requires `customer.suspend` in addition (403 otherwise, nothing resolved), runs through the existing suspend action in the same transaction (the seller's live listings held as today), and is audited. A `repeated_disputes` suspension of either party also stays possible from the Customer file; this spec adds no automatic threshold.
- **FR-017**: The Customer file history MUST show disputes the customer raised (reference and date).

**Proxy collection**

- **FR-018**: The buyer of an order in `ready_to_collect` (not yet collected — the collection and its code exist only after payment; the prototype offers it on the collection screen) MUST be able to name one proxy: full name (2–120), phone (international E.164 format, the registration rule; Egyptian numbers as +20…), an ID photo (upload purpose `proxy_id`), and an acceptance of the current proxy-authorisation legal document recorded with context `collection_proxy`; replacing the proxy records a new acceptance; the buyer can remove it before collection. When a proxy is named (or replaced), the proxy receives **one SMS without the collection code** — that they were named to collect a piece for the buyer at the named IGI branch and must bring their ID; the code is never sent to the proxy, the buyer shares it. The buyer's own SMS/email confirms the proxy was named.
- **FR-019**: The handover MUST, when the person collecting is the named proxy (the buyer may still collect in person), require the staff member to confirm they checked the person's ID against the stored name and photo; without it `proxy_details_missing` (422). The collection records `is_proxy` and the proxy's details (`proxy_needs_details` holds).
- **FR-020**: The proxy's ID photo is private: shown only to staff who can hand over or view orders, each view audited; never to the seller.
- **FR-021**: Naming a proxy MUST be refused while the order is frozen, after collection, and for a suspended buyer [assumption — a proxy is part of completing a trade].

**Seller's request for more time**

- **FR-022**: The seller of an order `awaiting_delivery` whose reach-branch deadline has not passed MUST be able to request more time with a reason (`travelling`, `emergency`, `branch_closed`, `other`) and a line (10–1000 characters) — the seller does **not** propose a time; one waiting request per order; the deadline keeps running.
- **FR-023**: The prototype's *I already sold it elsewhere* MUST route to the existing seller cancel (with its warning), not create a request.
- **FR-024**: Staff with *Extend a deadline on request* (`order.extend_deadline`) MUST see waiting requests in Orders with the deadline, time left, reason, text and the order's earlier extension count, and accept (choosing exactly one of 6 / 12 / 24 / 48 working hours — no other value — computed by the working-hours resolver from the current deadline at decision time, plus a note to both sides 10–1000) or refuse (with a note); accepting goes through the spec 012 extend action so its guards and audit apply, and links the extension to the request.
- **FR-025**: The seller MUST be told the outcome by SMS + email (and in the app); on accept the buyer is told the new deadline too.
- **FR-026**: A waiting request MUST close as `lapsed` when the order leaves `awaiting_delivery` without an answer.
- **FR-027**: Staff MUST see this month's requests with their outcome (*Extension requests*).

**Cross-cutting**

- **FR-028**: Every customer and staff POST in this spec MUST take an `Idempotency-Key`; every staff action and every money movement MUST be audited with a named actor; money moves only through `PostLedgerEntryAction`, every entry balanced; the "each ending carries its money" check is extended to the new endings.
- **FR-029**: New tables MUST be under forced row-level security, enforced by the database for the customer who owns the data (analysis C2): a dispute, its photos and its history only to the customer who raised it; a compensation only to the customer who received it; a request for more time only to the seller who sent it; proxy details on the collection as in spec 012, with the seller's payload never carrying them; staff access through the elevated scopes used by spec 012.
- **FR-030**: Staff approval of customer messages is **out of scope** (Clarification Q3: B). The docs and the Dashboard design define exactly one such flow — the *Inspections* result page, where staff write/approve *Message to the seller* and *Message to the buyer* ("nothing is sent until you approve it", a spec 012 follow-up); it is deferred, and inspection results keep notifying the parties automatically as in spec 012. The design defines no approval of customer-written text: dispute descriptions and extension reasons are read by staff only, listing descriptions already pass listing review.
- **FR-031**: Listing reports (`RPT-…`, the prototype's *Report this listing*) shown on the same Dashboard page are out of scope; the page is named *Disputes and reports* but shows disputes only.
- **FR-032**: Customer App: *Report a problem* with reasons, text and photos; the dispute status and reply on the order screen; *Someone else collects* with the ID photo and tick; *Ask for more time* with the reasons and the *sold elsewhere* path; EN/AR; on the live API; the fake backend and flow tests cover each.
- **FR-033**: Dashboard: *Disputes and reports* page live (queue + detail + pass on + resolve with compensation), the dispute, waiting extension request and proxy shown in the order detail, *More time requested* and *Extension requests* in Orders, every modal a DModal with an idempotency key, everything gated on the backend permission strings.

### Key Entities

- **Dispute**: a complaint by a party on one order — reference, order, raiser, reason, description, photos, state (open / passed on / resolved), assignee, the state the order was frozen from and when, outcome, resolution reply, resolver, resolution time, linked refund and compensation entries.
- **Dispute photo**: an encrypted upload attached to a dispute (purpose `dispute_photo`).
- **Collection proxy**: the person named by the buyer — name, phone, ID photo, the agreement acceptance; stored on the order's collection record as soon as it is named, and marked as the collector at handover when the proxy collects.
- **Extension request**: a seller's ask on one order — reason, text, state (waiting / accepted / refused / lapsed), the staff member who answered, the note, the linked granted extension.
- **Compensation payment**: a balanced ledger entry of kind `compensation` to a customer's available wallet, tied to the dispute and order, with the staff member, reason code and note.
- **Permissions** (dynamic, seeded once): handle disputes (`dispute.handle` — CEO, COO, Operations, Finance; from "Freeze an order during a dispute", plus Finance so the people who move the money can act on a dispute — analysis C1), refund a buyer in full (`order.refund` — CEO, Finance), pay compensation (`compensation.pay` — CEO; Finance up to caps), check a proxy's ID (`order.handover` already covers the counter; IGI).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A customer can report a problem, with photos, in under 2 minutes from the order screen, and the order is frozen the moment they send it.
- **SC-002**: 100% of disputed orders are untouched by the sweep for as long as they are frozen (verified by running the sweep past every deadline of a frozen order).
- **SC-003**: 0 disputes can be resolved without a reply, and 100% of resolutions name the staff member in the audit log.
- **SC-004**: After any mix of resolutions, refunds and compensations, the ledger stays balanced and every customer's balance equals the sum of their entries (reconciliation tests as in specs 012/013).
- **SC-005**: Under concurrency (dispute vs sweep, dispute vs pay-balance, resolve vs handover, resolve twice), each pair ends in exactly one consistent outcome with no double refund.
- **SC-006**: Staff answer a request for more time in under a minute from the Orders page, and both parties hear the new deadline within a minute of the decision.
- **SC-007**: A proxy collection can only complete when the code is right and the ID check is confirmed — 0 proxy handovers without both.

## Assumptions

- Dispute photos use a new upload purpose `dispute_photo` (the docs name only `proxy_id`); stored encrypted on the private disk like identity documents and top-up receipts.
- The dispute reason codes are the prototype's six choices; extension reasons are the prototype's four (plus the sold-elsewhere path).
- The raiser receives exactly one reply — the resolution reply — as the schema holds one; there is no back-and-forth thread in this spec.
- The design's *Ask IGI to re-weigh* is a canned reply/next step, not a re-inspection workflow; re-inspection is out of scope.
- Compensation is paid only as part of a dispute resolution in this spec; the stand-alone *Pay compensation* screen, *Refund a buyer* outside disputes, direct wallet adjustment and case files stay out of scope.
- The proxy authorisation is a new legal document (`collection_proxy_authorisation`, EN/AR text from the prototype's tick), seeded like `payout_account_declaration`; the legal clinic's final wording is pending (open-questions §1).
- Automatic suspension for repeated disputes is not built; there is no threshold setting in the schema.
- The Customer App runs locally against `http://127.0.0.1:8000/api/v1`, passed with `--dart-define`; its compiled default is unchanged unless the user asks; the Dashboard work happens in a `feature/disputes` worktree of the dashboard repository.
