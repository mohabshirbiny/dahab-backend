# Research: First-sale advance, customer-side codes, referral, and the two pages (spec 023)

Every decision cites its source: **TS** = Technical Spec section, **DD** = Dashboard design (`docs/dahab-admin-dashboard.html`, `p-promos`, `p-mm`, `p-rates`), **AP** = app prototype (`docs/dahab-app-prototype.html`), **OA** = the owner's answers in `spec.md` (2026-10-12), **CODE** = what the repos do today, **PROPOSAL** = mine, for the owner to confirm. Bases read on 2026-10-12: Backend `main` f2e308b (spec 022 merged + the owner's answers), Dashboard `main` 6d0a82c, Customer app `main` a993878 (read-only shallow clones of the three repos).

The owner's two rules govern every gap (OA): **(1)** where nothing else is written the design wins; **(2)** every figure the design shows is a **setting** with the design's value as default, and so is any figure nobody gave. No business number is a constant in code. All settings are listed once, in `data-model.md` §7.

Planning decisions that the owner has **not** answered and that cannot be derived are labelled **P23-n** and collected in §R31. They are recommendations, not choices; the plan builds the recommended path and says where.

---

## R1 — What is built, what is not (CODE)

| Piece | State today |
|---|---|
| `promo_code` (code, kind `first_sale\|market_maker`, tie, `commission_waived`, `gives_spread`, `monthly_cap_egp`, on/off), `promo_code_use` (+ `buy_request_id`, `amount_egp`, `device_fingerprint`), `market_maker_approval` | spec 019 6A/6B. `CreatePromoCodeAction` refuses everything but `market_maker`; guard DH020 lets only the cap and the on/off change |
| `PromoCodeGate` | the buy side only; lock the code row, reasons `inactive`, `not_your_code`, `not_approved`, `too_new`, `cap_reached`; the cap sums the uses of requests still `queued`/`accepted` |
| Settlement | `OrderSettlement::compute` waives the commission when `listing->isFreeRelist() || request->promo_code !== null` (a boolean on `Piece`); `post()` credits a positive spread to the dealer. **No minimum-commission rule applies to a waiver** (TS §2.5, §8) |
| `payout.first_sale_cap_egp` | a setting (100,000, group RATES); ledger kind `first_sale_payout` exists and is labelled; nothing posts it |
| `listing_price_locked(uuid)` | spec 022: true while a market-maker approval exists; "Spec 023 extends it" is written in its own migration |
| Views of a listing | not measured; `ListMarketMakerQueueAction` returns `views: null` |
| National ID **number** | **not stored anywhere** (`identity_document` holds an image reference, status and reviewer only; `AuditEntryPresenter` lists `national_id` only as a key to mask) — see R8 |
| Dashboard | `pages/promos/index.vue` and `pages/market-maker/index.vue` are the simplified pages (code + dealer id + cap; a plain queue/approved/cost list); `pages/rates/index.vue` lists `payout.first_sale_cap_egp` and `marketmaker.min_list_age_days` in a static array |
| Customer app | `sell1_screen.dart` `_PromoCard` (flagged `MockMark`), a second promo row on `sell3_screen.dart`, both reading `ContentRepository.promo()` over `mockPromoCodes`; `invite_screen.dart` is a flagged MOCK with a sample code |

## R2 — One commission-credit mechanism for Stories 3 and 4 (OA, PROPOSAL)

**Decision.** A single table pair, `commission_credit` + `commission_credit_event`, holds every promise of "a lower commission on a later sale". It is **not wallet money**: no ledger account, no balance in `cust_available`, never withdrawable, never in a statement. It is spent only by reducing the commission line of a settlement.

| Kind | Meaning | Granted by | Spent as |
|---|---|---|---|
| `waive` | commission 0 (the minimum is not forced, R4) | a seller-side code with *Waive it entirely* | one use |
| `percent` | `percent_off` % off the ordinary commission, never below the minimum | a seller-side code with *Reduce by a percentage*; the friend's referral reward | one use per sale, `uses_total` sales |
| `amount` | an EGP balance off the commission, never below the minimum | the inviter's referral reward | partial: only what the sale could take; the rest stays |

- **Storage.** `commission_credit` (the promise: owner, source, kind, figures, `uses_left` / `amount_left_egp`, optional `listing_id` binding, `expires_at`, `status`) and an **append-only** `commission_credit_event` (`granted`, `spent`, `expired`, `voided`) with the order and the figures of each spend. A counter on the credit moves only with its event in the same transaction (deferred check, DH024).
- **Why a table and not a ledger account.** The ledger holds money Dahab owes or holds; a credit is a pricing entitlement. Putting it in the ledger would put a promise on `cust_available`'s side, make the non-negative check and the statements wrong, and need a Finance decision about a liability. The reduction needs no posting at all: a smaller commission leg and a larger seller leg already balance.
- **Binding.** A credit from a **code** is bound to the listing the code was applied to (`listing_id`); the friend's reward is bound the same way. The inviter's reward is **unbound**: it waits in the pool and any later sale of that seller may use it. A credit bound to a listing that is withdrawn, rejected or taken down is `voided`; relisting the same piece keeps it bound (the listing row stays).
- **Expiry.** `expires_at` for referral credits (`referral.credit_expiry_days`). Code credits carry none: "anything already in progress carries on" (DD note under the table) — turning a code off stops new redemptions, not a credit already granted. A sweep expires the rest (`commission-credits:expire`, hourly).
- **Alternatives rejected.** (a) EGP-only credits: the design's form says "Reduce by … Percent". (b) Percent-only credits: the prototype's *Earned so far 200 EGP* is a sum of EGP, which an `amount` credit gives exactly. (c) Crediting the wallet: R2 above. (d) One credit row per code in `promo_code_use`: the use log is staff's audit trail and is append-only; a credit needs counters.

## R3 — Which waiver applies: one, the lowest, never a sum (OA)

Candidates for one settlement, all computed from the **ordinary** commission `C0 = max(base × pct, commission.minimum_egp)` (the existing calculator with no waiver):

| Candidate | Commission | Floor (`commission.minimum_egp`) |
|---|---|---|
| none | `C0` | — |
| free relist (spec 018) | `0` | not forced |
| market-maker code on the buy request (spec 019) | `0` | not forced (TS §2.5, §8) |
| credit `waive` | `0` | not forced (P23-2) |
| credit `percent` p | `max(C0 × (100 − p) / 100, floor)`, round-half-up 4 dp | applied |
| credit `amount` a (left) | `max(C0 − a, floor)` | applied |

**Rule.** The settlement takes the **minimum** commission over the candidates. A tie goes by this priority: free relist, market maker, then credits (a code's before a referral's, then the earliest expiry, then the oldest). A credit that would not lower `C0` is **not consumed** (a 200 EGP credit on a sale already at the 200 EGP minimum stays in the pool). Only the winning credit is spent; the others are untouched. VAT follows the commission actually charged (OA). The seller's proceeds, the spread and the buyer's side are unchanged by any waiver.

The one implementation is a new `CommissionWaivers` resolver in `app/Support/Pricing`, called from three places that must agree: the settlement (`OrderSettlement::compute`), the advance's provisional proceeds (R12) and the sell form's preview (R24). `Piece::commissionWaived` stays for the calculator's own use; the settlement stops passing the boolean and applies the resolver on the ordinary breakdown instead.

## R4 — The minimum is enforced in the settlement, in code and in the database (OA, DD)

DD's closing note — "The minimum commission of 200 EGP is never broken, whatever this code says" — is a rule of the settlement, not text on a form:

1. `CommissionWaivers` floors every `percent` and `amount` candidate at `commission.minimum_egp` read live in the settlement's transaction (the setting is read once per settlement, the value is stored with the order).
2. The order records `commission_floor_egp` (the minimum read) and `commission_floor_applied` (true for `none`, `percent`, `amount`; false for `free_relist`, `market_maker`, `waive`).
3. A table CHECK on `"order"`: `commission_floor_applied IS NOT TRUE OR commission_amount >= commission_floor_egp`. A bug in the resolver cannot commit a below-minimum commission.
4. The New code form's note quotes the live setting (not a literal 200).

`marketmaker` and `free relist` keep their existing behaviour (no floor on a waived commission, TS §2.5), which the checks above leave alone.

## R5 — VAT, the tax invoice and the DH012 reconciliation (OA, Finance D23-5)

- VAT is `vat.pct × commission charged` (OA). The seller's invoice net is the commission charged and its VAT, exactly as the settlement posted them, so the deferred DH012 check (invoice = order = ledger) keeps passing **without change**. A sale whose commission is 0 issues no seller invoice (spec 018 behaviour, now also for MM and `waive`).
- The invoice lines (`InvoiceLines`) are untouched in this spec. Showing "commission before / discount" on the PDF is **P23-7** (Finance): the recommendation is no — the invoice shows the commission charged only; the discount is visible in the seller's order screen and in the staff order detail.
- A credit note (spec 016) can only give back what was charged; nothing about it changes. A credit is never restored when a credit note is issued.
- Nothing here is filed with the Tax Authority (unchanged, Part 4 §4).
- **Switch.** The seller-side codes and referral credits can be built and tested but are **off for customers** until Finance signs the VAT treatment and the credit's accounting: `promo.seller_codes_enabled` and `referral.enabled`, both default **false** (OA: "the build does not wait for it").

## R6 — A seller-side code, from the sell form to the listing (spec Story 2, DD, AP)

- The seller types a code on the sell form. The app calls `POST /customer/me/commission-preview` (R24), which validates the code and shows what it does; **nothing is recorded yet**.
- The code is recorded when the listing is created (`promo_code` on `POST /customer/me/listings`, validated again) or later with `PUT /customer/me/listings/{listing}/promo` and `DELETE …/promo`. One code per sale: one `listing_promo` row per listing (PK `listing_id`).
- Recording redeems the code in one transaction: lock the code row, run the gate (R7), insert the **allowed** `promo_code_use` (with `amount_egp` = the listing's current price from the spec 005 calculator, `id_key`, `device_fingerprint`), insert the bound `commission_credit` (+ its `granted` event). Removing or replacing the code `voids` that credit; the use row stays (append-only) and is ignored by every count because its credit is `voided`.
- The code can be applied or removed while the listing is `draft`, `changes_requested`, `in_review` or `live` and **no buyer is in line**; from `reserved` on it is frozen (`listing_promo_locked`, DH027). The seller's other state (suspended hold, withdrawn…) keeps the row; a relisted piece keeps its code.
- A staff **take-down**, a **withdrawal** or a **rejection** voids the credit (the piece left the market without a sale); a relist reuses nothing — the seller applies the code again (the redemption rules count only non-voided credits, so a voided one frees the share).
- The listing records the code, never the percentage: the credit holds the promise, so a code edited later cannot change a sale in progress (the code's terms are immutable anyway, R9).

## R7 — Who may use a code, and how often (DD, D23-1, D23-9)

The gate for a seller-side code, in this order (first failure wins; `reason` in `promo_code_invalid`):

| # | Check | reason |
|---|---|---|
| 1 | `promo.seller_codes_enabled` | `not_open` |
| 2 | code exists, `is_active`, kind not `market_maker` | `unknown` |
| 3 | `audience = account` ⇒ `tied_customer_id` is the caller (else `unknown`: the existence of an account-tied code is not revealed) | `unknown` |
| 4 | today (Cairo) within `starts_on … ends_on` | `not_started`, `ended` |
| 5 | `first_sale_only` ⇒ the seller has no paid sale (`"order".settlement_txn_id IS NOT NULL` as seller) | `first_sale_only` |
| 6 | `use_limit` by **id_key**: `once` ⇒ no non-voided credit of this code for this key; `monthly` ⇒ none this calendar month (Cairo) | `already_used` |
| 7 | `monthly_cap_egp` ⇒ `SUM(amount_egp)` of this month's non-voided uses + this price ≤ cap | `cap_reached` |
| 8 | `pays_advance` ⇒ the advance rules are checked and reported (not refused: the code still gives its commission terms) | — |

`id_key` is the **national-ID hash** when the customer has one (R8), else `account:<customer_id>`. D23-9 ("per national ID") is honoured wherever the ID is known. The code row is locked `FOR UPDATE` during the transaction (as `PromoCodeGate` does) so two redemptions cannot both take the last of a cap or a "once".

**Cap accounting (P23-10).** "EGP of transaction value" for a seller-side code is counted at redemption by the listing's **current price** (indicative for gold), the same figure the market-maker gate uses at the request. The alternative, counting at settlement, would let a code's cap be passed by listings already holding it. *Used this month* on the page sums non-voided uses of the month.

## R8 — "Once per ID" needs an ID number that is not stored (D23-9 vs CODE) — **P23-1**

The owner fixed *Once per ID* as **per national ID**. The Backend holds no ID number (R1). Recommendation and what the plan builds:

- Add `identity_document.id_number_hash` (nullable). At **review** (`POST /dashboard/identity-documents/{id}/review`, approve, Egyptian ID), the reviewer types the 14-digit number from the card; the server stores `HMAC-SHA256(app key, "national-id|" . number)` only. The number is never stored or logged (`AuditEntryPresenter` already masks `national_id`).
- A customer's key is the hash of their latest **approved** document; customers approved before this spec have none and fall back to `account:<customer_id>` — **weaker for those accounts** (one person with two accounts and no recorded ID could use a "once" code twice). The Dashboard form requires the number for new approvals; the API field is optional (non-breaking). Re-keying older approvals is a staff task, not a migration.
- No uniqueness constraint on the hash (a person re-registering is a policy question, not this spec's).
- If the owner prefers per account until IDs are captured, only the key function changes (`CustomerIdKey::for`).

## R9 — Promo-code terms: one migration, spec 019 rows keep their meaning (spec Story 5, DD)

Columns added to `promo_code` (all part of the guard DH020: **immutable after creation**, like `commission_waived`; only the cap and the on/off change):

| Column | Meaning | Default for spec 019 rows |
|---|---|---|
| `audience` `account\|anyone` | *Who can use it* | `account` |
| `commission_mode` `waive\|percent\|none` | *Commission* | `waive` |
| `commission_percent` | *Reduce by* (0 < p < 100, only for `percent`) | NULL |
| `use_limit` `unlimited\|once\|monthly` | *Times they can use it* | `unlimited` |
| `first_sale_only` | table *First sale only* | false |
| `pays_advance` | the code unlocks the advance (kind `first_sale`) | false |
| `min_listed_days` NULL = follow `marketmaker.min_list_age_days` | *Piece must be listed for* | NULL (still 7 today) |
| `requires_approval` | *Piece must be admin approved* | true |
| `starts_on`, `ends_on` | *Starts / Ends* (inclusive, Cairo) | NULL |

- `kind` gains `discount` (a seller-side commission code); `first_sale` now means **pays the advance** (`pays_advance`); `market_maker` is unchanged. CHECKs: `commission_waived = (commission_mode = 'waive')`; `commission_percent` present iff `percent`; `audience = 'account'` iff `tied_customer_id` present; `market_maker` ⇒ `account`, `waive`, `gives_spread`; `first_sale` ⇒ `pays_advance` and `first_sale_only`; `gives_spread` only for `market_maker` (a spread can only go to a dealer who bought).
- *Piece must be listed for / admin approved* are **market-maker** terms (they gate a buy request). They are stored as given for any code and shown in *Conditions* only for market-maker codes; the gate ignores them for seller-side codes. (Part of P23-3.)
- `PromoCodeGate` reads the code's `min_listed_days ?? marketmaker.min_list_age_days` and `requires_approval AND marketmaker.require_approval` (R21).

## R10 — The *New code* form maps to the API with two additions (DD vs TS §6.1) — **P23-3**

The 11 designed fields map one to one (`code`, `audience`, `tied_to`, `use_limit`, `commission_mode`, `commission_percent`, `gives_spread`, `min_listed_days`, `requires_approval`, `monthly_cap_egp`, `starts_on`/`ends_on`). Two things the design does not show but the Technical Spec and the design's own table need:

1. **First sale only** — the table prints it under *Tied to* for WELCOME, but the form has no control. 
2. **Pays the first gold sale ahead** — TS §6.1 gates the advance on "an active `promo_code` of `kind = first_sale`"; the form cannot create one.

**Recommendation (built as such, flagged in the report):** two extra Yes/No fields *First sale only* and *Also pays the first gold sale in advance*, shown for *Anyone* (and for *One account only*), default No. "Pays in advance" forces "first sale only". If the owner refuses them, first-sale codes can only be created by API and the advance stays reachable through staff enablement (R15).

The kind is **inferred** by the API when `kind` is omitted: the tied account is a market maker ⇒ `market_maker`; else `pays_advance` ⇒ `first_sale`; else `discount`. A sent `kind` that contradicts the inference is a `422`. The "Tied to" box takes an account number (`display_ref`) or a phone (`tied_to`); `tied_customer_id` is still accepted (the current page's body keeps working).

## R11 — The advance: switch, eligibility, timing (TS §6.1–6.3, OA, brief)

- **Switch.** `payout.first_sale_enabled`, default **false**, checked at the moment of advancing. Building and deploying moves no money; Finance flips it after D23-5. It lives in group RATES (`pricing.rates.manage`, the CEO and Finance).
- **Eligibility (all, at the moment):** the switch is on; category `gold` (never diamond or gold with diamond); the seller has **no earlier `first_sale_advance`** (database-enforced by `UNIQUE (seller_id)`) and **no earlier paid gold sale**; the seller is not suspended; an **active gate**: the listing carries an `unspent` code credit of a `pays_advance` code **or** the seller has an active staff enablement (R15); the exposure ceiling is not passed (R13); `min(provisional, cap) > 0`.
- **When.** At the inspection that **passes** (`RecordInspectionResultAction`, outcome `pass` → `awaiting_balance`), or when the buyer **accepts** an adjusted price (`DecideAdjustmentAction::decide`, accept → `awaiting_balance`). Never while an adjustment waits for the buyer, never on a stone regrade (gold only). A correction that re-derives `pass` after the advance does not advance twice (`UNIQUE (order_id)`).
- **Amount.** `advance = min(provisional proceeds, payout.first_sale_cap_egp)`, the proceeds computed on the IGI-measured weight with the locked rates and the **same waiver resolver** the settlement will use, in preview mode (nothing spent). The cap in force is stored on the advance (`cap_applied_egp`).
- **Skipped, not failed.** A seller who is not eligible at that moment (switch off, ceiling, suspended) simply isn't advanced; the settlement stays ordinary; the seller is told once by `ADVANCE_UNAVAILABLE` ("you'll be paid when the buyer pays"); an audit row `first_sale.skipped` records why (and feeds the spec 024 alerts). The inspection result is never blocked by an advance problem.

## R12 — Ledger at the advance, and reconciliation at payment (TS §6.3–6.4)

**At the advance** — one balanced `first_sale_payout` entry, actor = the system staff (promo-gated) or the enabling staff (staff enablement), tied to `order_id`/`listing_id`:

```
external_equity        −A
seller cust_available  +A
```

(sign convention: lines sum to zero; `external_equity` is Dahab's own capital, negative when it has been spent).

**At the buyer's payment** (`OrderSettlement::post`), with `R = min(A, proceeds)` and `seller_leg = proceeds − R`:

```
buyer cust_available   −balance (+excess)      buyer cust_held −deposit      [as today]
escrow +buyer_total / escrow −buyer_total                                    [as today]
seller cust_available  +seller_leg             (omitted when 0: posting_nonzero)
external_equity        +R                      (Dahab's front back; omitted when 0)
dahab_commission +c · vat_payable +v · dahab_spread +s                        [as today]
sum = 0 ✓   because seller_leg + R = proceeds
```

- If the final proceeds are **below** the advance (a commission-rate change, a correction), `R = proceeds`, the seller leg is 0, and `shortfall_absorbed_egp = A − R` is Dahab's incentive cost. Never negative for the seller; never clawed back from their wallet (TS §6.4).
- The advance row moves `outstanding → recovered` in the same transaction with `recovered_txn_id`, `recovered_egp`, `shortfall_absorbed_egp` (guard DH025: once, forward only).
- `"order".seller_proceeds` keeps meaning *what the seller is owed in total*; the wallet shows two lines (the advance earlier, the rest now). The seller's order screen shows both.
- The tax invoice is unaffected: it is built from the commission and VAT legs, which are the same (R5). The deferred DH012 check needs no change.
- The seller was paid money that can be **withdrawn at once** (normal withdrawal review, email second-check, payout-account rules apply — TS §6.3). The spec 013 "held" split is untouched: the advance sits in `cust_available`.

## R13 — Exposure ceiling and concurrency (DD `p-rates`, brief)

- DD: *Total exposed to first-sale payouts — EGP across everyone. The feature switches off above this* (1,000,000). New setting `payout.first_sale_exposure_ceiling_egp`.
- **Exposure** = `SUM(amount_egp) WHERE status = 'outstanding'`. The Dashboard shows exposure, ceiling, headroom and the list of outstanding advances (TS: "advances outstanding × cap"; the sum is exact, not a product).
- An advance that would take the sum **above** the ceiling is **skipped** (R11), not partly paid. The sell form's preview says `pays_advance.available = false, reason = ceiling` so the seller isn't promised money the platform won't front.
- **Concurrency.** The advance Action takes `pg_advisory_xact_lock(hashtext('first_sale_exposure'))` then reads the sum, so two inspections cannot both pass the last of the headroom; it locks the seller's `customer` row `FOR UPDATE` before checking "no earlier advance" (the `UNIQUE (seller_id)` is the backstop). The locks are held for one short transaction.

## R14 — The write-off rule (TS §6.5, OI-3.3, OA D23-5)

- **Ledger.** No posting. The advance already left `external_equity` when it was paid; writing it off is a **status change**: `outstanding → written_off` with `written_off_at`, `written_off_reason` and `written_off_amount_egp = A`. The cost is therefore visible in `external_equity`'s balance and in the advance list, and the advance leaves the exposure sum.
- **When.** Whenever the order ends **without** a payment: `cancelled_buyer_nopay` (the sweep), `cancelled_inspection` (a corrected result or a dispute resolved against the sale), `cancelled_staff`. One hook: `MovesOrder::moveOrder`, after the move, when `$to->isCancelled()` and the order has an outstanding advance → `WriteOffFirstSaleAdvanceAction` (reason from the state). The seller keeps the piece **and** the advance; nothing is clawed back (TS §6.5).
- **OI-3.3.** *Does the seller still receive the 50 % of the forfeited deposit?* Default (TS): **yes**, Dahab absorbs the advance. The setting `payout.first_sale_comp_offsets_writeoff` (default **false**) lets Finance direct the seller's share to Dahab instead: when true and the order had an advance, `ForfeitDepositAction` posts the whole deposit to `dahab_commission` (seller share 0). It is a setting, not a code branch the owner must decide now.
- **After a write-off** the seller can relist the returned piece, but it can never carry another advance (`UNIQUE (seller_id)`), and its price is locked (R16).

## R15 — Staff enablement of the advance (TS §6.1, spec Story 1.5)

`POST /dashboard/customers/{id}/first-sale-advance` (permission **`first_sale.manage`**, new; seeded to Finance, the CEO holds every code) with a mandatory reason creates a `first_sale_enablement` row for that seller; `DELETE` revokes it before use. The seller's **next** eligible gold sale uses it (the actor of that advance is the enabling staff member). One active enablement per seller; refused for a seller who already had an advance (`first_sale_not_eligible`). Every enable, revoke, advance, recovery, write-off and skip is audited. The Customer file shows the state; `first_sale.manage` also opens the exposure panel.

## R16 — Coupling with spec 022 (D23-4, OA)

`listing_price_locked(uuid)` is `CREATE OR REPLACE`d to `EXISTS(market_maker_approval) OR EXISTS(first_sale_advance WHERE listing_id = p_listing)` — **any** advance row, whatever its status, so a piece that carried an advance (also after a write-off and a relist) cannot change price. The guard DH023, `ChangeListingPriceAction`, `can_change_price` and the customer error `listing_price_locked` need no code change; the 022 message ("This piece's price is fixed for now") already fits. A test per state is added to the 022 suites.

## R17 — Cost figures stored on the order at settlement (spec Story 6)

Added to `"order"`, written by `OrderSettlement` in the same update as the other settlement columns, all **NULL** for orders settled before this spec ("not recorded", never back-filled or estimated):

| Column | Value |
|---|---|
| `commission_ordinary_amount` | `C0`, what an ordinary sale of the same piece would have paid |
| `commission_given_up` | `C0 − commission_amount` (≥ 0) |
| `commission_waiver_kind` | `none \| free_relist \| market_maker \| credit` |
| `commission_waiver_credit_id` | the spent credit (iff `credit`) |
| `commission_floor_egp`, `commission_floor_applied` | R4 |
| `spread_given_up` | for a market-maker sale, the positive spread credited to the dealer instead of `dahab_spread`; else 0 |
| `settled_at` | the settlement's time (the order had none; the ledger's `created_at` is not queried by pages) |

The **Market maker approvals** panel sums these for orders settled in the month: *Pieces bought by market makers* = count of `commission_waiver_kind = 'market_maker'`; *Commission given up* = sum of `commission_given_up` of those; *Spread given up* = sum of `spread_given_up`; **Total cost** = the two. The response also says how many earlier market-maker sales are *not recorded* (orders with a market-maker request but no stored figures). CHECKs make the columns all-or-none and `commission_amount + commission_given_up = commission_ordinary_amount`; a deferred trigger (DH026) ties a `credit` waiver to exactly one `spent` event with the same reduction.

## R18 — The view counter (D23-6, OA)

- `listing_view (listing_id, day, viewer_hash)`, primary key on all three: **one row per account-or-device per piece per day**. `viewer_hash = HMAC-SHA256(app key, "listing-view|" . listing_id . "|" . (customer_id | device fingerprint hash))`; no raw identifier is stored, so the table cannot say *who* viewed (the design's "never revealing who viewed").
- Recorded by `GET /market/listings/{listing}` (the detail), **not** by the list. The seller's own views are skipped (the optional customer token gives `is_mine`). A request with neither token nor `X-Device-Id` is not counted.
- Written by a queued job after the response (`RecordListingViewJob`, system actor, `INSERT … ON CONFLICT DO NOTHING`), so the public read-only `market` scope is **not** widened and a Redis outage loses a count, never a page.
- Read only by staff: forced RLS, elevated policy; the queue and the staff listing detail return a count. Pruned after `listing.view_retention_days` (90) by a daily command. Customers never see it (spec 019's `visibility.listing_stats` is unrelated and unchanged).
- Append-only (DH027) except the prune, which runs as the maintenance scope with the guard's documented exception (delete older than the window).

## R19 — *Against the guide* (spec Story 6, DD)

- **Gold:** `making total ÷ gold value × 100`, rounded to a whole percent, from the same `ListingPricer::quote` the queue already uses (`makingTotal`, `goldValue`). Tone `ok` up to `marketmaker.making_charge_warn_pct` (30, **P23-9**: DD shows 21 % green and does not say where it turns), `bad` above.
- **Stones:** the guide is **Rapaport**, an external integration left out; the API says `guide.kind = "none"` and the Dashboard prints *No guide yet* in grey. `market_maker_approval.rapaport_guide` stays NULL.
- The API returns numbers and a tone (`{kind, value_pct, tone}`), never prose; the Dashboard composes "Making charge 21% of gold".

## R20 — *Leave* (D23-7, OA)

`POST /dashboard/market-maker/queue/{listing}/leave` (permission `market_maker.approve`, idempotent) changes nothing about the piece and writes the audit event `market_maker.left` (actor, listing, optional note, the figures seen). The piece stays in the queue. No table. Toast "Left as it is.".

## R21 — Market-maker approval requirement and waiting period (spec Story 6, DD)

- `marketmaker.require_approval` (bool, default **true**, group OPERATIONS) and the existing `marketmaker.min_list_age_days` are both **read by the p-mm info note** and editable from *Commission rates*.
- Effective approval requirement for a code = `marketmaker.require_approval AND promo_code.requires_approval`. The global switch is the master: off ⇒ no market-maker code needs an approval; on ⇒ a code may still opt out with *No*. Effective waiting period = `promo_code.min_listed_days ?? marketmaker.min_list_age_days`.
- The **approval queue** itself keeps its global rule (only pieces older than `marketmaker.min_list_age_days` appear, TS §8), so approving is never possible before the waiting period.
- Changing either setting never touches an existing approval.

## R22 — Referral: the model (OA, AP)

- **Code.** Each customer has one referral code, created on first read of `GET /customer/me/referral` (`referral_code`): 8 characters from an unambiguous alphabet, random (a personal-data-free code, unlike the prototype's `MONA4417`); it never collides with a `promo_code.code` (both creations check the other table). Referral codes and promo codes share the sell-form field; the lookup tries promo codes first.
- **The friend** applies the code on the sell form like any code (P23-4: there is no registration field in the prototype or the app). Conditions: `referral.enabled`; not the inviter's own code (`own_referral`); the friend has **no paid sale**; the friend has no earlier referral (`UNIQUE (friend_id)` — one reward per friend); the inviter has not reached `referral.max_uses_per_code` (the usage cap); fewer than `referral.max_per_device` redemptions came from this device fingerprint (the per-device limit); and the friend's ID key differs from the inviter's. Applying creates the `referral` row (`joined`) and the **friend's credit** (`percent`, `referral.friend_percent_off`, `uses_total = referral.friend_sales_covered`, bound to the listing, expiring in `referral.credit_expiry_days`).
- **The inviter's reward** lands **after the friend completes the first sale**, not at sign-up (AP): when the friend's order settles with a sale value (`final_buyer_total`) of at least `referral.min_friend_sale_egp`, the referral moves `joined → rewarded` and the inviter receives one `amount` credit of `referral.inviter_reward_egp` expiring in `referral.credit_expiry_days`. A referral whose friend never sells stays `joined` and is not rewarded; a rejected/withdrawn listing or an expired friend credit does not void it (the friend may list again and sell without the discount; the link remains, and the reward still comes if they sell within the credit's lifetime counted from the join — otherwise `void` with reason `expired`).
- **"Where it stands"** (AP): *Invites sent* = the Share/Copy counter (P23-5); *Joined* = referrals of this inviter; *Sold something* = `rewarded`; *Earned so far* = sum of granted `amount` credits (EGP); the response also carries *used* and *available*. This replaces the prototype's single EGP figure faithfully because the reward **is** an EGP credit.
- **Abuse limits are settings with conservative defaults**: usage cap 10 friends, 1 redemption per device, 100 counted invitations, a minimum friend sale of 5,000 EGP, 90 days.

## R23 — How the inviter's reward is delivered (CODE: RLS scopes)

The friend's settlement runs in the buyer's `order` scope, which cannot insert a credit for a **third** customer (the inviter). So the reward is **not** granted inside the settlement. `PayBalanceAction` (after commit) dispatches `GrantReferralRewardJob(order_id)`; the job runs as the system actor and is idempotent (`referral.state` and the unique `inviter_credit_id`). A healer `referrals:reward-pending` (every 5 minutes, like `invoices:render-pending`) finds referrals still `joined` whose friend has a settled order and grants the reward — a lost job costs minutes, never the reward. The inviter is told by an `inbox` + SMS notice (R27).

## R24 — Customer endpoints (spec Stories 2–4)

| Endpoint | Purpose |
|---|---|
| `POST /customer/me/commission-preview` | stateless: the piece's figures (+ optional `code`) ⇒ the code's effect, the winning waiver, commission/VAT/"you receive" before and after, `pays_advance` availability. Records nothing. 10 per minute (`customer.promo`) so codes cannot be enumerated |
| `PUT /customer/me/listings/{listing}/promo` · `DELETE …/promo` · `promo_code` on `POST /customer/me/listings` | record / remove the code on the listing (R6) |
| `GET /customer/me/referral` · `POST /customer/me/referral/shares` | the Invite screen and the counter (R22) |
| `GET /customer/me/commission-credits` | the seller's credits (the sell form's "you have a credit"; the Invite screen's numbers) |

The sell form shows the preview **without** a code too, so an unbound credit in the pool (the inviter's) is visible before listing. Additive fields: `promo` and `pays_advance` on the seller's listing; `advance` and `commission` on the seller's order. Details in `contracts/`.

## R25 — Staff endpoints (spec Stories 5–6, Surfaces)

Changed (all additive or backward compatible): `GET|POST|PATCH /dashboard/promo-codes`, `GET /dashboard/promo-codes/{code}/uses`, `GET /dashboard/market-maker/queue`, `GET /dashboard/market-maker/cost`, the identity review (optional `id_number`), the staff order detail and the Customer file. New: `POST /dashboard/market-maker/queue/{listing}/leave`, `POST|DELETE /dashboard/customers/{customer}/first-sale-advance`, `GET /dashboard/first-sale-advances`. `GET /dashboard/market-maker/approvals` stays but the rebuilt page no longer calls it (the design has no "Approved" panel — P23-11).

## R26 — Row-level security and the new `promo` scope (CODE: spec 003 / 008 / 010 / 011 / 012 pattern)

- A new **non-elevated** scope `promo` (like `queue` and `market`), pushed only by `ApplyListingPromoAction` and the preview, keeps the customer and may: read `promo_code`; read/insert `promo_code_use`; insert `commission_credit` (+ events) and `listing_promo` for the caller's own listing and own `customer_id` (friend credit) ; read/insert `referral`/`referral_code` rows of the caller. Cross-customer counts ("how many redemptions from this device", "has this ID used this code") go through two `SECURITY INVOKER` SQL functions using the `listing_price_locked()` scope-switch pattern, answering **integers only**.
- The `order` scope (settlement, buyer as caller) may read the **seller's** credits of the order it is a party to, update their counters and insert `spent` events, and update the order's advance. Every write is audited with the customer as actor, as the scope's contract requires.
- Staff (elevated) read everything; customers read their own credits and referral rows and **never** `promo_code_use` or `listing_view`.
- `first_sale_advance` / `first_sale_enablement`: owner (the seller) may **read** their advance; staff elevated read/write; the settlement's `order` scope updates the advance of the order it settles.

## R27 — Notifications (CODE: spec 019 template catalogue)

New catalogue entries (EN + AR-DRAFT, golden fixture updated, SMS + email + inbox like the other order notices): `order.advance_paid`, `order.advance_unavailable`, `referral.reward_earned`. Not told: the friend (the screen shows it), staff (audit + the Dashboard panels; spec 024 will route alerts). A reduced commission is **not** a notice; the order screen says it.

## R28 — App texts (CODE: spec 019; OA rule 2)

Every customer-visible sentence is a `TextKey` with the English default and an **AR-DRAFT** Arabic default (`promo.*`, `referral.*`; listed in `data-model.md` §8). Figures in sentences use setting placeholders (`{referral_friend_pct}`, `{referral_reward_egp}`, `{first_sale_cap}`, `{commission_minimum_egp}`, `{referral_expiry_days}`), added to `SettingPlaceholders::MAP`, so a changed setting changes the words without a release. Keys are append-only. The Backend registry is refreshed from the app's manifest (`database/data/app_text_keys.json`, `php artisan app-texts:sync` on the dev database only).

## R29 — Settings (OA rule 2)

Sixteen keys, defaults, sources, who may change them: `data-model.md` §7. Each is a `SettingKey` case with its group, bounds and bool/integer flags (so the existing `PATCH /dashboard/settings` validates them) and appears on *Commission rates*. A setting is read live inside the transaction (existing rule).

## R30 — Dashboard and Customer app mapping, and what is removed (DD, AP, CODE)

- **Dashboard.** `pages/promos/index.vue` and `pages/market-maker/index.vue` are rewritten to the design; `pages/rates/index.vue` gains rows for the new settings and an exposure panel; the Customer file gains the enable-advance action and state; the identity review gains the ID-number field; the staff order detail shows the advance and the waiver. `useMarketMakers.ts` is split/extended with typed shapes.
- **Customer app.** One shared `PromoCard` widget (sell1 and sell3 use it) replaces the two mock inputs; `SellDraft` carries `promoCode` and sends it; the sell form's estimate and "when your money reaches you" read the preview; `MockContentRepository.promo()`, `mockPromoCodes`, the `PromoCode` model and the `MockMark` go; `invite_screen.dart` becomes real and leaves `mockScreens`; the seller's order screen shows the advance and the commission saved. Texts as R28.

## R31 — Open planning decisions (not answered by the owner, not derivable)

Each is built on the recommendation; the report lists them for the owner.

| ID | Question | Recommendation (built) |
|---|---|---|
| **P23-1** | The Backend stores no national-ID number, so D23-9 (*Once per ID*) cannot be enforced as written | Capture a hash at identity review (R8); until captured, the account is the key |
| **P23-2** | *Waive it entirely* on a seller-side code versus "the minimum is never broken" | Waive = 0 (TS §2.5: the minimum floors a charged commission, it does not force one); *Reduce* is floored |
| **P23-3** | The form cannot create a first-sale code or say *First sale only* | Two extra Yes/No fields (R10) |
| **P23-4** | Where a friend enters the referral code | In the sell form's code field; no registration field |
| **P23-5** | What *Invites sent* and *invitation limit per account* mean when Share has no recipient | A counter of Share/Copy taps, capped by `referral.max_invitations_per_account`; the effective rewards cap is `referral.max_uses_per_code` |
| **P23-6** | Reward shapes | Friend: percent off for N sales; inviter: an EGP credit (matches *Earned so far 200 EGP*); every figure a setting, Finance to confirm |
| **P23-7** | Does the tax invoice show the discount? (Finance, with D23-5) | No: invoice = commission charged; discount shown on the order screens |
| **P23-8** | Three master switches default off (`payout.first_sale_enabled`, `promo.seller_codes_enabled`, `referral.enabled`) | Off until Finance signs; flipped from *Commission rates* by `pricing.rates.manage` |
| **P23-9** | Where *Against the guide* turns red for gold | `marketmaker.making_charge_warn_pct` = 30 |
| **P23-10** | Cap accounting for seller-side codes | At redemption, by the listing's current price (R7) |
| **P23-11** | The design has no *Approved* panel on the market-maker page | Dropped from the page; the endpoint stays |

## R32 — Alternatives considered and not taken (summary)

- A **second price lock** for the advance (rejected): extended `listing_price_locked()` instead (R16).
- Estimating *Commission given up* from the calculator at read time (rejected by the brief): stored at settlement (R17).
- A `view` write in the `market` scope (rejected): a job keeps the scope read-only (R18).
- Granting the inviter's reward inside the friend's settlement (rejected): RLS (R23).
- Posting the write-off to the ledger (rejected): no second movement; the cost already left equity (R14).
- A global "stacking" order table (rejected): the rule is one, the lowest (R3).
