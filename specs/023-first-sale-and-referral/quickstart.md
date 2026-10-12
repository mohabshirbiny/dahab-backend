# Quickstart: spec 023 — how to verify by hand, story by story

## 0. Databases (owner first)

The owner creates `dahab_wt023` (test suite) and `dahab_wt023_dev` (dev data); the `dahab` user has no CREATEDB. Check with `psql -l`. If either is missing, stop and ask. **Never the primary `dahab`.** Pass the database on every command:

```bash
DB_DATABASE=dahab_wt023 DB_USERNAME=dahab DB_PASSWORD=… php artisan migrate:fresh --seed     # suite schema
DB_DATABASE=dahab_wt023_dev DB_USERNAME=dahab DB_PASSWORD=… php artisan migrate:fresh --seed # dev data
```

A fresh database has **all three switches off** (`payout.first_sale_enabled`, `promo.seller_codes_enabled`, `referral.enabled`). Steps below say when to turn one on (Dashboard → *Commission rates*, or `PATCH /dashboard/settings` with a reason) — **in the dev database only**.

## 1. Tests (targeted, sequential)

```bash
DB_DATABASE=dahab_wt023 … ./vendor/bin/pest tests/Feature/Migrations/Spec023MigrationTest.php tests/Unit/Pricing/CommissionWaiversTest.php
DB_DATABASE=dahab_wt023 … ./vendor/bin/pest tests/Feature/MarketMakers tests/Feature/Promo tests/Feature/Settlement/CommissionWaiverSettlementTest.php
DB_DATABASE=dahab_wt023 … ./vendor/bin/pest tests/Feature/FirstSale tests/Feature/Referral
```

One full run at the end only (T091, background).

## 2. Story 5 — Promo codes page (staff)

1. Sign in to the Dashboard as Finance. **Promo codes** shows the designed table. *Add a code*: the 11 fields with the designed helper texts (and the two extra Yes/No fields).
2. Create `MM-KARIM`: *One account only* → a market-maker account's number; *Waive it entirely*; *Give it to them*; 7 days; *Yes*; cap 500,000. Toast "Code created and logged against your name." The row reads *No commission · Spread goes to them · Karim S. +20 10 ••8842 · 7 days old · Admin approved · 0 of 500,000 · On*.
3. Create `WELCOME`: *Anyone*, *Reduce by a percentage* 50, *First sale only* Yes, use limit *Once* → *50% off commission · Everyone / First sale only · Once per ID · 0 uses*. Create `EID25` (25 %, ends in the future, no limit).
4. *Turn off* `EID25` → "Turned off and logged."; *Turn on* → "Turned on and logged." *Uses* opens the code's log.
5. Try a 2-digit code, a percent of 0 and of 100, an unknown account: each refused with a clear message. The old Postman body `{code, tied_customer_id, monthly_cap_egp}` still creates a dealer code.
6. Identity review of a new Egyptian ID asks for the 14-digit number; psql: `SELECT id_number_hash FROM identity_document …` holds a hex string, never the number.

## 3. Stories 3 and 2 — a code on the sell form, spent at settlement

Turn `promo.seller_codes_enabled` on. Seller with a verified account; gold piece, making charge 300/g, 10 g (commission 600 at 20 %).

1. App sell form: type `WELCOME` → **Apply**: "50% off the commission. The 200 EGP minimum commission still applies." *Promo saving* appears. Wrong code → "That code is not valid."; the same account types it again after using it → "You have already used this code." (after step 4).
2. `POST /customer/me/commission-preview` (Postman) with and without `code`: no row appears in `promo_code_use` or `commission_credit` (psql `SELECT count(*)`).
3. Finish the listing; `SELECT * FROM listing_promo` shows the code and credit; a second customer sends a buy request → `PUT …/promo` on the listing now answers `listing_promo_locked`.
4. Run the order to payment (accept, receive, inspect pass, pay balance). psql on the order: `commission_ordinary_amount 600`, `commission_amount 300`, `commission_given_up 300`, `commission_waiver_kind credit`, `commission_floor_applied t`; the credit is `exhausted` with one `spent` event; the ledger entry sums to zero (`SELECT sum(amount) FROM ledger_posting WHERE ledger_txn_id = …`); the seller invoice shows net 300 and VAT 42.
5. Repeat with a 99 % credit (a code created with *Reduce by* 99): the commission stays at the minimum (200). Repeat with a free-relist listing: commission 0, the credit is **not** spent.
6. Turn the switch off: a new redemption answers `promo_code_invalid` `not_open`; a credit already granted still applies.

## 4. Story 1 — the advance

Turn `payout.first_sale_enabled` on. Create `FIRSTGOLD` (*Anyone*, first sale only, *Also pays the first gold sale in advance* Yes). A seller with no earlier sale lists a gold piece with the code.

1. Sell form shows "Paid as soon as IGI confirms your piece, up to 100,000." (the setting). A diamond listing shows no such line.
2. Buyer requests, seller accepts, branch staff receive, inspector records a **pass**. Immediately: the seller's wallet shows a `first_sale_payout` movement; `first_sale_advance` has one `outstanding` row; the ledger entry is `external_equity −A`, `cust_available +A`; the seller got "Order … paid ahead" (SMS + inbox). `GET /dashboard/first-sale-advances` shows exposure = A.
3. Buyer pays the balance: the entry has `settlement_seller` for `proceeds − A` and `external_equity +A`; the advance is `recovered`; the invoice still reconciles; the order screen shows *Paid earlier* and the rest.
4. A second order from the same seller never advances (unique); an order that would pass the ceiling (set the ceiling low) is skipped: a `first_sale.skipped` audit row and "We could not pay … ahead this time."
5. **No-pay**: another seller, buyer never pays, run `php artisan orders:sweep` after the deadline: the advance becomes `written_off`, the seller keeps the advance and the piece, gets 50 % of the deposit; flip `payout.first_sale_comp_offsets_writeoff` on for a third case and the seller gets 0 of it.
6. Staff enablement: Customer file → *Enable the first-sale advance* with a reason on a seller who used no code; their next gold sale is advanced with the staff member as actor.
7. Price lock: on a piece that carried an advance (also after the write-off and a relist) *Change price* answers "This piece's price is fixed for now." (spec 022).
8. Fresh database check: with the switch **off**, repeat step 2 — nothing is posted.

## 5. Story 4 — referral

Turn `referral.enabled` on. Seller A opens **Invite a friend**: a code, *Invites sent 0 · Joined 0 · Sold something 0 · Earned so far 0 EGP*. **Share** raises *Invites sent*.

1. Friend B (no sale yet) lists a gold piece and types A's code in the card: 50 % off (the minimum applies), `referral` row `joined`; A's screen shows *Joined 1*.
2. A types their own code → "You cannot use your own invitation code."; B tries a second code → "You have already joined…"; a second account on B's device → "This device has already been used…".
3. B's sale settles (≥ 5,000 EGP): within seconds `referral.state = rewarded`, A has one `amount` credit of 200 EGP, an SMS/inbox "Your friend completed a sale…", A's screen shows *Sold something 1 · Earned so far 200 EGP*.
4. A's next sale (commission 600): commission 400 (credit spent 200, `reduction_egp 200`); if A also applies `WELCOME` (50 % ⇒ 300) the **lower, 300** applies and the credit stays; they are never added.
5. Kill the queue worker between B's payment and the reward: `php artisan referrals:reward-pending` grants it. Expire the credit date in psql and run `commission-credits:expire`: status `expired`.

## 6. Story 6 — Market maker approvals

1. A live gold piece older than 7 days (adjust `listed_at` in psql on the dev DB). Open it from the market app on two devices on two days, and once signed in as the seller: the Dashboard queue row shows `views` = 3 (two devices, one of them twice on different days; the seller's own view is not counted).
2. **Market maker approvals**: the info note shows the live waiting period (7) and the approval requirement (on) with a *commission rates* link; the piece row shows *30.00 g · N views, no requests*, the file links (Video, Original invoice, N photos), the green *Making charge 21% of gold*; a diamond row shows *No guide yet*. Change `marketmaker.making_charge_warn_pct` to 10 → the gold row turns red.
3. **Leave** → "Left as it is."; the piece stays; audit has `market_maker.left`. **Price is sound** → "Approved and logged against your name."
4. A dealer buys the approved piece with `MM-KARIM` (spec 019 flow) and pays. *What this costs* (This month) now shows 1 piece, the commission the sale would have paid (600), the positive spread given, and **Total cost** = their sum; psql shows the same numbers on the order. A market-maker sale made before this spec shows in "N earlier sales are not recorded".
5. Open the *Video* link: the file plays, and the audit log has a view row by the staff member.

## 7. Rollback check (T089)

On `dahab_wt023_dev` with data: `php artisan migrate:rollback --step=1` refuses for M2/M3 while credits/advances exist (message names the table), and rolls back M4 and an empty M1 cleanly. On a database with none of those rows all four reverse and the spec 022 function returns to its market-maker-only body.
