# Research: Finance operations and order export (spec 015)

Engineering decisions behind [plan.md](./plan.md). Product decisions are the spec's Clarifications (2026-10-04).

## R1 — Paths

**Decision**: `/api/v1/dashboard/*` for staff, `/api/v1/customer/me/*` for the customer, `/api/v1/reference/*` for the public price reads — never `/admin/*`.
**Rationale**: Every module since spec 002 maps Part 2's `/admin/*` to `/dashboard/*`; the Dashboard and Flutter clients only know these prefixes.
**Alternatives**: `/admin/compensation` etc. as written in Part 2 — rejected, inconsistent with 14 specs of built paths (recorded deviation, as in specs 009–014).

## R2 — Compensation outside a dispute

**Decision**: One migration makes `compensation.dispute_id`, `order_id` and `party` nullable, with CHECKs `dispute_id IS NULL OR order_id IS NOT NULL` and `(order_id IS NULL) = (party IS NULL)`. `compensation_recorded()` keeps the party check only when an order is named and matches the ledger entry with `t.order_id IS NOT DISTINCT FROM NEW.order_id`. `PayCompensationAction` is generalised: `handle(Staff, Customer, amount, reason, note, ?Order, ?Dispute, ctx)`; the dispute path passes both, the direct path passes the optional order (its party derived: buyer or seller of that order, else a validation error on `order_id`, 422). A direct payment opens its own transaction (the dispute path keeps running inside the resolve's). The caps, the per-payer advisory lock (`comp:<staff_id>`) and `compensation.uncapped` are unchanged, so a direct payment and a dispute payment share one day limit.
**Customer standing**: as for crediting by hand (spec 009) — verified (active or suspended from verified) allowed, the audit row records the status; any other status `verification_required` (403).
**Alternatives**: a separate `goodwill_payment` table — rejected: two places for one ledger kind and two cap sums.

## R3 — Wallet adjustment entry

**Decision**: Kind `reversal` with `reverses_txn_id = NULL` (allowed by the schema: `one_reversal_per_txn` is partial), lines `cust_available ±X` / `external_equity ∓X`, the staff actor, the reason as memo. A new append-only `wallet_adjustment` row (direction, amount, reason, customer status, ledger id) makes adjustments listable and is tied to its entry by a deferred check (DH011). The money service's lock and non-negative check refuse a debit beyond available (`insufficient_funds`, the existing 409 with `details.available`).
**Rationale**: Part 2 §9 names "`compensation`/`reversal`-kind"; `compensation` is already "goodwill / dispute compensation" and has its own row and caps, so `reversal` (correction) is the honest kind. `external_equity` is the only account the docs use for Dahab-originated wallet money.
**Customer label**: the history already labels `reversal` *Correction* (Backend `LedgerEventKind::label`, Flutter `wallet_labels.dart`); the Customer App label stays, with Arabic checked.
**Alternatives**: a new ledger kind `wallet_adjustment` — rejected: changes the enum and every exhaustive map in both apps for no gain.

## R4 — Permissions

**Decision**: three new catalogue codes, seeded additively by `DashboardRolesAndPermissionsSeeder`:

| Code | Seeded to | Part 1 §4.2 row |
|---|---|---|
| `wallet.adjust` | no role (founders hold every code; the CEO is the founder seed) | *Adjust a wallet balance directly* — CEO only |
| `bank.record` | finance (+ CEO) | *Record a bank movement outside the app* |
| `day.close` | finance (+ CEO) | *Close the day* |

Reads: the compensation list `compensation.pay|wallet.view`; adjustments list `wallet.adjust|wallet.view`; bank book and recorded movements `bank.record|wallet.view`; the close view `day.close|wallet.view`. Never the COO by seed.
**Alternatives**: reuse `wallet.view` for writes — rejected (Principle II: one code per action).

## R5 — Staff proof upload

**Decision**: `POST /dashboard/uploads` (`bank.record`, purpose `bank_movement_proof`, PDF/JPG/PNG ≤ 10 MB, throttled) stores the file encrypted whole via `IdentityDocumentStorage::storeAt('bank-proofs', <staff_id>, …)` and returns a single-use token from `UploadTokenStore` keyed by the staff principal (`staff:<id>`); `POST /dashboard/bank-movements` takes `proof_upload_token`. `GET /dashboard/bank-movements/{id}/proof` streams it, audited `bank.movement_proof_viewed`.
**Rationale**: Keeps the movement POST JSON so the idempotency hash covers it (multipart files hash as `{}`), and mirrors the customer upload → token → submit pattern.
**Alternatives**: multipart on the movement POST — rejected (idempotency hash blind to the file); a text `proof_ref` only — rejected by the clarification.

## R6 — Bank movement kinds and signs

**Decision**: `kind` TEXT CHECK in `capital_in | operating_expense | bank_charge | profit_draw | own_transfer | supplier_refund | other` (the design's seven; Part 2's `rent` is an operating expense). `amount` is signed (+ in, − out), non-zero; the request carries `direction` + a positive amount. Entry for in: `bank −X`, `external_equity +X`; out: `bank +X`, `external_equity −X` (the bank sign rule, research 008 R15). `own_transfer` writes no entry (`ledger_txn_id` NULL; CHECK `(kind = 'own_transfer') = (ledger_txn_id IS NULL)`). `other` needs the reason (always required, 10–500). `occurred_on` ≤ today (Cairo); any past date, locked or not, is accepted and the entry posts now.
**Direction rules**: none enforced per kind (a bank charge refund is real) — the UI pre-selects the usual direction.

## R7 — Bank book query

**Decision**: `GET /dashboard/bank-book?from&to&cursor` = every `ledger_transaction` with a posting on the `bank` account in the Cairo period, oldest first, keyset on `(created_at, ledger_txn_id)` like the Wallet statement (`StatementCursor`), with opening cash (−SUM(bank) before `from`), in, out, closing; each row: time, kind, direction, amount, cash after, actor (staff name or *System*/customer), and the source: top-up (reference `DAHAB-…`, customer ref, *Matched notice* or *Credited by hand*, receiving account), withdrawal (`WD-n`, customer ref, bank transaction number), bank movement (kind, reason, statement date, proof flag), reversal (what it reverses). `GET /dashboard/bank-movements` lists the hand-recorded rows (own transfers included) — the design's table. Both export CSV (cap from config, BOM, formula-neutralised cells, audited `bank.book_exported` / `bank.movements_exported`).
**Matching status**: the bank book's per-row source column above; there is no bank-statement import, so "matched against the statement" is the daily close, not a per-row flag.

## R8 — Daily close snapshot and cut-off

**Decision**: The books for day D are the ledger at `cut = (D + 1) 00:00 Africa/Cairo`: bank cash = −SUM(bank postings with `created_at < cut`), customer available / held, Dahab wallet (`dahab_commission` + `dahab_spread`), escrow, VAT payable, and that day's hand-recorded movements (by `occurred_on = D` and by posting time). A day can be closed only when `now ≥ cut` (`day_not_ended` 422). The close transaction takes `LOCK TABLE ledger_transaction IN SHARE MODE` (waits for in-flight writers, blocks new ones for the few ms of the read) and `pg_advisory_xact_lock(hashtext('close:'||D))`, so a transaction stamped before midnight that commits after the close started is either in the snapshot or impossible. Locked rows are frozen by the trigger, so later entries never change them.
**Rationale**: `created_at` is the transaction start time (`now()`); without the share lock a late commit stamped 23:59:59 could appear after the snapshot (the "close vs a late entry" test).
**Alternatives**: a grace period after midnight — rejected (still a race, just rarer).

## R9 — Daily close rows and locking

**Decision**: `daily_close` keyed by `close_date`, columns per the schema plus `books_bank`, `customer_available`, `customer_held`, `escrow`, `vat_payable`, `movements_in`, `movements_out`, `explanation`, `saved_by`, `saved_at`. `bank_balance` is the typed figure across all Dahab accounts; `difference = bank_balance − books_bank`. 0 → `is_locked`; non-zero → locked only with `explanation` (10–1000), else saved unlocked (`day.saved`) and re-closable. CHECKs: locked ⇒ closer and time; locked and difference ≠ 0 ⇒ explanation. Trigger `daily_close_no_reopen` (schema) blocks UPDATE/DELETE of a locked row; a close of a locked day is refused `day_already_closed` (409) before the trigger. Not under customer RLS (no customer data; staff-only reads by permission).

## R10 — Overview endpoint

**Decision**: `GET /dashboard/overview` (any active staff) returns only the sections the viewer may see, each omitted (not null) otherwise:
- `earnings` (`wallet.view`): this Cairo month's commission and spread credits (postings to `dahab_commission`, `dahab_spread`) and their sum.
- `orders` (`order.view`, the viewer's branch scope): open orders by state (all open, any age) and `completed` / cancelled (all `cancelled_*`) over the last 30 days: count, value (`locked_total_price`) and held now (SUM of the buyers' held postings on those orders' requests).
- `needs_decision` (oldest first, max 10 rows, each kind only with its acting code): listings waiting review (`listing.review`), withdrawals `requested|under_review` (`withdrawal.release`), open/passed-on disputes (`dispute.handle`), identity documents waiting (`identity.review`), top-up notices pending (`topup.match`), payout accounts to check (`payout_account.verify`), waiting requests for more time (`order.extend_deadline`); each with kind, label data, `waiting_since`, the acting permission code (the Dashboard shows the area it belongs to — e.g. *Withdrawals* — never a role name, since roles are dynamic data) and the target id.
- `this_month` (`order.view`): new sellers (customers whose first listing was submitted this month), pieces listed (listings that went live), sold (orders completed), sell-through (sold ÷ live during the month, 0 when none), average days from acceptance to the seller's payment (orders paid this month).
Wallets and the safety figure stay on `/dashboard/wallets/overview`; the gold price on `/dashboard/gold-prices/current`. *Paid out ahead of buyers* and the Rapaport row are dropped (not built).
**Performance**: one query per section, indexed; < 300 ms p95 with 10,000 orders.

## R11 — Order export

**Decision**: `GET /dashboard/orders/export` with `ListOrdersRequest`'s filters (no cursor/per_page), the same branch scope and query builder as `ListOrdersAction`, the withdrawals export technique (temp stream, BOM, cap `config('dahab-orders.export_cap')` = 10,000 with a truncation line), audited `order.list_exported` with filters and count. Columns: order ref, state, stage label, piece, karat, weight, branch, seller ref, buyer ref, locked total, deposit, held now, accepted at, current deadline, past deadline.

## R12 — Held per request/order

**Decision**: `held(buy_request_id) = SUM(p.amount)` of postings on the buyer's `cust_held` account whose transaction carries that `buy_request_id` (every deposit hold, release, forfeit and balance payment names the request — `DepositLedger`, `ForfeitDepositAction`, `OrderSettlement`). Batch-loaded per page (one grouped query) under the `ledger` scope for the customer's own requests. `BuyRequestResource` and `CustomerOrderResource` gain `deposit_held` (the buyer's held amount; `null` when the viewer is the seller, who holds nothing on the order). New `GET /customer/me/wallet/held` (verified gate, like the wallet): `{ total, items: [{ type: buy_request|order, id, ref, title, state, amount }] }`, items with amount > 0, newest first; `total` equals `held_on_orders` (withdrawal holds carry `withdrawal_id`, never a request).

## R13 — Public prices and quote

**Decision**: `GET /reference/gold-prices` → `{ price_at, feed_state: live|manual|stale, karats: [{ code, label, sellers_get, buyers_pay }] }` for enabled karats from `PricingContext::karatPrices` — never raw bid/ask or adjustments. `GET /reference/quote?category=gold|gold_with_diamond|diamond&karat&weight_g&making_per_g&asking_price` → `PriceBreakdown` for the seller: `gold_value` (the protected gold value or `sellers_get × weight`), `making_back`, `asking_price`, `commission`, `vat`, `payout` (`sellerProceeds`), `commission_rate`, `minimum_applied`, `indicative: true`, `price_at`. No usable price → `price_unavailable` (409, the existing code). Throttle `public.market`; `Cache-Control: max-age=30`. Validation 422 (disabled karat, weight ≤ 0 or > 10,000 g, missing asking price).
**Customer App**: `LiveRates` becomes `ApiLiveRates` polling every 60 s (and on resume), keeping the flash on change; the calculator and the sell estimate call the quote (debounced 400 ms). The prototype's jeweller-comparison figure and the promo card have no Backend source and keep their MockMark.

## R14 — Notifications

**Decision**: one new `App\Notifications\WalletNotification` (SMS + email, EN/AR, after commit) with two events — `compensation_paid` for a direct compensation (spec 014's `OrderNotification` needs an order; a dispute payment keeps using it) and `wallet_adjusted` for a wallet adjustment (`wallet_adjusted`: direction, amount, the reason is **not** sent — staff text stays internal; a generic line "Dahab corrected your wallet"). EN/AR in `lang/`. No founder alert (OI-1.3 open).

## R15 — Concurrency and reconciliation tests

**Decision** (spec 012–014 technique: committed fixtures on two connections, then truncate with keep lists):
- two adjustments (debits) on one wallet at once — never negative;
- an adjustment debit vs a withdrawal submission;
- an adjustment debit vs a buy request deposit hold;
- two direct compensations by one Finance payer against the day cap; a direct and a dispute compensation together;
- two closes of the same day; a close vs a ledger entry committed across midnight (the share lock);
- reconciliation: after every outcome `ledger_global_zero = 0`, bank cash = −SUM(bank), every compensation/adjustment/movement row matches its entry, held per request sums to `held_on_orders`.
No new transition tables, so the six keep lists gain nothing; the new append-only tables truncate (row triggers do not fire on TRUNCATE), verified in the tests.

## R16 — Migration reversibility

**Decision**: `down()` refuses once any `wallet_adjustment`, `bank_movement`, `daily_close` or compensation without a dispute exists; otherwise drops the new tables, restores the NOT NULLs and the original `compensation_recorded()`.
