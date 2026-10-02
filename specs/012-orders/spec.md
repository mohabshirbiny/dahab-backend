# Feature Specification: Orders — delivery, inspection, balance, settlement and collection

**Feature Branch**: `feature/orders` (backend and dashboard, both branched from `feature/buy-requests` because spec 011 is not merged to `main` yet; the Customer App has no repository). Spec work runs on the worktree branch `feature/orders` (worktree `orders-spec-012-62a1f7`).

**Created**: 2026-10-01

**Status**: Draft

**Input**: User description: "Orders (spec 012) — the life of an order after the seller accepts a buy request, plus the Dashboard Orders page and a new Dashboard Buy requests page. Backend: the order state machine after awaiting_delivery — the piece reaching the branch and the reach-branch deadline sweep; the seller cancelling (and the count toward suspension); branch change / deadline extension; IGI inspection and its result (pass / adjusted / fail); the buyer's balance payment and its deadline; deposit forfeiture when the buyer never pays; settlement through escrow (commission, VAT, spread) to the seller's wallet; every money movement a balanced ledger entry. Dashboard: the Orders page live, the Inspections page if in scope, and a new Buy requests page (every open buy request across listings, highlighting ones close to expiry) with a new read endpoint and a view permission. Flutter: the order life after acceptance live for buyer and seller."

## Context

- **Sources**:
  - Technical Spec Part 2 §5 (seller cancel, admin change-branch, admin extend-deadline), §6 (IGI work list, receive, the immutable inspection result and its derived outcome, the buyer's settlement decision), §7 (pay-balance as the settlement event, escrow pass-through, collection code, handover as a physical event), §11 (reach-branch, balance-payment, seller-return and collection sweeps), §12 (error codes).
  - Technical Spec Part 3 §1.3–§1.4 (calendar vs working deadlines; extensions are explicit instants), §2 (the two prices and the spread), §3 (settlement math, worked examples §3.3 and §3.4 to the piastre, rounding residue to Dahab), §5 (reach-branch, branch change, receive), §6 (first-sale advance), §7 (inspection outcome decision and consequences, corrections are new rows), §9.1 (seller cancellations threshold, karat/fake suspension), §10 (buyer no-pay → forfeiture and seller return; paid but uncollected), §12 (jobs).
  - Technical Spec Part 1 §3.4 (the IGI branch account: branch-scoped, sees no prices, wallets or contact details), §4.1 (*Extend a deadline on request*, *Change the inspection branch on an open order*, *Cancel an order*, *Enter an inspection result*, *Confirm handover at the counter*, *Check the ID of someone collecting for another*).
  - Schema: `04_schema_market.sql` §9 `"order"`, `order_branch_change`, `order_deadline_extension`, `seller_cancellation`; §10 `inspection_result` (append-only, `karat_rule`, `karat_mismatch_forces_cancel`), `settlement_decision`; §11 `collection`, `seller_return`; `tax_invoice`. `01_schema_core.sql`: `order_state`, `listing_state`, ledger kinds `deposit_forfeit`, `settlement_seller`, `commission`, `spread`, `vat`, `balance_payment`, `weight_adjustment`; settings `deposit.seller_forfeit_share_pct` (50), `deadline.buyer_pay_days` (10), `deadline.collect_weeks` (3), `deadline.seller_return_weeks` (3), `inspection.weight_tolerance_pct` (1.5), `suspension.cancellations_threshold` (2), `commission.*`, `vat.pct`. `05_schema_security.sql`: `order_transition`, `listing_transition`. `03_schema_ledger.sql`: the internal accounts `escrow`, `dahab_commission`, `dahab_spread`, `vat_payable`, `external_equity`.
  - Customer prototype: the seller's order card (*Bring the piece to IGI*, "Deadline to reach IGI … 9 hours 40 minutes left", *Ask for more time* with a reason, *Cancel the sale* — "This cancels the sale, it does not extend it … Two of these and you cannot list for a while"), the inspection result screen ("What IGI found — weight you listed / weight measured — the inspector's note — certificate"), the buyer's *Balance to pay* / *Pay balance* / "Once you pay, the seller is paid straight away and a collection code appears here", *Show this code at the counter*, *Collect before*, the cancelled cards (declined adjustment, buyer never paid → "you keep half of the buyer's deposit", *Your code* to collect the returned piece), *Collection window passed*.
  - Dashboard design: the Orders page (filters *Waiting for the seller*, *On the way to IGI*, *Waiting for the balance*, *Ready to collect*, *Past deadline*, *Needs a decision*; columns order, piece, seller → buyer, value, held, stage, deadline; *Pieces needing a decision*; *More time requested* with *Extend by 6/12/24/48 working hours*; *Extension requests*), the Inspections page (what IGI entered, the result list with stated vs measured, difference and outcome). There is no Buy requests page in the design (product-owner decision 2026-10-01).
- **What exists** (specs 004–011): the order row created at acceptance (`order_ref`, branch, `reach_branch_deadline`, `locked_total_price`, state `awaiting_delivery`), the accepted request with its deposit still held, the staff cancel-acceptance (`order.cancel` → `cancelled_staff`, full refund, relist or withdraw); the money service `PostLedgerEntryAction` and the internal accounts; the price calculator (spec 005) and the per-request locked buyer rate (`buy_request.locked_unit_rate` = the karat's sell-side rate per gram); the working-hours resolver (spec 004); staff branch assignment (spec 004) and the seed role `igi_branch`; customer suspension and its listing hold (specs 007, 010, 011); the idempotency layer, audit log, system actor, forced RLS, SMS + email after commit.
- **What does not exist**: no order endpoints for customers; no `order_branch_change`, `order_deadline_extension`, `seller_cancellation`, `inspection_result`, `settlement_decision`, `collection`, `seller_return`, `tax_invoice` tables; no order sweeps; the order transitions beyond `cancelled_staff` are never exercised; the seller's side of the price is not locked anywhere (spec 011 left it indicative); the Dashboard Orders and Inspections pages are placeholders; the Customer App's order cards run on `MockOrdersRepository`.

## Clarifications

### Session 2026-10-01

- Q: One spec or two? → A: **One spec (012)** for the whole order life. Out of scope: the first-sale advance, disputes and freezing, proxy collection, tax invoices and ETA filing, the free 0% relist after collection, market makers, and the manual post-window refund from escrow (the manual hand-over is in).
- Q: Who marks a piece received, and how is branch scope decided? → A: New permission `order.receive` (CEO, Operations, `igi_branch`). Scope comes from the staff member's **assigned branch** (spec 004): assigned → that branch only (`wrong_branch`, 403); unassigned → any branch. The same scope applies to entering results and to handovers. No role names in code.
- Q: Seller cancel / missed reach-branch deadline — listing and suspension count? → A: The listing moves `accepted → withdrawn` (final; the seller still has the piece and may list it again as a new listing); the buyer is refunded in full. Both an explicit cancel and a missed deadline count. When the seller's cancellations **since their last reinstatement** reach `suspension.cancellations_threshold` (2, read live), the seller is suspended automatically by the system actor, with a new reason `repeated_cancellations`, in a separate operation run by the order sweep within one scheduler minute (so each request keeps exactly one actor — analysis C2).
- Q: Who may change the branch or extend a deadline, how many times? → A: Staff only, new permissions `order.change_branch` and `order.extend_deadline` (CEO, COO, Operations), **no cap**. A branch change keeps the clock running, with an optional extension. Extensions cover `reach_branch`, `balance` and `collect`: forward only, reason required, audited. There is no seller request flow: the seller contacts support.
- Q: Who records the IGI result, and how is an adjusted price set? → A: New permission `inspection.enter` (CEO, `igi_branch`), branch-scoped. The inspector enters the measured karat, weight, stone grade, certificate number and note, plus two flags: *counterfeit* and *stone below claim*. The server derives the outcome (Part 3 §7.1). For a weight adjustment, the new price is the locked per-gram rates × the measured weight. For a stone regrade there is no automatic repricing: a staff member with the new permission `order.price_adjust` (CEO, COO, Operations) enters the proposed new price, and only then is the buyer asked. The inspector never sees prices. Results are sent to customers automatically; there is no message-approval step.
- Q: The buyer declines an adjusted price — what happens to the piece? → A: The buyer gets a full refund and the seller is not suspended. The piece waits at the branch as a return (`awaiting_seller_return`, a seller code, 3 calendar weeks, no compensation). The seller either collects it or relists from the app (`awaiting_seller_return → live`); a relisted gold piece takes the IGI-measured karat and weight in place of the stated ones.
- Q: Which seller-side price does settlement use? → A: **Locked at acceptance.** The order stores the seller's per-gram rate (`sellers_get`, the "you would receive" the seller saw). Settlement uses both locked rates × the IGI weight; commission, VAT and the minimum are read live at payment. If the spread comes out negative (gold rose between the buyer's join and the acceptance), Dahab absorbs it as a negative `dahab_spread` leg.
- Q: How does the buyer pay the balance? → A: From wallet **available only, in full, once** — no partial payments, no gateway. The deadline is `deadline.buyer_pay_days` (10) **calendar days** from the pass, or from the buyer accepting an adjustment. An adjustment left unanswered for the same 10 days is treated as a **decline** (refund, no forfeiture). `insufficient_funds` carries the amount due, the available balance and the shortfall.
- Q: How is the forfeited deposit split and booked? → A: One `deposit_forfeit` transaction: buyer held −deposit; seller available +deposit × `deposit.seller_forfeit_share_pct` (50, read live, half-up to the piastre); `dahab_commission` +the remainder (the rounding residue goes to Dahab). No VAT on it. The piece goes to `awaiting_seller_return` (hashed seller code, 3 calendar weeks); the seller collects or relists; past the window → `seller_unclaimed` (manual).
- Q: When is the seller paid, and which ledger lines? → A: **At pay-balance**, in one transaction with the escrow pass-through and the Part 3 §3.3 legs. The rounding residue goes to `dahab_spread` (gold) or `dahab_commission` (stones). Any excess deposit is refunded when the total is at or below the deposit. The money is available to the seller at once. No first-sale advance. The settlement figures are stored on the order for display.
- Q: Buy requests page — permission, roles, fields, actions? → A: New permission `buy_request.view` (CEO, COO, Operations; editable from the Dashboard). **Read-only.** Buyer and seller appear by display reference only, linked to the Customer file for holders of `customer.view`. Columns: piece, listing reference, seller, buyer, place in line / queue length, locked price, deposit held, requested at, reply deadline. Filters: state (queued by default, accepted, ended), listing, near expiry (under 6 hours, highlighted) and branch. Sorted by reply deadline.
- Q: Notifications, and what do Orders page actions require? → A: **Every event plus reminders**, by SMS plus email when the customer has one, after commit, in their language, never for the customer's own action except the buyer's collection code. Reminders: one 3 working hours before the reach-branch deadline, and one 24 hours before the balance deadline. Orders page: `order.view` (CEO, COO, Finance, Operations) to see; each action needs its own permission (`order.receive`, `order.change_branch`, `order.extend_deadline`, `order.cancel`, `order.price_adjust`, `order.handover`).
- Q: How does collection work; is the Inspections page in scope? → A: New permission `order.handover` (CEO, `igi_branch`), branch-scoped. A 6-digit code is sent by SMS and shown in the buyer's app; only its hash is stored. Staff enter it after checking the buyer's ID on site → `completed`; no money moves. Wrong codes are audited and limited: 5 tries, then a 15-minute lock per order. Past 3 calendar weeks → listing `uncollected_expired`, and the buyer is told; staff can still hand over later. A returned piece uses the same code flow with the seller. Dashboard: Orders, Inspections (the results list plus the inspector's work list and result form) and Buy requests are all live. No proxy collection.
- Q: How may an inspection result be corrected? → A: By a new result naming the one it supersedes, never by an edit. Allowed only while the order is `weight_adjust_pending` or `awaiting_balance` with nothing paid and no buyer decision recorded. It re-derives the outcome and re-runs its effects. After a cancellation or a payment there is no correction (that is a dispute, a later spec).
- Q: What may a suspended customer do with an open order? → A: **Wind down.** Order reads, seller cancel, the buyer's decision and pay balance use the **verified** gate (Part 3 §9.2): a suspended buyer can still pay and collect, and a suspended seller can still deliver and be paid. Relisting a returned piece needs the **trade** gate.
- Q: Karat mismatch or counterfeit — the seller? → A: **Suspended at once**, in the same operation as the result: the order becomes `cancelled_inspection`, the buyer is refunded in full, and the seller is suspended by the inspector's action with the existing reason `piece_misrepresented` (spec 007/010/011 effects). The piece is returned with a code and no compensation; relisting is blocked while the seller is suspended.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The seller brings the piece and the branch receives it (Priority: P1)

After accepting, the seller sees the order: the branch, its opening hours and the deadline to reach it with the time left. They bring the piece. Staff at that branch find the order and mark the piece received; the order moves to inspection and both sides see it.

**Why this priority**: nothing else in the order can happen until the piece is in Dahab's custody.

**Independent Test**: An order accepted at Nasr City is awaiting delivery; the seller's order shows the deadline; a staff member assigned to Nasr City marks it received; the order is at inspection, the listing is at inspection, the buyer and seller both see "At IGI". A staff member assigned to another branch cannot mark it received.

**Acceptance Scenarios**:

1. **Given** an order awaiting delivery, **When** the buyer or the seller opens their orders, **Then** each sees only their own orders with the order reference, the piece summary, the branch (name, address, hours), the state, the reach-branch deadline and the deposit held; the other party appears only by display reference.
2. **Given** an order awaiting delivery at branch B, **When** a staff member allowed to receive pieces and allowed to act at B marks it received, **Then** in one operation the order becomes `at_inspection`, the listing moves `accepted → at_inspection` with a history row, an audit row names the staff member, and both sides are told.
3. **Given** a staff member assigned to a different branch, **Then** marking it received is refused as `wrong_branch` (403).
4. **Given** an order in any other state, **Then** it is refused as `illegal_order_transition` (409); the same idempotency key twice takes effect once.

---

### User Story 2 - A seller who cannot deliver cancels, or misses the deadline (Priority: P1)

A seller who cannot bring the piece cancels the sale; the buyer gets the deposit back at once and the cancellation counts against the seller. If the deadline passes with the piece not received, the system does the same on its own. Reaching the threshold of cancellations suspends the seller within a minute.

**Why this priority**: the buyer's deposit must never be stranded by a seller who does not show up.

**Independent Test**: A seller cancels an awaiting-delivery order; the buyer's held deposit returns to available; a seller-cancellation record exists; the listing leaves the market. A second order's deadline passes; the sweep cancels it the same way; the seller now has 2 cancellations and is suspended (their live listings held), with the system actor recorded.

**Acceptance Scenarios**:

1. **Given** an order awaiting delivery, **When** its seller cancels it, **Then** in one operation the order becomes `cancelled_seller`, one balanced `deposit_release` returns the buyer's deposit from held to available, a seller-cancellation record is written, the listing moves `accepted → withdrawn` with a history row, and the buyer is told.
2. **Given** an order awaiting delivery past its reach-branch deadline, **When** the sweep runs, **Then** the same happens as the system actor, one order per operation, safe to run twice and racing safely with receive and seller-cancel (exactly one wins).
3. **Given** the seller's cancellation count reaches `suspension.cancellations_threshold`, **Then** within one scheduler minute the order sweep suspends the seller, in its own operation, by the system actor with the reason `repeated_cancellations` (counting cancellations since their last reinstatement), with spec 007/010/011 suspension effects; the cancel itself never suspends.
4. **Given** an order in any other state, **Then** the seller's cancel is refused as `illegal_order_transition` (409).

---

### User Story 3 - Staff change the branch or extend a deadline (Priority: P2)

When a seller cannot reach the chosen branch, staff change the branch to another one the seller named, keeping the clock running, and may extend the deadline. Staff may also extend the balance or collection deadline on request. Every change is audited with a reason and both sides are told.

**Why this priority**: real life — a closed branch, a family emergency — needs a human lever that does not cost anyone their deposit.

**Independent Test**: Staff change an awaiting-delivery order from Nasr City to Zamalek (one of the listing's options) with a reason; the deadline is unchanged; a branch-change row and an audit row exist. Staff extend the reach-branch deadline by 12 working hours; a deadline-extension row records old and new; the new deadline is later.

**Acceptance Scenarios**:

1. **Given** an open order awaiting delivery, **When** a staff member with the branch-change permission names another enabled branch among the listing's options with a reason (and optionally a new deadline), **Then** the branch changes, a branch-change record and an audit row are written, the deadline keeps running unless extended (then an extension record too), and both sides are told. A branch outside the options → `branch_not_in_options` (409); a closed order → `order_not_open` (409).
2. **Given** an order whose reach-branch, balance or collection deadline is running, **When** a staff member with the extend permission sets a later deadline with a reason, **Then** an extension record keeps the old and new deadlines, the order's deadline moves, an audit row is written, and both sides are told. A deadline that does not move forward is refused (422); a deadline that is not running in the order's state is refused (409).

---

### User Story 4 - The inspector records the result (Priority: P1)

The inspector at the branch sees the pieces received at their branch with only what an inspection needs: the reference, piece type, stated karat and weight, category, expected stones — no prices, wallets or contact details. They enter what they measured. The system decides the outcome: pass, weight adjustment, stone regrade, karat cancel or fake cancel. A mistake is corrected by a new result that supersedes the old one; nothing is ever edited.

**Why this priority**: inspection decides whether the sale proceeds and at what price.

**Independent Test**: A 21K 10.000 g piece measured at 9.900 g, 21K → `pass`, order awaiting balance with a balance deadline 10 days later and the balance computed on 9.900 g. Measured 9.700 g → `weight_adjust`, order waiting for the buyer's decision. Measured 18K → `karat_cancel`, buyer refunded in full, seller suspended.

**Acceptance Scenarios**:

1. **Given** an order at inspection at the inspector's branch, **When** they submit the measured karat, weight, (stones) grade, certificate number and note, **Then** an immutable result is stored with the stated figures copied in, `karat_mismatch`, `weight_diff_pct` and the derived outcome, with an audit row; the listing moves `at_inspection → settling` unless the outcome cancels the sale (then `at_inspection → awaiting_seller_return`, scenario 4).
2. **Given** outcome `pass`, **Then** the order moves `at_inspection → inspection_passed → awaiting_balance` with `balance_due_deadline = now + deadline.buyer_pay_days` (calendar days); both sides are told with the final price on the measured weight.
3. **Given** outcome `weight_adjust`, **Then** the order moves to `weight_adjust_pending`, the new price is the locked per-gram rates × the measured weight, and the buyer is asked to accept or decline it; the seller is told. **Given** `stone_regrade`, **Then** the order moves to `weight_adjust_pending` and the buyer is asked only once a staff member with `order.price_adjust` has entered the proposed new price.
4. **Given** outcome `karat_cancel` or `fake_cancel`, **Then** in one operation the order becomes `cancelled_inspection`, the buyer's deposit is refunded in full, the seller is suspended by the inspector's action with the reason `piece_misrepresented`, the listing moves to `awaiting_seller_return` with a seller code and no compensation, and both are told.
5. **Given** a submitted result, **Then** it can never be changed or deleted; a correction is a new result naming the one it supersedes, and only the latest non-superseded result counts — allowed only while the order is `weight_adjust_pending` or `awaiting_balance` with nothing paid and no buyer decision recorded; the outcome is re-derived and its effects re-run.
6. **Given** an inspector of another branch, **Then** the work list does not show the order and submitting is refused as `wrong_branch` (403).

---

### User Story 5 - The buyer decides on an adjusted price (Priority: P2)

When the measured weight is outside the tolerance or a stone grades lower, the buyer sees what IGI found and the new price, and accepts it (then pays the balance) or declines (the deposit comes back in full and the seller is not punished).

**Why this priority**: an adjustment the buyer never answers would freeze the sale.

**Independent Test**: An order in `weight_adjust_pending`; the buyer accepts → `awaiting_balance` with a balance deadline and a recorded decision (old and new price). Another buyer declines → `cancelled_inspection`, the deposit back in full, the seller not suspended, the piece goes back to the seller.

**Acceptance Scenarios**:

1. **Given** an order in `weight_adjust_pending`, **When** its buyer accepts, **Then** a settlement decision (accepted, old price, new price) is recorded and the order moves to `awaiting_balance` with its balance deadline; the seller is told.
2. **When** its buyer declines, **Then** in one operation the decision is recorded, the order becomes `cancelled_inspection`, the deposit is released in full, the seller is not suspended, the listing moves to `awaiting_seller_return` (seller code, 3 calendar weeks, no compensation), and the seller is told.
3. **Given** anyone but the buyer, **Then** not found; any other state → `illegal_order_transition` (409).
4. **Given** the buyer has not answered `deadline.buyer_pay_days` calendar days after being asked, **Then** the sweep treats it as a decline (as scenario 2, system actor, no forfeiture).

---

### User Story 6 - The buyer pays the balance and the seller is paid (Priority: P1)

The buyer sees the balance due — the final price on the measured weight minus the deposit already held — and the deadline. They pay from their wallet. In that one moment the seller is paid their proceeds, Dahab takes commission, VAT and spread, and the buyer receives a collection code.

**Why this priority**: this is the sale; it is the only moment money reaches the seller (outside the first-sale advance).

**Independent Test**: The §3.3 worked example to the piastre: buyer total 55,631.2500, deposit 11,126.2500, balance 44,505.0000; after payment the buyer's held is 0 and available down by 44,505.0000; the seller's available up by 54,684.7500; `dahab_commission` +600, `vat_payable` +84, `dahab_spread` +262.5; escrow nets to zero; the order is ready to collect with a collection deadline 3 weeks later and a code delivered to the buyer. The §3.4 example on 9.900 g likewise.

**Acceptance Scenarios**:

1. **Given** an order awaiting balance before its deadline, **When** its buyer pays, **Then** in one balanced ledger transaction (escrow pass-through): buyer available −balance, buyer held −deposit, escrow +buyer total, escrow −buyer total, seller available +seller proceeds, `dahab_commission` +commission, `vat_payable` +VAT, `dahab_spread` +spread (gold only, omitted when zero); the order moves to `ready_to_collect`, the listing to `sold`; a collection record holds only the hash of a code sent to the buyer; `collect_deadline = now + deadline.collect_weeks` (calendar); both sides are told.
2. **Given** the buyer's available balance is below the balance, **Then** `insufficient_funds` (409) with the amount due, available and shortfall; nothing changes.
3. **Given** the deadline has passed, **Then** `balance_deadline_passed` (409).
4. **Given** the recomputed total is at or below the deposit, **Then** no balance is charged and the excess deposit is refunded to available in the same transaction.
5. **Given** a rounding residue, **Then** it is posted to `dahab_spread` (gold) or `dahab_commission` (stones), never to a customer; the transaction sums to zero.
6. **Given** a diamond or gold-with-diamond piece, **Then** there is no spread leg and commission is on the value above gold (Part 3 §2.4, §3.6).
7. **Given** the same payment sent twice with the same key, **Then** it settles once.

---

### User Story 7 - A buyer who never pays forfeits the deposit; the piece goes back (Priority: P1)

If the balance deadline passes unpaid, the sale is cancelled on its own. The buyer's deposit is forfeited: half to the seller as agreed compensation, the rest to Dahab. The piece waits at the branch for the seller, who collects it with a code or puts it back on the market.

**Why this priority**: the deposit's whole purpose; the seller must be made whole for a buyer who walks away.

**Independent Test**: An order past its balance deadline; the sweep cancels it as `cancelled_buyer_nopay`; one balanced `deposit_forfeit` transaction: buyer held −11,640, seller available +5,820, Dahab +5,820; a seller-return record with a hashed code and a deadline 3 weeks later; the listing awaits the seller's return; both told.

**Acceptance Scenarios**:

1. **Given** an order awaiting balance past its deadline, **When** the sweep runs, **Then** as the system actor, in one operation: `awaiting_balance → cancelled_buyer_nopay`; the forfeiture split by `deposit.seller_forfeit_share_pct` (half-up, residue to Dahab); the listing → `awaiting_seller_return`; a seller-return record (branch, hashed code, `return_deadline`, the compensation transaction); both told; safe to run twice and racing safely with pay-balance.
2. **Given** a returned piece, **When** staff at the branch confirm the seller collected it with the code, **Then** the listing becomes `withdrawn` and the return is marked collected; **or When** the seller (trade gate) chooses to relist, **Then** the listing becomes `live` (empty queue); after an inspection, a gold listing takes the measured karat and weight.
3. **Given** the return deadline passes uncollected, **Then** the listing becomes `seller_unclaimed` and the seller is told; disposition is manual.

---

### User Story 8 - The buyer collects the piece (Priority: P2)

The buyer brings the code and their ID to the branch; staff check the code and hand the piece over. No money moves. If the buyer never comes within the window, the listing shows the window has passed and staff decide case by case.

**Why this priority**: closes the order; money already moved at payment.

**Independent Test**: A ready-to-collect order; staff at the branch enter the buyer's code; the order is `completed` with the collection time and the staff member; a wrong code is refused as `invalid_collection_code`. An order past its collect deadline: the listing becomes `uncollected_expired` and the buyer is told; the order stays `ready_to_collect`.

**Acceptance Scenarios**:

1. **Given** an order ready to collect at branch B, **When** a staff member allowed to hand over at B enters the right code, **Then** the order becomes `completed`, the collection is stamped (time, staff), audited, no ledger entry; both told.
2. **Given** a wrong code, **Then** `invalid_collection_code` (422 — Part 2 §12 says 401, changed because a 401 signs Dashboard staff out; recorded in the docs) with the attempts left, and an audit row; after 5 wrong codes the order's handover is locked for 15 minutes.
3. **Given** the collect deadline passes, **Then** the listing `sold → uncollected_expired`, the buyer is told; staff may later hand over (`uncollected_expired → sold`, order completed).

---

### User Story 9 - Staff run orders from the Dashboard (Priority: P1)

Staff open the Orders page: every order with its stage, deadline, held and paid amounts, buyer and seller references, filters by stage, past deadline and needing a decision, and an order's detail with its timeline (acceptance, branch changes, extensions, receipt, inspection results, decision, payment, collection, cancellation). Each action shows only to staff holding its permission.

**Why this priority**: operations cannot chase or unblock orders without it.

**Independent Test**: A staff member with order view sees six orders across states, filters "Past deadline", opens one, sees its timeline and the actions their permissions allow.

**Acceptance Scenarios**:

1. **Given** a staff member with the order view permission, **Then** the Orders page lists orders newest first with filters (state group, past deadline, branch, search by order reference or customer display reference) and counts per group, and an order detail with its history.
2. **Given** the inspection permission, **Then** the Inspections page lists results with stated vs measured, the difference and the outcome, and the inspector's work list for their branch.
3. **Given** a staff member without a permission, **Then** its action is hidden and the server refuses it with 403 and an audit row.

---

### User Story 10 - Staff see every open buy request (Priority: P2)

A new Buy requests page shows every open buy request across listings: the buyer (display reference), the piece, the place in line, the deposit held and the seller-reply deadline, highlighting the ones close to expiry, so operations can chase sellers before buyers are released.

**Why this priority**: product-owner decision (2026-10-01): it is the queue view operations asked for; spec 011 showed queues only inside a listing.

**Independent Test**: With 5 queued requests across 3 listings, one due in 2 hours, a staff member with the buy-request view permission sees all 5, sorted by reply deadline, the near-expiry one highlighted; a filter by listing narrows to its line.

**Acceptance Scenarios**:

1. **Given** the view permission, **Then** `GET` the open buy requests across listings, keyset-paged, sorted by reply deadline (soonest first), filterable by state, listing and near-expiry (within a window), each with request id, listing reference and piece summary, seller display reference, buyer display reference, position and queue length, locked total price, deposit, requested time and reply deadline.
2. **Given** no permission, **Then** 403 and an audit row.
3. **Then** no buyer or seller name, phone or email appears; display references link to the Customer file for holders of `customer.view`. The page is read-only.

---

### User Story 11 - The Customer App runs the order life on the API (Priority: P1)

Buyer and seller see their orders live: the tracking timeline, *Bring the piece to IGI* with the deadline, *Cancel the sale*, the inspection result, *Accept / Decline* the new price, *Pay balance*, the collection code, the returned-piece code, and the cancelled cards — all from the API.

**Why this priority**: customers reach the feature only through the app.

**Independent Test**: In the Customer App, the seller sees the deadline countdown; staff mark the piece received; the buyer sees "At IGI"; staff enter a pass; the buyer pays; the seller's wallet shows the proceeds and the buyer's card shows the collection code.

**Acceptance Scenarios**:

1. **Given** the app, **Then** the order cards and detail come from the order endpoints; `MockOrdersRepository` is replaced for orders; anything still mock (invoices, proxy collection, disputes, first-sale advance) is listed in the report.
2. **Given** a refusal (`insufficient_funds`, `balance_deadline_passed`, `illegal_order_transition`), **Then** the app shows the API's figures and message.

### Edge Cases

- **Receive and the reach-branch sweep at the same moment**: exactly one wins; a received piece is never cancelled, a cancelled order is never received.
- **Pay-balance and the balance sweep at the same moment**: exactly one wins; the deposit is either settled or forfeited, never both.
- **Seller cancels while staff receive**: exactly one wins.
- **Buyer decides while the unanswered-adjustment sweep runs**: exactly one wins; the decision or the decline is recorded once.
- **Correction after the buyer decided or paid**: refused (`illegal_order_transition`); a dispute is a later spec.
- **Seller reaches the cancellation threshold**: suspended by the sweep's suspension pass (system actor, its own operation) within a minute, whether the last cancellation was their own or the sweep's; their other open orders are honoured; a seller already suspended is skipped.
- **Seller suspended for a karat mismatch with a returned piece**: they may collect it; relisting is refused (`account_suspended`).
- **Branch disabled after acceptance**: the order keeps its branch; staff may change it to another option.
- **Deadline extension during a dispute**: disputes are out of scope; nothing freezes.
- **Seller suspended mid-order**: open orders are honoured (Part 3 §9.2) — the seller may still deliver, cancel and be paid; buyer suspended mid-order may still pay and collect (wind down).
- **Measured weight above stated within tolerance**: the buyer pays more, on the measured weight (locked formula).
- **Recomputed total at or below the deposit**: the excess deposit is refunded in the same transaction.
- **Gold price moves between acceptance and payment**: no effect — both per-gram rates are locked (buyer at the request, seller at acceptance); a negative spread is absorbed by Dahab.
- **Wrong collection code repeatedly**: rate limited and audited.
- **Karat changed after correction**: a superseding result may change the outcome only while the order has not moved past the earlier decision.
- **Seller-return or collection after the window**: manual disposition; no money moves automatically.

## Requirements *(mandatory)*

### Functional Requirements

**Orders, visibility and isolation**

- **FR-001**: A buyer and a seller MUST be able to list their own orders (newest first, keyset pages, filter by role and state group) and open one, under row-level security that isolates orders by buyer or seller. Each order carries: reference, role, state, piece summary, branch (name, address, hours), the other party's display reference only, locked total, deposit, deadlines that apply (reach branch, balance, collect), the latest inspection result in customer terms, the amounts due or paid, the collection code state (never the code in a list), and a timeline of events.
- **FR-002**: Every order state change MUST pass the database transition guard (`order_transition`) and every listing move the listing guard with a history row; an illegal move answers `illegal_order_transition` / `illegal_listing_transition` (409).

**Delivery**

- **FR-003**: Staff holding the receive permission and allowed to act at the order's branch MUST be able to mark a piece received: `awaiting_delivery → at_inspection`, listing `accepted → at_inspection`, audited, idempotent.
- **FR-004**: Branch scope: a staff member with an assigned branch MAY receive, inspect and hand over only at that branch (`wrong_branch`, 403); a staff member with no assigned branch may act at any branch. Scope is never derived from a role name.
- **FR-005**: The seller MUST be able to cancel an order awaiting delivery (verified gate — winding down is allowed while suspended): `cancelled_seller`, the buyer's deposit refunded in full (`deposit_release`, tied to the request and order), a `seller_cancellation` record, the listing `accepted → withdrawn`, idempotent; the buyer is told.
- **FR-006**: A scheduled sweep MUST cancel every order awaiting delivery past its reach-branch deadline exactly as FR-005, as the system actor, one order per operation, idempotent and race-safe.
- **FR-007**: When a seller's cancellations since their last reinstatement (or ever, if never suspended) reach `suspension.cancellations_threshold` (read live), the seller MUST be suspended by a scheduled pass, in its own operation, within one scheduler minute, by the system actor with the new reason `repeated_cancellations`, with all spec 007/010/011 suspension effects. A customer's cancel request never writes the suspension (one actor per request, Constitution I).

**Branch change and extensions**

- **FR-008**: Staff with the branch-change permission MUST be able to change the branch of an order awaiting delivery to another enabled branch among the listing's options, with a reason (10–1000 characters) and an optional later reach-branch deadline; recorded in `order_branch_change` (and `order_deadline_extension` when extended), audited, idempotent; both sides told. `branch_not_in_options`, `order_not_open` (409).
- **FR-009**: Staff with the extend permission MUST be able to set a later deadline for `reach_branch` (awaiting delivery), `balance` (awaiting balance) or `collect` (ready to collect), with a reason; recorded in `order_deadline_extension` (old, new, staff, reason), audited, idempotent; both told. Not forward → 422; deadline not running → 409.

**Inspection**

- **FR-010**: Staff with the inspection permission MUST see a work list of orders at inspection at their branch (all branches without an assigned branch) with only inspection fields (reference, piece type, category, stated karat and weight, expected stones) — no prices, no wallets, no contact details.
- **FR-011**: They MUST be able to submit a result (measured karat, measured weight, measured stone grade, certificate number, note, the inspector's counterfeit flag and stone-below-claim flag, optional `supersedes_id`); the server derives `karat_mismatch`, `weight_diff_pct` and the outcome per Part 3 §7.1; the row is immutable; audited; idempotent.
- **FR-012**: Outcome effects, each in one operation: `pass` → `inspection_passed → awaiting_balance` with `balance_due_deadline` (calendar days); `weight_adjust` → `weight_adjust_pending` with the recomputed price; `stone_regrade` → `weight_adjust_pending`, the buyer asked once staff with `order.price_adjust` enter the proposed price; `karat_cancel` / `fake_cancel` → `cancelled_inspection`, full deposit refund, seller suspended (`piece_misrepresented`), listing → `awaiting_seller_return` without compensation. Listing `at_inspection → settling` on any result that does not cancel.
- **FR-012a**: A correction MUST be a new result superseding the latest one, allowed only while the order is `weight_adjust_pending` or `awaiting_balance` with nothing paid and no buyer decision; it re-derives the outcome and re-runs its effects; otherwise `illegal_order_transition` (409).
- **FR-013**: The buyer (verified gate) MUST be able to accept or decline the recomputed price on an order in `weight_adjust_pending` (`settlement_decision` with old and new price): accept → `awaiting_balance` with the balance deadline; decline → `cancelled_inspection`, full refund, seller not suspended, listing → `awaiting_seller_return` (no compensation). A sweep MUST treat an adjustment unanswered for `deadline.buyer_pay_days` calendar days as a decline.

**Balance and settlement**

- **FR-014**: The final figures MUST be recomputed on the latest inspection's measured weight using the locked per-gram rates (buyer side from the request, seller side `sellers_get` stored on the order at acceptance) and the listing's making charge, with commission, VAT and minimum read live at payment; a negative spread is posted as a negative `dahab_spread` leg (Dahab absorbs it); stones use the asking price or, after a regrade, the accepted new price; exactly per Part 3 §2–§3 and reproducing §3.3 and §3.4 to the piastre.
- **FR-015**: The buyer (verified gate) MUST be able to pay the balance from their wallet's available balance only, in full, once, before the deadline: one balanced transaction with the escrow pass-through and the legs of Part 2 §7; `insufficient_funds`, `balance_deadline_passed`; idempotent. The order moves `ready_to_collect`, the listing `settling → sold`, `collect_deadline` set (calendar weeks), a collection code generated and only its hash stored, a 6-digit code sent to the buyer by SMS and shown to them in the app; the settlement figures stored on the order.
- **FR-016**: The seller MUST be paid at that moment, never before (no first-sale advance in this feature), and never twice.
- **FR-017**: For every order, the ledger MUST reconcile: while open, the buyer's deposit is held exactly once; when completed or ready to collect, one settlement transaction whose legs sum to zero and equal the computed figures; when cancelled (seller, staff, inspection), one full `deposit_release`; when cancelled for no-pay, one `deposit_forfeit` and no release.

**Forfeiture and the returned piece**

- **FR-018**: A scheduled sweep MUST cancel every order awaiting balance past its deadline (`cancelled_buyer_nopay`) with one balanced `deposit_forfeit`: buyer held −deposit, seller available +share (half-up), Dahab account +remainder; the listing → `awaiting_seller_return`; a `seller_return` record with branch, hashed seller code, calendar `return_deadline` and the compensation transaction; system actor; both told.
- **FR-019**: Staff allowed at the branch MUST be able to confirm the seller collected a returned piece with the seller's code (listing → `withdrawn`); the seller (trade gate) MUST be able to relist it instead (listing → `live`, a gold listing taking the measured karat and weight after an inspection). A sweep MUST move uncollected returns past their deadline to `seller_unclaimed` and tell the seller.

**Collection**

- **FR-020**: Staff with the handover permission at the branch MUST be able to confirm collection with the buyer's code: `ready_to_collect → completed`, `completed_at`, collection stamped with the staff member, audited, no ledger entry; wrong code → `invalid_collection_code`, audited; 5 wrong codes lock the order's handover for 15 minutes.
- **FR-021**: A sweep MUST move the listing of a ready-to-collect order past its collect deadline `sold → uncollected_expired` and tell the buyer; staff may later hand over (listing back to `sold`, order completed).

**Staff: Orders, Inspections, Buy requests**

- **FR-022**: Staff with the order view permission MUST be able to list and open orders (filters: state group, past deadline, needs a decision, branch, reference / customer display reference search; counts per group) with the full timeline; amounts are shown; buyer and seller by display reference with a link to the Customer file for holders of `customer.view`.
- **FR-023**: Staff with `buy_request.view` MUST be able to list buy requests across listings (US10): keyset pages, sorted by reply deadline, filters state (queued default, accepted, ended), listing, near expiry (under 6 hours, flagged) and branch; buyer and seller by display reference only; read-only.
- **FR-024**: Every new staff action MUST be gated by a permission from the catalogue, seeded and editable from the Dashboard: `order.view` (COO, Finance, Operations), `order.receive` (COO, Operations, igi_branch — the COO added during implementation so a COO can still manage the Operations role), `inspection.enter` (igi_branch), `order.price_adjust`, `order.change_branch`, `order.extend_deadline` (COO, Operations), `order.handover` (igi_branch), `buy_request.view` (COO, Operations); `order.cancel` exists; the CEO holds every code. Every action is audited with the staff member as actor and refused with 403 + audit otherwise. The Inspections page opens with `inspection.enter` or `order.view`.

**Notifications**

- **FR-025**: After commit, by SMS plus email when the customer has one, in their language: received at the branch (both); seller cancelled / deadline missed (buyer; seller for the sweep); branch changed / deadline extended (both); inspection result (both; buyer asked to decide when adjusted); decision made (seller); balance paid (seller: paid; buyer: the collection code); forfeited (both); returned piece waiting and window passed (seller); collected (both); collection window passed (buyer); plus reminders 3 working hours before the reach-branch deadline (seller) and 24 hours before the balance deadline (buyer), each sent once. No message for a customer's own action except the buyer's collection code. A failed message never undoes the change.

**Idempotency, audit, actors**

- **FR-026**: Every customer and staff POST MUST require an `Idempotency-Key`; every staff action, every sweep effect and every customer order write that reaches the other party's rows (cancel, decision, pay balance, relist — audited as `order.seller_cancelled` / `order.decided` / `order.paid` / `order.relisted` with the customer as actor, analysis C1) MUST be audited; sweeps run as the system actor; every ledger transaction names its actor and ties to the order (and the request where it touches the deposit).

**Apps**

- **FR-027**: The Dashboard MUST make the Orders page and the Inspections page live and add the Buy requests page and navigation entry, each gated by its permission, with every modal in the shared modal component.
- **FR-028**: The Customer App MUST run the buyer's and the seller's order life after acceptance on the API (FR-001, FR-005, FR-013, FR-015, the codes, the cancelled and returned cards).

**Quality and contract**

- **FR-029**: Feature tests through the HTTP boundary MUST cover every transition, every refusal, the sweeps (run by their commands, results read back through the API), idempotent replay, branch scoping, the §3.3 and §3.4 settlements to the piastre, stones settlement, rounding residue, the forfeiture split, concurrency races (receive vs sweep, pay vs sweep, cancel vs receive) and a reconciliation test over every order (FR-017).
- **FR-030**: Every new endpoint MUST carry OpenAPI annotations and a Postman request; the Technical Spec (Parts 1–3), the schema docs, `docs/platform/api-contract.md` and a feature doc `docs/features/orders.md` MUST be updated in the same change; a local seeder MUST create orders in each reached state through the real Actions.

### Key Entities

- **Order** (exists): reference, listing, request, buyer, seller, branch, state, deadlines (reach branch, balance, collect), locked total, completed / cancelled stamps; gains the seller-side locked rate (at acceptance), the stone-regrade proposed price and the settlement figures.
- **Branch change**: from, to, staff, reason, extended-to.
- **Deadline extension**: which deadline, old, new, staff, reason.
- **Seller cancellation**: order, seller, when (counted toward suspension).
- **Inspection result** (immutable): stated vs measured karat and weight, stone grade, certificate, note, mismatch, weight difference, outcome, supersedes, inspector, branch.
- **Settlement decision**: the buyer's accept / decline, old and new price.
- **Collection**: the hashed buyer code, collected at, handed over by (proxy fields unused in this feature).
- **Seller return**: the returned piece's branch, hashed seller code, return deadline, collected at, the compensation transaction.
- **Ledger transactions**: `deposit_release`, `deposit_forfeit`, the settlement set (`balance_payment` / `settlement_seller` / `commission` / `vat` / `spread`).
- **Open buy request** (exists): read across listings by staff.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: The two worked settlements of Part 3 §3.3 and §3.4 come out to the piastre, and 100% of settlement transactions sum to zero with escrow netting to zero.
- **SC-002**: 0 orders end with a deposit still held; 0 deposits are both released and forfeited or settled (reconciliation over every order).
- **SC-003**: A missed reach-branch or balance deadline is acted on within one scheduler minute.
- **SC-004**: A seller sees proceeds in their wallet within 5 seconds of the buyer's payment.
- **SC-005**: 0 inspection results are ever modified; 0 karat mismatches carry any outcome but `karat_cancel`.
- **SC-006**: 0 inspector-facing responses contain a price, wallet figure, name, phone or email; 0 customer-facing responses contain the other party's name, phone or email.
- **SC-007**: Operations can find every order past a deadline and every buy request due within the near-expiry window in one filter.

## Assumptions

- Out of scope (Clarifications): the first-sale advance, disputes and freezing, proxy collection, tax invoices and ETA filing, the free 0% relist after collection, market makers, the manual post-window refund from escrow, seller-initiated extension requests, staff approval of inspection messages.
- The IGI branch account is a Dashboard staff user with the `igi_branch` role (spec 002) and an assigned branch; there is no separate `/igi` surface — its endpoints live under `/dashboard/*`.
- Customer order endpoints live under `/customer/me/orders*`.
- Schema additions expected (detailed in the plan): the seller-side locked rate and the settlement figures on `"order"`; the price proposal for a stone regrade; the order event history; the handover attempt lock; the `repeated_cancellations` suspension reason; the listing and order transitions this spec reaches that the transition tables lack (e.g. `accepted → withdrawn` on a seller cancel, `settling → awaiting_seller_return` on an inspection cancel). All are reported against the schema docs.
- The IGI-confirmed weight is the basis of every figure (Part 3 §3.4); the locked per-gram rates and the making charge never change after acceptance.
- Display references identify customers to the other party and in staff lists; contact details are reached only through the Customer file.
