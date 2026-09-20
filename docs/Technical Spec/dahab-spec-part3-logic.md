# Dahab — Technical Specification

## Part 3 of 4: Backend Logic & Workflows

*Senior developer handoff · PostgreSQL 15+ · Depends on Part 1 (Authentication & Authorization) and Part 2 (API Endpoints). This part specifies the **logic the endpoints call into**: the working-hours deadline resolver, the full settlement and spread math, the first-sale advance, the queue concurrency model, the return-to-seller and uncollected-piece workflows, the suspension rules, the state-machine guards, and the scheduled jobs. It does not restate endpoint wire contracts (Part 2) or the request-context/permission/error machinery (Part 1); it references them.*

> **Sources of truth.** Where this part says *"schema"*, the object exists in the applied SQL (files `01`–`05`) and is named verbatim. Where it says *"setting"*, the value is a row in `setting` (never hardcoded). Where it says *"locked decision"*, it is fixed in the business documents and must not be reopened. Open items are collected at the end (§13); build around them, do not guess past them.

---

## 0. Conventions specific to this part

- **Money.** All amounts `NUMERIC(18,4)` EGP. All arithmetic below is exact decimal, never float. Rounding, where a division can produce sub-4dp residue (VAT, percentage splits), is **round-half-up to 4 dp**, and the **residue is absorbed by the Dahab side**, never by a customer account — a customer is never short by a rounding cent, and the ledger still sums to zero because the residue is an explicit posting to a `dahab_*` account. Every worked example below states its rounding.
- **One transaction.** Every money movement is one balanced `ledger_transaction` + `ledger_posting` set, written by the **money service** as a single set (Part 1 §7; schema comment on `ledger_posting`). The deferred `trg_txn_balanced` and `trg_customer_nonneg` fire at commit. No handler writes postings ad hoc.
- **Named actor.** Every ledger transaction and audit row carries a `customer_id` or `staff_id` (`ledger_txn_has_actor`, `audit_has_actor`). Scheduled jobs run as a **system staff actor** (a dedicated `staff` row, role chosen per §12) so their effects are attributable.
- **State changes are guarded.** Order state changes pass `assert_order_transition` (schema `order_transition`); listing and other machines are guarded in the service against their transition tables (`listing_transition`, `buy_request_transition`, `withdrawal_transition`). An illegal move raises and aborts the transaction (surfaced as `illegal_*_transition`, Part 2 §12).
- **Settings are read live** inside the transaction, not cached across a request, so a mid-flight change of a rate or deadline never applies half-and-half within one operation.

---

## 1. The working-hours deadline resolver

Every deadline counted "in working hours at a branch" (`deadline.reach_branch_working_hours`, and the working-hours form of `deadline.seller_return_weeks`) is computed by **one shared resolver**. Endpoints and jobs call it; none re-implement it. This is the single most reused piece of logic in the system and the one most likely to drift if copied, so it lives in exactly one place.

### 1.1 Inputs and contract

```
resolve_working_deadline(start_instant, amount, unit, branch_id) -> deadline_instant
```

- `start_instant` — `TIMESTAMPTZ`, the moment the clock starts (e.g. `order.accepted_at`).
- `amount`, `unit` — from the setting (`working_hours`, or `weeks`/`days` expressed as working time where the business says so).
- `branch_id` — the branch whose calendar governs. **The calendar is the branch's, not the customer's** (locked decision: "counted at the branch she chose, so a Thursday afternoon acceptance is Sunday, not Friday").

### 1.2 Algorithm

Working time accrues only inside a branch's open intervals, skipping closures.

1. Load the branch's weekly template `branch_hours` (rows per `dow`, `opens_at`–`closes_at`; multiple rows per day allowed for a lunch split) and its `branch_closure` dates (rows with `branch_id = :branch_id` **or** `branch_id IS NULL` — the latter are all-branch national holidays).
2. Walk forward from `start_instant`. If the start falls outside an open interval, the clock does not begin until the next open interval starts. Accumulate open-interval minutes until the required `amount` of working time is consumed. The instant at which the last needed minute is consumed is the deadline.
3. A `branch_closure` date contributes **zero** open minutes regardless of the weekly template (a holiday on a normally-open day is closed).
4. All arithmetic is in the branch `timezone` (`branch.timezone`, default `Africa/Cairo`), then stored as `TIMESTAMPTZ`.

### 1.3 Working-weeks

`deadline.seller_return_weeks` and `deadline.collect_weeks` are expressed in **weeks** in the seed. The business intent differs by which:

- **`collect_weeks` (buyer, paid, storage limit):** this is a **calendar** storage window — "they have paid and it is theirs; this is a storage limit, not a penalty" (blueprint §4). Compute as calendar weeks from `pay-balance`, **not** working hours. The piece physically sits at the branch; closures do not extend the buyer's ownership clock.
- **`seller_return_weeks` (seller must collect a returned piece):** same nature — a storage window for a physical piece already the seller's again. Compute as **calendar** weeks from the return being opened. (If the business later wants this in working hours to be generous around holidays, it is a one-line switch to the resolver; flagged, not assumed — **OI-3.1**.)

> Only deadlines that gate an **action a person must take at a branch** (reaching the branch to deliver) are working-hours. Deadlines that merely bound **how long a physical piece rests** are calendar. This distinction is deliberate; do not unify them.

### 1.4 Extensions

An admin extension writes `order_deadline_extension` (`which ∈ reach_branch|balance|collect`) and the new instant must move forward (`extension_moves_forward` CHECK). The resolver is **not** re-run on an extension — the admin supplies the explicit new instant (Part 2 `extend-deadline`). A branch change (`order_branch_change`) keeps the clock running (locked decision) and only extends if the admin explicitly sets `extend_to`.

---

## 2. Pricing: the four inputs and the two sides

Every figure a customer sees is built from four inputs, none typed by hand (blueprint §3): the **gold rate** (Evolve, Part 4), the **per-gram price correction** (two settings, buy and sell side), **weight and karat** (seller-stated, IGI-confirmed before settlement), and the **making charge** (seller's, for gold). This section defines how those become the two prices — what the buyer pays and what the seller receives — and the spread between them.

### 2.1 Purity

`karat.purity_ratio` is the fraction of pure gold (`0.750` for 18K, `0.875` for 21K, `0.999` for 24K, etc.). Gold value always uses purity; a karat code never appears as a bare number in a price.

### 2.2 The two corrected rates (gold)

From the live gold rate `R` (EGP per gram of pure gold, from Evolve or a manual price):

- **Sell-side rate** (what the buyer pays on): `R_sell = R + price_correction.sell_side`
- **Buy-side rate** (what the seller receives on): `R_buy = R + price_correction.buy_side`

`price_correction.buy_side` is typically negative and `price_correction.sell_side` typically positive (schema seed: `-15` and `+15`), so `R_buy ≤ R ≤ R_sell`. Both are EGP-per-gram corrections, the same for everyone (blueprint §3, "the correction is the same for everyone").

### 2.3 The two prices (gold)

For weight `W` grams (stated at listing, **IGI-confirmed at settlement** — see §3.4) and purity `p`:

- **Buyer total (sell side):** `buyer_total = R_sell × W × p + making_charge_per_g × W`
- **Seller gross (buy side):** `seller_gross = R_buy × W × p + making_charge_per_g × W`
- **Spread (Dahab):** `spread = (R_sell − R_buy) × W × p = (sell_side − buy_side corrections) × W × p`

The spread is the **rate difference on the gold value only**. The making charge is identical on both sides (the seller set it; the buyer pays exactly it), so it cancels out of the spread and Dahab earns nothing on it via the spread — Dahab's earning on the making charge is the **commission** (§2.5), a different line.

> **The gold value is never touched by commission.** The seller receives the full `R_buy × W × p` gold value. Commission is taken from the making charge (and from stone value on diamonds), never from the metal (locked rule, everywhere). The spread is a rate-difference margin on the gold, not a deduction from the seller's gold value — the seller was always quoted `R_buy`, and receives exactly `R_buy × W × p`.

### 2.4 Diamond and gold-with-diamond: no spread

For `category ∈ {diamond, gold_with_diamond}` the seller sets **one fixed asking price** (`listing.asking_price`); the buyer pays it; there is **no buy/sell rate split and no spread** (locked decision #5; blueprint §5). Pricing:

- **Diamond (pure):** `buyer_total = seller's asking_price`. The whole asking price is "value above gold" (there is no gold). Commission is `commission.stone_pct` of it. `seller_gross = asking_price`; commission is taken from it per §2.5; no spread.
- **Gold-with-diamond:** `buyer_total = asking_price` (seller sets the whole-piece price). The **gold value** within it (`R × W × p`, at the **plain** rate — no buy/sell correction, since there is no spread on these) is protected: commission applies only to the **value above the gold** (`asking_price − gold_value`), per §2.5. No spread.

> Why no spread on stones: gold has a public price and a buyer at any hour; a diamond is worth what a specific buyer pays and may sit for months (blueprint §5). The spread mechanism assumes a liquid, rate-driven value; stones do not have one. Dahab's earning on stones is commission on the value above gold, only.

### 2.5 Commission and VAT

- **Gold:** `commission = max(commission.gold_pct × (making_charge_per_g × W), commission.minimum_egp)`.
- **Diamond / gold-with-diamond:** `commission = max(commission.stone_pct × value_above_gold, commission.minimum_egp)`, where `value_above_gold` is the whole asking price (pure diamond) or `asking_price − gold_value` (gold-with-diamond).
- **Minimum commission** (`commission.minimum_egp`, 200) is never broken, "not even by a promo code" (blueprint §3) — except a market-maker code waives commission entirely by design (§8); the minimum floors a *charged* commission, it does not force a charge onto a waived one.
- **VAT** = `vat.pct × commission`, applied **to commission only** (schema seed note; blueprint §3). VAT is included in the figure the customer sees and shown separately on the invoice. The spread is trading margin, **not** a service fee, and carries **no VAT**.

### 2.6 What the seller actually receives

```
seller_proceeds = seller_gross − commission − vat
```

For gold, `seller_gross = R_buy × W × p + making_charge × W`. For stones, `seller_gross = asking_price`. In both, commission + VAT come out of the seller's gross (the seller recovers the making charge / sets the stone price; Dahab's cut is from that value the seller recovers, never from the gold). The buyer pays `buyer_total`; the difference `buyer_total − seller_proceeds` is exactly `commission + vat + spread` — which is what Dahab keeps, split across `dahab_commission`, `vat_payable`, `dahab_spread`.

---

## 3. Settlement math, worked end to end

This is the exact computation the money service performs at **`pay-balance`** (Part 2 §7 — the settlement event). Every figure is recomputed on the **IGI-confirmed weight** (locked decision #5 / (أ)).

### 3.1 When it runs

At `POST /orders/{id}/pay-balance`, with the order in `awaiting_balance` and an inspection result whose `outcome` permits settlement: `pass`, or `weight_adjust`/`stone_regrade` that the buyer approved via `settlement_decision` (Part 2 §6). The deposit is already in the buyer's `cust_held` (placed at the buy request, §5).

### 3.2 The escrow pass-through (locked decision)

The full price transits `escrow` **inside the one transaction**: it enters from the buyer (balance from `cust_available`, deposit from `cust_held`) and is distributed out to the seller and the `dahab_*` accounts in the same balanced set. Net escrow movement is zero within the transaction. Escrow stays on the path (not bypassed) because it is the single clean source for a later refund in the paid-but-uncollected case and for any post-payment dispute reversal (Part 2 §7 note; §10.3 here).

### 3.3 Worked example — gold, clean pass

A 21K ring. Seller stated **10.000 g**; IGI confirms **10.000 g** (no difference). Gold rate `R = 6,000`. Corrections: `sell_side = +15`, `buy_side = −15`. Making charge `300`/g. Purity `p = 0.875`. Commission `20%`, VAT `14%`, minimum `200`.

```
R_sell = 6,000 + 15 = 6,015
R_buy  = 6,000 − 15 = 5,985
gold_value_sell = 6,015 × 10 × 0.875 = 52,631.2500
gold_value_buy  = 5,985 × 10 × 0.875 = 52,368.7500
making          = 300 × 10          =  3,000.0000

buyer_total  = 52,631.2500 + 3,000 = 55,631.2500
seller_gross = 52,368.7500 + 3,000 = 55,368.7500
spread       = 52,631.2500 − 52,368.7500 = 262.5000

commission = 20% × 3,000 = 600.0000  (≥ 200 min, ok)
vat        = 14% × 600   =  84.0000
seller_proceeds = 55,368.7500 − 600 − 84 = 54,684.7500
```

Deposit at 20% of buyer_total (locked at request; but recomputed total is identical here) `= 11,126.2500`. Balance `= 55,631.2500 − 11,126.2500 = 44,505.0000`.

**Ledger (one transaction, `event_kind` legs as noted):**

```
buyer  cust_available   −44,505.0000     (balance_payment)
buyer  cust_held        −11,126.2500     (deposit released from hold)
escrow                  +55,631.2500     (pass-through in)
escrow                  −55,631.2500     (pass-through out)
seller cust_available   +54,684.7500     (settlement_seller)
dahab_commission        +600.0000        (commission)
vat_payable             +84.0000         (vat)
dahab_spread            +262.5000        (spread)
------------------------------------------------
sum = 0   ✓  (55,631.2500 in, 55,631.2500 out)
```

Check: `seller_proceeds + commission + vat + spread = 54,684.75 + 600 + 84 + 262.50 = 55,631.25 = buyer_total` ✓. The seller receives the full **buy-side gold value** (52,368.75) plus the making charge (3,000) minus Dahab's cut on the making charge (600 + 84); the gold value is untouched by commission ✓.

### 3.4 Worked example — gold, weight within tolerance (IGI weight is the basis)

Same ring; IGI confirms **9.900 g** (1.0% under 10.000; within `inspection.weight_tolerance_pct` 1.5% → `outcome = pass`, auto-adjust). **All figures recompute on 9.900** (decision (أ)):

```
gold_value_sell = 6,015 × 9.9 × 0.875 = 52,104.9375
gold_value_buy  = 5,985 × 9.9 × 0.875 = 51,845.0625
making          = 300 × 9.9           =  2,970.0000
buyer_total  = 52,104.9375 + 2,970 = 55,074.9375
seller_gross = 51,845.0625 + 2,970 = 54,815.0625
spread       = 259.8750
commission   = 20% × 2,970 = 594.0000
vat          = 14% × 594   =  83.1600
seller_proceeds = 54,815.0625 − 594 − 83.16 = 54,137.9025
```

The deposit was locked at the **stated**-weight total (20% of 55,631.25 = 11,126.25). The buyer's **balance** trues up to the recomputed total: `balance = 55,074.9375 − 11,126.2500 = 43,948.6875`. The buyer pays less because the piece weighed less — the locked figure was the per-gram rate and formula, not a frozen total (locked decision). If the recomputed total were **below** the deposit already held (tiny pieces, large downward weight correction), the excess deposit is refunded to `cust_available` in the same transaction; the money service handles `balance ≤ 0` by refunding the difference rather than charging a negative balance.

### 3.5 Rounding rule, concretely

VAT of `594 × 0.14 = 83.16` is exact. Where a percentage yields >4 dp (e.g. a commission on an odd making charge), round half-up to 4 dp and post the **residue** (buyer_total − sum of the other legs) to `dahab_spread` (gold) or `dahab_commission` (stones, no spread account in play), so the transaction sums to exactly zero and no customer leg carries a rounding artefact. The residue posting is explicit and auditable.

### 3.6 Diamond / gold-with-diamond settlement

Same escrow pass-through, but **no spread leg** and commission on stone value:

- `buyer_total = asking_price` (no buy/sell split).
- `commission = max(commission.stone_pct × value_above_gold, commission.minimum_egp)`.
- `vat = vat.pct × commission`.
- `seller_proceeds = asking_price − commission − vat`.
- Ledger legs: `escrow` in/out, `seller cust_available +seller_proceeds`, `dahab_commission +commission`, `vat_payable +vat`. **No `dahab_spread` posting.**

For gold-with-diamond, the certificate-cost handling (IGI certificate free; another lab's reduced; none full — blueprint §5) is a deduction from `seller_proceeds` taken at settlement, sourced to the party that bears it per the IGI contract; the exact ledger treatment depends on the IGI commercial terms and is specified in **Part 4** (integration), flagged here as the hook — **OI-3.2**.

---

## 4. The queue: concurrency model in depth

The buyer queue (Part 2 §4) is the most concurrency-sensitive area in the system. Multiple buyers race to join one listing's queue; the seller races to accept the head while a buyer races to withdraw. The model must guarantee: **FIFO integrity, no gap/reuse in positions, exactly one deposit hold per active request, and no double-accept.**

### 4.1 The queue lock

Every mutation of a listing's queue (join, withdraw, accept, release) takes a **per-listing lock** first. The dedicated `listing_queue_seq` counter table exists precisely so position assignment is race-free: the join takes the listing's `listing_queue_seq` row `FOR UPDATE`, reads `next_pos`, increments it, and inserts the `buy_request` with that `queue_position`. Two concurrent joins serialize on that row lock; neither can get the same position (`UNIQUE (listing_id, queue_position)` is the backstop).

### 4.2 One active request per buyer per listing

`one_active_request_per_buyer_listing` (partial unique index on `state IN ('queued','accepted')`) makes a second active request from the same buyer on the same listing impossible at the engine; the service surfaces the conflict as `already_in_queue` (409). A buyer who withdrew (`withdrawn_by_buyer`) or was released is not "active" and may rejoin — at the back, taking a fresh `next_pos` (locked decision: rejoin at the back).

### 4.3 Price lock at join

At join, the service computes the buyer's `locked_unit_rate` (the live gold rate at that instant) and `locked_total_price` (the **sell-side** total on the stated weight — §2.3), and the `deposit_amount = deposit.buyer_pct × locked_total_price`. It re-checks the buyer's `confirm_locked_price` against the freshly computed figure and rejects on drift beyond a small tolerance (`price_moved`, 409) so a buyer never locks a stale number. The deposit hold is one `deposit_hold` transaction: buyer `cust_available −deposit`, `cust_held +deposit`. `locked_total_price` is the **per-gram rate and formula frozen**, not the final payable — the final trues up to IGI weight at settlement (§3.4).

### 4.4 Accept: take the head, release the rest, atomically

`POST /listings/{id}/accept` (Part 2 §5), one transaction under the queue lock:

1. Confirm the named `buy_request_id` is the current head (lowest `queue_position` among `queued`); else `not_queue_head` (409). The model has no "pick whoever" (locked decision: seller accepts the first).
2. Create the `order` (`awaiting_delivery`), copy `locked_total_price`, set the chosen `branch_id` (must be in `listing_branch_option` — `trg_order_branch_subset`; `branch_not_in_options` 409), `accepted_at = now()`.
3. `reach_branch_deadline = resolve_working_deadline(accepted_at, deadline.reach_branch_working_hours, 'working_hours', branch_id)` (§1).
4. Head request `queued → accepted`.
5. **Every other active request `queued → released_not_chosen`**, each with its own `deposit_release` refund (`cust_held −d`, `cust_available +d`). All refunds + the acceptance commit together — a buyer is never left both un-chosen and un-refunded.
6. Listing `reserved → accepted` (`trg_sync_queue` and the listing guard keep it consistent).

### 4.5 Withdraw and the seller-reply deadline

- **Buyer withdraws** (`queued → withdrawn_by_buyer`): refund the deposit; if the queue empties, `trg_sync_queue` flips the listing `reserved → live`. If the buyer set `notify_when_free`, they are enrolled for the notify-when-free job (§12) — fired only when the listing returns to `live` with **zero** active requests (locked decision).
- **Seller-reply deadline** (`deadline.seller_reply_hours`, clock hours — **not** working hours; it is a responsiveness limit, not a branch action): the sweep (§12) moves any `queued` request past its `seller_reply_deadline` to `released_expired` and refunds it. This is **per request**, preserving FIFO for the rest.

---

## 5. Acceptance to delivery to inspection

### 5.1 Reach-branch deadline

From acceptance, the seller has `deadline.reach_branch_working_hours` working hours at the chosen branch to deliver (§1). Missing it is handled by the reach-branch sweep (§12): order `awaiting_delivery → cancelled_seller`, buyer refunded in full, and the miss **counts toward seller suspension** (§9) exactly as an explicit cancel does — "I sold it elsewhere is a cancellation, not a request for more time" (blueprint §4).

### 5.2 Branch change

Only an admin changes the branch on an open order (Part 2 §5; `order_branch_change`). The clock **keeps running** (locked decision); the admin may extend only if the new branch is closed for part of the window, via an explicit `order_deadline_extension`. The new branch must still be one of the listing's named options (`trg_order_branch_subset` re-checks).

### 5.3 IGI receive

`POST /igi/orders/{id}/receive` (Part 2 §6): order `awaiting_delivery → at_inspection`, listing `accepted → at_inspection`, branch-scoped to the IGI account (`wrong_branch` 403 otherwise). This is the moment the piece is physically in Dahab's custody at IGI — and the trigger point for the **first-sale advance** (§6).

---

## 6. The first-sale advance (`first_sale_payout`)

The single exception to "never pay the seller before the buyer pays." A first-time gold seller is paid **early** — at the moment Dahab receives the piece (IGI receive), before the buyer pays the balance — capped, gated, and reconciled. It is a **trust incentive**, not a purchase: Dahab remains an intermediary and recovers the advance from the buyer's later payment (schema `first_sale_payout` comment; blueprint §2).

### 6.1 Eligibility (all must hold)

- **Gold category only** (`category = 'gold'`). Never diamond / gold-with-diamond (blueprint §5: the exception "would not be cheap" on stones).
- **The seller's first gold sale.** Determined by the absence of any prior `completed` gold order as this seller, plus no prior `first_sale_payout` for them. (A robust check: no prior `first_sale_payout` ledger transaction with this seller, and no prior completed gold order.)
- **Gated on:** an active `promo_code` of `kind = 'first_sale'` applied, **or** an explicit admin enablement (Part 2 promo/admin path). Not automatic for every new seller — it is switched on deliberately (locked decision #2).
- **Capped** at `payout.first_sale_cap_egp` (100,000, adjustable). If the seller's proceeds exceed the cap, the advance is **capped at the setting** and the remainder is paid normally at buyer payment; above the cap "even a first sale waits for the buyer" (blueprint §2).

### 6.2 What is advanced

The advance is against the seller's **eventual proceeds**, computed provisionally on the **IGI-confirmed weight at receipt/inspection** (so it uses real weight, not stated). Provisional because the buyer has not paid yet and no `settlement_decision` has run for an above-tolerance case:

- If inspection `outcome = pass`: `advance = min(seller_proceeds_provisional, payout.first_sale_cap_egp)`.
- If inspection needs buyer approval (`weight_adjust`/`stone_regrade`): **do not advance yet** — wait for the approved price, then advance at `awaiting_balance`. (An advance on a price the buyer might decline would create exactly the credit risk the rule avoids.)

### 6.3 Ledger at advance (IGI receive / inspection pass, gold, eligible)

Dahab fronts the money from its own funds — the buyer has paid nothing yet, so the source is Dahab, not escrow:

```
external_equity      −advance      (Dahab fronts from its own capital)
seller cust_available +advance     (first_sale_payout)
event_kind = first_sale_payout, actor = the enabling admin (or system actor if promo-gated)
```

The seller can withdraw this immediately (subject to the normal withdrawal review + email second-check + payout-account rules, §7 Part 2 / §11 here). This is the trust the incentive buys.

### 6.4 Reconciliation at buyer payment

When the buyer later pays (`pay-balance`, §3), the seller has **already received `advance`**. The settlement must not pay the seller twice and must return Dahab's front. In the one settlement transaction, the seller's settlement leg is **reduced by the advance**, and escrow repays Dahab's front:

```
buyer  cust_available   −balance
buyer  cust_held        −deposit
escrow                  +buyer_total
escrow                  −buyer_total
seller cust_available   +(seller_proceeds − advance)   (settlement_seller, net of advance)
external_equity         +advance                        (recover Dahab's front)
dahab_commission        +commission
vat_payable             +vat
dahab_spread            +spread            (gold)
------------------------------------------------
sum = 0 ✓
```

If `seller_proceeds` finally computed (on IGI weight) is **less** than the advance (e.g. a downward weight correction after the advance), the shortfall is Dahab's cost of the incentive on that sale — post the difference `external_equity` does not fully recover; the residual stays a marketing cost (blueprint: "a marketing cost with a known ceiling"). The service records this explicitly; it never claws back from the seller's wallet (no negative customer balance — `trg_customer_nonneg`).

### 6.5 If the buyer never pays after an advance

The buyer no-pay sweep (§10.1) still returns the piece to the seller and pays 50% of the forfeited deposit as compensation — **but the seller already holds the advance.** Dahab's exposure here is the advance minus what it recovers from the forfeited-deposit split. This is the bounded, known cost the cap exists to limit ("Dahab's exposure at any moment is the number of first-time sellers × the cap" — blueprint §2). The seller keeps the piece **and** the advance; Dahab does **not** claw back (the incentive was to build trust, and clawing back destroys it). Reconciliation: the advance is written off against `external_equity` as the incentive's realised cost, offset by the seller's share of the forfeited deposit if the business directs it there — **OI-3.3** (whether the 50% seller-comp in a first-sale-advance case instead offsets Dahab's write-off needs a commercial decision; default: seller still gets the 50%, Dahab absorbs the advance).

---

## 7. Inspection outcomes and the karat rule

The settlement service sets `inspection_result.outcome` from the measured vs stated figures (Part 2 §6); this section is the decision logic and its consequences.

### 7.1 Outcome decision

```
if measured_karat IS DISTINCT FROM stated_karat:      outcome = karat_cancel   (zero tolerance)
elif piece is counterfeit / not as described:         outcome = fake_cancel
elif category has a stone and grade < claimed:        outcome = stone_regrade
elif |weight_diff_pct| ≤ inspection.weight_tolerance_pct:  outcome = pass        (auto-adjust to measured)
else:                                                 outcome = weight_adjust  (buyer must approve)
```

- **Karat is structural, not a setting.** Any difference at all → `karat_cancel`. The `karat_mismatch` boolean and the `karat_mismatch_forces_cancel` CHECK make a mismatch carry only this outcome; the `karat_rule` CHECK ties the boolean to the actual mismatch. No role can override (admin-roles §9). The seller stated a karat stamped on the metal; a mismatch is treated as the piece not being what it claims (blueprint §4).
- **Weight is different by nature:** a shop scale and a lab scale honestly differ, so ≤ tolerance auto-adjusts and the sale continues on the measured weight (§3.4). Above tolerance needs the buyer's approval of the recomputed price.

### 7.2 Consequences

- `pass` → order `at_inspection → inspection_passed → awaiting_balance`; set `balance_due_deadline = now() + deadline.buyer_pay_days` (calendar days). If first-sale-eligible, the advance fires now (§6).
- `karat_cancel` / `fake_cancel` → order `at_inspection → cancelled_inspection`; **refund the buyer's deposit in full** (`deposit_release`); **suspend the seller** (§9). No penalty beyond suspension (locked decision: "suspension is the deterrent"). The piece returns to the seller physically (it is at IGI); this is a return handover, but with **no compensation** to the seller (the fault was the seller's) — distinct from the buyer-no-pay return (§10). Listing goes to a withdrawn/returned disposition; the seller collects the piece.
- `weight_adjust` / `stone_regrade` → order `at_inspection → weight_adjust_pending`; await `settlement_decision`. Buyer accepts → `awaiting_balance` on the recomputed price; buyer declines → `cancelled_inspection`, deposit refunded in full, **seller not suspended** (a price disagreement is not a fault). On decline, the piece returns to the seller with no compensation (no buyer breach occurred).

### 7.3 Corrections are new rows

A correction to a submitted result is a **new** `inspection_result` row with `supersedes_id` set; the original is never edited (`inspection_no_update` trigger; append-only). The settlement service reads the **latest non-superseded** result for an order. The actor on the correcting row must be a valid `igi_branch` account for the order's branch (Part 1 §3.4).

---

## 8. Market makers

A market-maker code (`promo_code`, `kind = 'market_maker'`, tied to exactly one customer via `mm_is_tied`) waives Dahab's commission and gives the spread to the dealer, in exchange for buying pieces that have not sold (blueprint §4).

- **Commission waived:** on an MM purchase, no `commission`/`vat` legs; the `commission.minimum_egp` floor does not force a charge (§2.5).
- **Spread to the dealer:** the `dahab_spread` leg is instead credited to the dealer — i.e. the dealer buys at the buy-side rate (no spread taken). The seller still receives the full buy-side proceeds; Dahab simply earns nothing on the trade (it is a marketing cost, not a hidden arrangement — blueprint §4).
- **Aged-listing gate:** a piece must have been listed ≥ `marketmaker.min_list_age_days` (7) before an MM code can buy it, "so a dealer never gets ahead of an ordinary buyer" (blueprint §4). Checked in the service at the buy request; recorded on `market_maker_approval` with the numbers the approving admin saw.
- **One account, logged approval:** the code fails from any other account/device; each approved piece carries an admin approval logged with price (`market_maker_approval`). Monthly cap per code (`promo_code.monthly_cap_egp`); switchable off instantly (`is_active`).
- **Scaling caveat:** the controls assume dealers known personally; whether it scales is an open question (open-questions §5) — not a build blocker, but the endpoint assumes no trust beyond the tied customer.

---

## 9. Suspension rules

Suspension is always a named action with a reason from a fixed list (`customer.suspended_needs_actor`; admin-roles §9). Two triggers:

### 9.1 Automatic-flagged, human-confirmed

- **Seller cancellations:** each `seller_cancellation` (explicit seller-cancel, or a reach-branch miss — §5.1) counts; when the count reaches `suspension.cancellations_threshold` (2), the seller's ability to list is suspended. The **count** is enforced by the backend; the suspension writes `is_suspended`, `suspended_by` (the system actor or the confirming admin), `suspended_reason`.
- **Karat mismatch / counterfeit:** immediate suspension on `karat_cancel`/`fake_cancel` (§7.2). This is not a threshold — one is enough (the karat is stamped; a mismatch is treated as fraud).

### 9.2 What suspension does

A suspended customer can still sign in and read, withdraw a remaining balance, and wind down open orders (Part 1 §2.2); every **trade** action returns `403 account_suspended`. Locked-price orders already in flight are honoured — suspension stops new trading, it does not confiscate a promise already made. Reinstatement is founders-only (Part 1 §4.3).

### 9.3 Pattern flags (not suspension)

Crossing `flag.pattern_txn_threshold` (5) raises a **review flag**, not an automatic suspension — a human decides. Who receives the flag and by which channel is unresolved (**OI-3.4**, = Part 1 OI-1.3 / Part 2 OI-2.2).

---

## 10. Post-payment afterlife: return-to-seller and uncollected-paid

The order is a **closed accounting record** once terminal (`completed`, `cancelled_buyer_nopay`, `cancelled_inspection`). The **physical afterlife of the piece rides entirely on `listing_state`** (locked model): `awaiting_seller_return`, `seller_unclaimed`, `uncollected_expired`. The order never re-opens. This section specifies the two afterlife flows.

### 10.1 Buyer never pays → piece returns to the seller (decision #3)

Balance-payment sweep (§12), one transaction:

1. Order `awaiting_balance → cancelled_buyer_nopay` (terminal).
2. **Deposit forfeiture** (`event_kind = deposit_forfeit`): the buyer's held deposit is split — `deposit.seller_forfeit_share_pct` (50%) to the seller's `cust_available`, the remainder to `dahab_*` (Dahab's cost on a sale that produced nothing). Drafted as **agreed compensation, not a penalty** (open-questions §1, legal clinic).
   ```
   buyer  cust_held      −deposit
   seller cust_available +(deposit × 50%)
   dahab_commission (or a dedicated dahab account) +(deposit × 50%)
   ```
3. **Piece returns to the seller:** listing → `awaiting_seller_return`; open a `seller_return` row (`return_deadline = now() + deadline.seller_return_weeks`, calendar §1.3; `compensation_txn_id` = the forfeiture transaction; `code_hash` = a seller collection code, hashed). The seller collects the physical piece at the branch, or relists.
4. Because the buyer never paid, **settlement never fired** — there is no seller settlement to reverse. (Exception: if a first-sale advance was paid, §6.5 governs.)

- **Seller collects the returned piece:** listing `awaiting_seller_return → withdrawn`; set `seller_return.collected_at`, `handover_by` (IGI). Or **relist:** `awaiting_seller_return → live`.
- **Seller never comes** (past `return_deadline`): the seller-return sweep moves listing `awaiting_seller_return → seller_unclaimed`; status shown ("window passed, not our liability"). Disposition (hand over / compensate) is a **manual decision** when the seller makes contact (decision #3). From `seller_unclaimed`: `→ withdrawn` (collected / handed over) or `→ live` (relisted after contact).

### 10.2 Buyer pays but never collects (decision #4)

Collection-deadline sweep (§12): the buyer paid in full at `pay-balance`, so the seller is **already settled** and the piece is the buyer's, paid for, sitting at IGI. Past `collect_deadline`:

1. Listing `sold → uncollected_expired`. The **order stays** in its paid state (settlement already recorded; the order does not move — the afterlife is on the listing).
2. Fire a **buyer notification** ("window passed, Dahab is not liable").
3. **Disposition is a manual, per-case operator decision** when the buyer makes contact (decision #4):
   - **Hand over:** IGI hands the piece over against the code; listing `uncollected_expired → sold` (collected). No money moves (settlement already happened).
   - **Refund:** refund the buyer **from `escrow`** — this is why the escrow pass-through is preserved (§3.2). A refund after settlement is a reversal-style transaction returning the buyer's payment from the appropriate source; the piece disposition (back to seller / to Dahab) follows the operator's decision and the legal framing.

> The commercial answer per case (hand over vs refund, and on what terms) is an **operator** decision, not automated. The legal framing of "not our liability" after the window still needs the clinic (open-questions §1) — **OI-3.5**. The system provides: the state, the notification, and the two manual actions.

### 10.3 Refunds and the escrow source

Any post-payment refund (uncollected-paid, or a dispute resolved for the buyer after payment) draws from `escrow` as the clean origin. Because settlement was an instantaneous pass-through, a refund is a deliberate reversal the money service constructs (not an automatic un-doing) — it posts from `escrow`/`dahab_*`/seller as the resolution directs, always balanced, always with a named actor and reason. Disputes freeze the order (`disputed`) so no deadline runs and no money moves until resolved (Part 2; schema `dispute`).

---

## 11. Withdrawals

Full wire contract in Part 2 §8–§9; the logic:

- **Request** moves the amount from `cust_available` into a pending hold and creates `withdrawal(requested)`. **No money leaves the bank** until a person releases it. Two gates beyond the session: the **email second-check** (Part 1 §2.4 — `email_confirmation_required` 403 without it) and the **account-change pause** (`withdrawals_paused` 409 if an active `withdrawal_pause` covers now).
- **Payout-account change** opens a `withdrawal_pause` (`pause_until = now() + withdrawal.account_change_pause_hours`, read from setting) and cancels any in-flight withdrawal; the new account is `pending_review` until Finance/Verification checks the name against the ID. No role can skip the pause (admin-roles §9).
- **Release** is person-reviewed (`under_review → released`): customer hold `−amount`, `bank −amount` (money leaves). Re-check the pause at release. **Release authority: CEO or Finance** (resolved — both may release; the COO is excluded as a wallet action). Every release is a named, audited action.
- **Money out only to an account in the customer's own name** (locked rule; the name check is the guard). Money in only from an account in their own name (top-up matching, Part 2 §9).

---

## 12. Scheduled jobs

Each runs as a **system staff actor** (attributable in the audit log), obeys the one-transaction rule, and produces the same audited, ledgered effects as an endpoint. All thresholds/deadlines are settings.

| Job | Scans for | Effect |
|---|---|---|
| Seller-reply sweep | `buy_request` `queued` past `seller_reply_deadline` | → `released_expired`, refund deposit (per request, FIFO preserved) |
| Reach-branch sweep | `order` `awaiting_delivery` past `reach_branch_deadline` | → `cancelled_seller`, refund buyer, count toward suspension (§9) |
| Balance-payment sweep | `order` `awaiting_balance` past `balance_due_deadline` | → `cancelled_buyer_nopay`; deposit forfeiture 50/50; piece → `awaiting_seller_return` + `seller_return` row (§10.1) |
| Seller-return sweep | `seller_return` past `return_deadline`, `collected_at IS NULL` | listing `awaiting_seller_return → seller_unclaimed`; notify seller (§10.1) |
| Collection sweep | `order` paid, past `collect_deadline` | listing `sold → uncollected_expired`; notify buyer; await manual disposition (§10.2) |
| Withdrawal-pause expiry | `withdrawal` `on_hold_account_change`, `pause_until` elapsed | → `under_review` |
| Notify-when-free | listing back to `live` with **zero** active requests | notify buyers who left with `notify_when_free = true` (rejoin at back) |
| Pattern/cap flag | txn counts crossing `flag.pattern_txn_threshold` | raise review flag (routing = OI-3.4) |

Idempotency: each sweep guards on the current state inside the transaction (an order already `cancelled_buyer_nopay` is skipped), so a double-run is safe.

---

## 13. Open items in this part — decide, do not guess

- **OI-3.1 — Seller-return / collect window: calendar vs working.** Both are computed as **calendar** weeks (§1.3), matching "storage limit" intent. Confirm; if the business wants working-hours generosity around holidays, it is a resolver switch.
- **OI-3.2 — Diamond certificate-cost ledger treatment.** IGI-free / other-lab-reduced / none-full (blueprint §5) deducted at settlement; exact sourcing depends on the IGI contract — specified in Part 4.
- **OI-3.3 — First-sale advance vs forfeited-deposit comp.** When a first-sale advance was paid and the buyer then never pays, does the seller still receive the 50% deposit compensation, or does it offset Dahab's advance write-off? Default: seller still gets 50%, Dahab absorbs the advance (the incentive's realised cost).
- **OI-3.4 — Cap/pattern alert routing.** Who is alerted, by which channel (= Part 1 OI-1.3, Part 2 OI-2.2). Unresolved.
- **OI-3.5 — Uncollected-paid legal framing.** The system behaviour is defined (state + notify + manual hand-over/refund, decision #4); the "not our liability after the window" framing needs the legal clinic (open-questions §1, custody/liability).
- **OI-3.6 — Withdrawal release authority. RESOLVED.** **CEO or Finance** may release (not CEO-only); COO excluded as a wallet action. Matrix, Part 1 §4.2, and Part 2 §9 updated.

*Carried, unchanged, from earlier parts and still blocking their specific spots: OI-1.4 (commission/spread rate-change authority — Part 2 §10 resolves to founders + Finance; confirm against the roles matrix). The karat rule, the append-only ledger/audit/inspection, the no-pay-before-buyer rule (with the single first-sale exception), and commission-never-from-gold are **locked** and enforced structurally — not open.*

---

*End of Part 3. Part 4 (integrations) will specify Evolve (gold rate feed, health, manual-price fallback), Rapaport (weekly matrix upload, diamond suggestions), IGI (inspection contract, certificate costs — OI-3.2, insurance/custody), and the Egyptian Tax Authority (automatic e-invoicing at `pay-balance`). Before Part 4: confirm OI-3.6 (withdrawal release authority) and OI-3.3 (first-sale vs deposit-comp), the two that change ledger/authorization behaviour.*
