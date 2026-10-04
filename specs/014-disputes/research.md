# Research: Disputes and freeze, proxy collection, the seller's request for more time

Engineering decisions for spec 014. Product decisions are in [spec.md](./spec.md) (Clarifications). Each item: **Decision**, **Rationale**, **Alternatives considered**.

## R1 — Paths

**Decision**: Customer endpoints under `/customer/me/orders/{order}/…` (`disputes`, `extension-requests`, `proxy`, `proxy/remove`); staff endpoints under `/dashboard/disputes*`, `/dashboard/extension-requests*`, `/dashboard/orders/{order}/proxy-id`; the handover body grows. Part 2's `/admin/disputes/{id}/resolve|pass-on` and `/igi/orders/{id}/handover` map to `/dashboard/disputes/{id}/resolve|pass-on` and the existing `/dashboard/orders/{id}/handover` (the spec 012 precedent R1).

**Rationale**: Every built module uses `/customer/me/*` and `/dashboard/*`; the docs' `/admin` and `/igi` prefixes were never built.

**Alternatives**: `/admin/*` as written — would split the Dashboard's client for no gain.

## R2 — Freezing is a state, the sweep needs no change

**Decision**: Opening a dispute moves the order to `disputed` through `MovesOrder` (guard + history). Every sweep pass already selects by order state (`awaiting_delivery`, `awaiting_balance`, `weight_adjust_pending`, `ready_to_collect`) and re-checks the state under its lock, so a `disputed` order is never picked and an order frozen between selection and lock is skipped. The deadline columns keep their values while frozen (they are read again at resume, R4). Tests prove each pass ignores a frozen order past every deadline.

**Rationale**: No new branch in the sweep, and the lock-then-recheck already gives "exactly one wins" against a concurrent open.

**Alternatives**: Clearing the deadlines on freeze — loses the remaining time needed at resume; a `frozen` flag beside the state — two sources of truth.

## R3 — `order_frozen` before any other refusal

**Decision**: A new `MovesOrder::assertNotFrozen(Order)` is called right after the order lock in every customer and staff order Action (seller cancel, decision, pay-balance, relist, receive, inspection result, propose price, change branch, extend deadline, handover, staff cancel, proxy name/remove, extension accept). It throws `order_frozen` (409, `details.dispute_ref`). Only the dispute Actions move a `disputed` order.

**Rationale**: The spec promises a clear conflict code; without the guard each Action answers its own `illegal_order_transition`, which tells the client nothing about why.

**Alternatives**: Checking inside `moveOrder` — too late: several Actions refuse on state before they call it.

## R4 — Resume gives back the frozen time (Clarification)

**Decision**: `frozen_seconds = resolved_at − dispute.frozen_at` (database clock, `clock_timestamp()` in the same transaction). On resume to `F` the deadline that runs in `F` is pushed by exactly that: `weight_adjust_pending` → `decision_due_deadline` (`which = 'decision'`), `awaiting_balance` → `balance_due_deadline` (`balance`), `ready_to_collect` → `collect_deadline` (`collect`); `at_inspection` has none. Each push writes an `order_deadline_extension` row (`granted_by` = resolver, `reason` = "Dispute DSP-n: frozen time given back", `dispute_id`). The reminder flag of that deadline is cleared so the reminder re-arms. (As built: a collection window that had already passed when the order froze stays passed — old + frozen < now — so an `uncollected_expired` piece stays so.) Written directly by the resolve Action, not through `ExtendOrderDeadlineAction` (whose "must be future / must be in that state" checks do not fit a move happening in the same transaction).

**Schema change**: `order_deadline_extension.which` CHECK gains `'decision'`; new nullable `dispute_id` and `extension_request_id` FKs.

**Alternatives**: A `paused_at`/`paused_seconds` on the order read by every deadline consumer — touches every sweep and resource.

## R5 — Transitions added (Clarification Q1, FR-012)

**Decision**: Three `order_transition` rows: `weight_adjust_pending → disputed` ("dispute opened (spec 014)"), `disputed → weight_adjust_pending` and `disputed → at_inspection` ("dispute resolved, resume (spec 014)"). The `disputed → cancelled_inspection` row exists. `disputed → awaiting_balance` from a dispute frozen at `ready_to_collect` is never used: the resolve Action resumes only to `dispute.frozen_from` (a CHECK ties `outcome = 'resume'` to the order's next state through the Action; the history shows it).

**Rationale**: The table is data and the guard (`trg_order_transition`, DH006) reads it.

## R6 — `against_sale` only before payment (Clarification Q2)

**Decision**: Allowed only when `frozen_from ∈ {at_inspection, weight_adjust_pending, awaiting_balance}`; otherwise `dispute_outcome_not_allowed` (409). It moves the order `disputed → cancelled_inspection`, calls `ReleaseOrderDepositAction` (one `deposit_release`, actor = the resolving staff member — `trg_order_money` already requires it for `cancelled_inspection`) and `OpenSellerReturnAction` (no compensation transaction), exactly like an inspection cancel. The listing moves as an inspection cancel moves it.

**Rationale**: Reuses the only proven "cancel before payment" money path; no new ledger shape, nothing left in escrow.

## R7 — Permissions (dynamic codes, seeded once)

| Code | Label | Seeded to | Used by |
|---|---|---|---|
| `dispute.handle` | Handle disputes and freeze orders | COO, Operations, Finance (+ CEO) — Finance added at analysis (C1) | queue, detail, photos, pass on, resolve |
| `order.refund` | Refund a buyer in full | Finance (+ CEO) | resolve `against_sale` |
| `compensation.pay` | Pay compensation to a wallet (up to the caps) | Finance (+ CEO) | resolve with compensation |
| `compensation.uncapped` | Pay compensation above the caps | — (CEO only, who holds every code) | lifts both caps |

`customer.suspend` (existing) gates *Suspend the seller*. "Check the ID of someone collecting for another" is `order.handover` (IGI holds it; the matrix row is the same people) — no new code. The COO never gets `order.refund`, `compensation.*` (wallet-touching).

**Rationale**: "Up to cap" cannot be a role check (Principle II: no role names), so the cap is lifted by a separate code.

**Alternatives**: A per-role cap setting — the settings are global (`compensation.cap_*`), and roles are data.

## R8 — Compensation

**Decision**: A new `compensation` table (one row per payment: dispute, order, customer, party, amount, reason code, note, paid_by, ledger_txn_id) and one `compensation` ledger entry through `PostLedgerEntryAction`: `external_equity −X`, the customer's `cust_available +X` (Part 2 §9), actor = staff, tied to the order. Caps for a payer without `compensation.uncapped`: `amount ≤ compensation.cap_per_payment_egp` and `SUM(today's payments by this staff member, Cairo day) + amount ≤ compensation.cap_per_day_egp`, computed under a per-staff advisory lock (`pg_advisory_xact_lock(hashtext('comp:'||staff_id))`) so two concurrent payments cannot both pass. Reason codes: `igi_delay`, `dahab_mistake`, `wasted_trip`, `dispute_settlement`, `goodwill` (the design's list). A deferred check: every `compensation` row has its ledger entry and vice versa.

**Rationale**: The day cap needs a cheap, exact sum by payer; the ledger has the actor but no reason code.

## R9 — Dispute data and reference

**Decision**: `dispute` as in §16 plus: `dispute_no BIGINT` from a sequence and `dispute_ref = 'DSP-' || dispute_no` (the `TOP-{n}`/`WD-{n}` precedent; the design shows `DSP-4417`); `raised_as` (`buyer|seller`); `frozen_from order_state`, `frozen_at`; `outcome` (`resume|against_sale`); `passed_on_at`; `release_txn_id`; `reason` CHECK (six codes); `detail` CHECK 10–2000 (the docs' column was nullable; the spec requires it); `resolution_reply` CHECK 10–2000. Uniques: `(order_id, raised_by)` (one per party per order, Clarification) and a partial unique `(order_id) WHERE state <> 'resolved'`. Child tables: `dispute_photo` (0–5 per dispute, the upload's storage ref and mime) and `dispute_change` (append-only history: opened / passed_on / resolved, actor customer or staff, assignee, note).

**Guard**: a `dispute_transition` table (`open → passed_on`, `passed_on → passed_on`, `open → resolved`, `passed_on → resolved`) and a trigger raising SQLSTATE `DH009` (`illegal_dispute_transition`, 409); a `resolved` dispute is final (the trigger also blocks edits of a resolved row).

## R10 — Who sees what

**Decision**: The raiser's order shows `dispute`: reference, reason, their text, photo count, state (`open` / `being_looked_at` once passed on / `resolved`), outcome and Dahab's reply. The other party's order shows `frozen: true` while `disputed` and, after resolution, `dispute_outcome: resumed | cancelled` derived from the order's own history (the `order_state_change` move out of `disputed`) — never read from the dispute rows, which the database hides from them (R11). Staff notes on pass-on are never shown to customers. Photos are never served to customers (the raiser sees thumbnails count only, as the app shows the ones they just picked).

**Rationale**: The spec's default; the design says "Nothing is sent to the customer" on pass-on.

## R11 — RLS

**Decision**: Forced RLS on `dispute`, `dispute_photo`, `dispute_change`, `compensation`, `order_extension_request`. Read (analysis C2, Principle II): elevated scopes, or the customer the row belongs to — `dispute`: `raised_by` = current customer; `dispute_photo` / `dispute_change`: their dispute's `raised_by` = current customer; `compensation`: `customer_id` = current customer; `order_extension_request`: `seller_id` = current customer. Write by a customer only in the non-elevated `order` scope (spec 012 R2): `dispute`/`dispute_photo`/`dispute_change` rows with `raised_by` / actor = current customer; `order_extension_request` rows with `seller_id` = current customer. `compensation` is staff-only (elevated). The other party never reads these rows: their view comes from the order (R10). The partial unique index still stops a second unresolved dispute regardless of visibility; an `order_frozen` refusal carries `details.dispute_ref` only when the caller raised that dispute (staff always). `dispute_transition` and `extension_request_transition` are plain lookup tables (no RLS, as `order_transition`).

**Rationale**: The customer open Action must update the order (party) and write the history — the same shape as pay-balance, which already runs in the `order` scope.

## R12 — Lock order

**Decision**: listing → order → dispute → collection → extension request → ledger accounts (the spec 012 order, extended at the end). Paths that start from a dispute or a request read its order id unlocked, lock the listing and the order, then the dispute/request, and re-check.

**Rationale**: Every concurrent pair (open vs sweep, open vs pay-balance, resolve vs handover, resolve vs resolve, accept vs order receive) locks the order first, so exactly one wins with no deadlock.

## R13 — Dispute photos upload

**Decision**: New purpose `dispute_photo` (images only, identity size limit), stored encrypted on the private disk (`storeAt('dispute-photos', …)`), **verified gate, not trade** — a suspended customer may open a dispute (FR-005); `requiresTrade()` returns false for it. Tokens are consumed by the open Action (0–5, each the caller's, purpose-checked, single use). Staff fetch a photo through `GET /dashboard/disputes/{dispute}/photos/{photo}` (`dispute.handle`), one `dispute.photo_viewed` audit row per view.

## R14 — Proxy collection

**Decision**: The named proxy lives on the existing `collection` row (schema §11: `is_proxy`, `proxy_name`, `proxy_phone`, `proxy_id_storage_ref`, CHECK `proxy_needs_details`), plus `proxy_acceptance_id` (FK `agreement_acceptance`), `proxy_named_at`, `collected_by_proxy`, `proxy_id_checked_by`. `is_proxy` means "a proxy is authorised"; removal clears the four proxy fields and sets `is_proxy = false`; replacing overwrites them with a new acceptance. Upload purpose `proxy_id` (trade gate, as in Part 2 §3). Legal document `collection_proxy_authorisation` v1 (EN/AR from the prototype's tick), accepted with context `collection_proxy`. Phone validated with the registration rule (E.164, `^\+[1-9]\d{7,14}$`), not required to be a Dahab customer's.

**Handover**: body gains `collector: "buyer" | "proxy"` (default `buyer`) and `proxy_id_checked: bool`. `collector = proxy` with no proxy named, or without `proxy_id_checked = true` → `proxy_details_missing` (422) before the code is checked (no attempt counted). The proxy collection sets `collected_by_proxy = true`, `proxy_id_checked_by` = staff. The buyer may still collect in person while a proxy is named.

**Proxy SMS** (Clarification): one on-demand SMS (`Notification::route('sms', phone)`), no code, after commit: the buyer's first name, the piece, the branch and "bring your ID". The buyer gets the usual in-app + SMS/email confirmation.

**Seller privacy**: the seller is a party and the `collection` policy is per order; the seller's order Resource never includes proxy fields, and a leak test covers it.

## R15 — Extension requests

**Decision**: A new `order_extension_request` table: order, seller, reason (`travelling|emergency|branch_closed|other`), detail 10–1000, state (`waiting|accepted|refused|lapsed`), requested_at, deadline at request, answered_by, answered_at, answer_note 10–1000, hours_granted (6/12/24/48), extension_id (FK `order_deadline_extension`). Partial unique `(order_id) WHERE state = 'waiting'`. `extension_request_transition` (`waiting → accepted|refused|lapsed`) with guard SQLSTATE `DH010` (`illegal_extension_request_transition`, 409).

- **Send**: seller, order `awaiting_delivery`, `reach_branch_deadline` in the future, none waiting → `extension_request_pending` (409) otherwise; verified gate (a suspended seller still winds down an open order).
- **Accept** (`order.extend_deadline`): new deadline = `WorkingHoursResolver::addWorkingMinutes(current reach_branch_deadline, hours × 60, order.branch_id)` (the spec 004 resolver spec 012 uses), then `ExtendOrderDeadlineAction` inside the same transaction (its guards, audit and both-party notification), request → `accepted` with `extension_id`. Hours outside {6,12,24,48} → 422.
- **Refuse**: request → `refused`, seller told (SMS + email + in-app).
- **Lapse**: `MovesOrder::moveOrder` lapses a waiting request whenever an order leaves `awaiting_delivery` (receive, seller cancel, missed deadline, staff cancel) — one place, same transaction, actor-less state change recorded on the request (`answered_by` null, state `lapsed`).
- **"Sold elsewhere"**: the app routes to the existing seller cancel; no request row.

## R16 — Notifications

**Decision**: `OrderEvent` gains `dispute_opened` (both parties; "the order is on hold"), `dispute_resolved` (the raiser: the reply; the other party: resumed / cancelled), `extension_refused` (seller), `extension_requested` (none — staff see the queue), `proxy_named` (buyer). Accepting an extension reuses `deadline_extended` (both parties) through the spec 012 Action. SMS + email after commit through `TellsOrderParties`; EN/AR templates.

## R17 — Staff listing and counts

**Decision**: `GET /dashboard/disputes` — filters `state` (`open|passed_on|resolved`, default unresolved), `assigned_to=me`, `q` (DSP/DH reference); keyset cursor on `(opened_at, dispute_id)`; `meta.counts {open, passed_on}` for the navigation badge. `GET /dashboard/dispute-assignees` lists active staff holding `dispute.handle` (id, name, role display names) so the pass-on modal never needs `staff.view`. `GET /dashboard/extension-requests` — `state` (default `waiting`), `month=YYYY-MM` for *Extension requests this month*; `order.extend_deadline` or `order.view`.

## R18 — Order resources

**Decision**: Customer order detail adds `frozen`, `dispute` (R10), `extension_request` (seller only: state, reason, answer note, hours granted), `proxy` (buyer only: name, phone masked except last 3, named_at) and `can` flags: `report_problem`, `ask_more_time`, `name_proxy`. Staff order detail adds `disputes[]` (ref, raised_as, state, outcome), `extension_request`, `proxy` (name, phone, named_at, has_id_photo, collected_by_proxy) and `can.resolve_dispute`, `can.answer_extension`, `can.view_proxy_id`; the list rows add `frozen` and `has_waiting_extension`. `GET /dashboard/orders/{order}/proxy-id` returns the decrypted image to `order.handover` or `order.view` holders, audited `order.proxy_id_viewed`.

## R19 — Customer file

**Decision**: The spec 007 activity list includes `dispute.opened` (raiser) and `dispute.resolved` events with the reference; the file gets `disputes_raised` (count) so *Repeated disputes* has a visible basis. No new endpoint.

## R20 — Tests that truncate

**Decision**: `dispute_transition` and `extension_request_transition` join the keep lists of `OrderConcurrencyTest`, `OrdersPerformanceTest`, `WithdrawalConcurrencyTest`, `WithdrawalPerformanceTest` (and the new `DisputeConcurrencyTest`), with the `collection_proxy_authorisation` legal document re-seeded where those tests rebuild legal documents.

## R21 — Local seeder

**Decision**: `LocalDisputeSeeder` (local only, after the spec 012/013 seeders), entirely through the real Actions: an order frozen at awaiting balance with a buyer dispute; one passed on; one resolved `resume` with compensation; one resolved `against_sale` before payment; a ready-to-collect order with a named proxy; an awaiting-delivery order with a waiting extension request, one accepted and one refused.

## R22 — Customer App base URL

**Decision**: The app's default `API_BASE_URL` stays as built; local runs against this worktree pass `--dart-define=API_BASE_URL=http://127.0.0.1:8000/api/v1` (the user's local backend port). Changing the compiled default is a separate one-line decision for the user.
