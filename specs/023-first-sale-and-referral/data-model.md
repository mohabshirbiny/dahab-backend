# Data model: First-sale advance, customer-side codes, referral, the two pages (spec 023)

PostgreSQL only, as every spec migration (`if (DB::getDriverName() !== 'pgsql') return;`). Four migrations, in this order, each mirrored in `docs/Database schema/` (`01_schema_core.sql` settings, `03_schema_ledger.sql` unchanged, `04_schema_market.sql` orders/listings/credits/advances/views, `05_schema_security.sql` `promo_code` and the guards, `00_schema_full.sql` everything). New SQLSTATEs **DH024–DH027** (the last used is DH023). Decisions: `research.md` R-numbers in brackets.

| # | Migration | Adds | Rollback |
|---|---|---|---|
| M1 | `2026_10_16_000010_promo_code_terms.php` | `promo_code` terms, `promo_code_use` columns, `identity_document.id_number_hash`, 16 settings | refuses if any `promo_code` row has a new-term value other than the spec 019 defaults, or any `promo_code_use` has a `listing_id`/`id_key`; otherwise reverses |
| M2 | `2026_10_16_000020_commission_credit.php` | `commission_credit`, `commission_credit_event`, `listing_promo`, `referral_code`, `referral`, `"order"` settlement-cost columns, the `promo` scope policies, two count functions, DH024, DH026, DH027 (listing_promo) | refuses once any credit, referral, listing_promo row or any order with stored cost figures exists |
| M3 | `2026_10_16_000030_first_sale_advance.php` | `first_sale_enablement`, `first_sale_advance`, DH025, `listing_price_locked()` replaced | refuses once an enablement or an advance exists (tied to ledger entries); else restores the spec 022 function |
| M4 | `2026_10_16_000040_listing_views.php` | `listing_view` (+ its append-only guard, DH027) | drops it (counts are not business records); no refusal |

Down-migrations follow the project rule: a migration that created rows tied to append-only ledger entries or audit refuses to roll back with data and needs a second reviewer recorded in the PR (as 012, 019, 022). The permission `first_sale.manage` is a PHP enum case synced by `SyncPermissionCatalogueAction`, not a migration.

---

## 1. M1 — promo-code terms, use log, ID hash, settings

### 1.1 `promo_code` (altered) [R9, R10]

| Column | Type | Rule |
|---|---|---|
| `kind` | existing | CHECK widened to `IN ('first_sale','market_maker','discount')` (drop `promo_code_kind_check`, add) |
| `audience` | `TEXT NOT NULL DEFAULT 'account'` | `CHECK IN ('account','anyone')`; `(audience = 'account') = (tied_customer_id IS NOT NULL)` |
| `commission_mode` | `TEXT NOT NULL DEFAULT 'none'` | `CHECK IN ('waive','percent','none')`; **backfill**: `waive` for every existing row (all are market-maker); `(commission_mode = 'waive') = commission_waived` |
| `commission_percent` | `NUMERIC(7,4)` | `CHECK ((commission_mode = 'percent') = (commission_percent IS NOT NULL))`; `CHECK (commission_percent IS NULL OR (commission_percent > 0 AND commission_percent < 100))` |
| `use_limit` | `TEXT NOT NULL DEFAULT 'unlimited'` | `CHECK IN ('unlimited','once','monthly')` |
| `first_sale_only` | `BOOLEAN NOT NULL DEFAULT FALSE` | |
| `pays_advance` | `BOOLEAN NOT NULL DEFAULT FALSE` | `(kind = 'first_sale') = pays_advance`; `pays_advance` ⇒ `first_sale_only` |
| `min_listed_days` | `SMALLINT` | NULL = follow `marketmaker.min_list_age_days`; `CHECK (min_listed_days IS NULL OR min_listed_days BETWEEN 0 AND 3650)` |
| `requires_approval` | `BOOLEAN NOT NULL DEFAULT TRUE` | |
| `starts_on`, `ends_on` | `DATE` | `CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)`; read as Cairo days, inclusive |
| (kind rules) | CHECK `promo_code_kind_terms` | `kind <> 'market_maker' OR (audience = 'account' AND commission_mode = 'waive' AND gives_spread)`; `kind = 'market_maker' OR NOT gives_spread`; `kind <> 'discount' OR commission_mode <> 'none'` |

- The existing `uq_promo_code_one_per_customer` (one market-maker code per customer) is kept.
- **Guard `promo_code_guard()` (DH020) is replaced** (CREATE OR REPLACE) so the nine new columns join the immutable list; still only `monthly_cap_egp`, `is_active`, `deactivated_by/at` may change; deletion still refused.
- Backfilled spec 019 rows therefore read: *No commission · Spread goes to them · One account · 7 days (follows the setting) · Admin approved · cap as before*.

### 1.2 `promo_code_use` (altered) [R6, R7]

| Column | Type | Rule |
|---|---|---|
| `listing_id` | `UUID REFERENCES listing(listing_id)` | seller-side uses |
| `id_key` | `TEXT` | national-ID hash, or `account:<customer_id>` (R8); NULL for uses before this spec |
| `credit_id` | `UUID` | FK added in M2 (`commission_credit`) |

Still append-only (DH020). `buy_request_id` (market-maker) and `listing_id`/`credit_id` (seller side) are exclusive: `CHECK (num_nonnulls(buy_request_id, listing_id) <= 1)`. Indexes: `(code, id_key) WHERE allowed`, `(code, used_at DESC)` (exists).

### 1.3 `identity_document.id_number_hash` [R8]

`TEXT NULL`. Set once by the reviewer's approve (Egyptian ID); `HMAC-SHA256(app key, "national-id|" || number)`, hex. Not unique. Never returned by any API. Customer scope may read it on their own document (existing owner policy) for the `id_key` computation; no resource exposes it.

### 1.4 Settings (16 rows) → §7.

---

## 2. M2 — credits, referral, listing code, order cost, scope, functions

### 2.1 `commission_credit` [R2]

| Column | Type | Rule |
|---|---|---|
| `credit_id` | `UUID PK DEFAULT gen_random_uuid()` | |
| `customer_id` | `UUID NOT NULL REFERENCES customer` | the seller who benefits |
| `source` | `TEXT NOT NULL` | `CHECK IN ('promo_code','referral_friend','referral_inviter')` |
| `kind` | `TEXT NOT NULL` | `CHECK IN ('waive','percent','amount')` |
| `percent_off` | `NUMERIC(7,4)` | present iff `kind = 'percent'`; `> 0 AND < 100` |
| `amount_egp` | `NUMERIC(18,4)` | granted amount; present iff `kind = 'amount'`; `> 0` |
| `amount_left_egp` | `NUMERIC(18,4)` | present iff `amount`; `0 <= amount_left_egp <= amount_egp` |
| `uses_total`, `uses_left` | `SMALLINT` | present iff `kind <> 'amount'`; `uses_total >= 1`, `0 <= uses_left <= uses_total` |
| `listing_id` | `UUID REFERENCES listing` | NULL = the pool (the inviter's reward) |
| `promo_code` | `TEXT REFERENCES promo_code(code)` | iff `source = 'promo_code'` |
| `referral_id` | `UUID REFERENCES referral` | iff `source` is a referral source |
| `status` | `TEXT NOT NULL DEFAULT 'active'` | `CHECK IN ('active','exhausted','expired','voided')`; `exhausted` ⇔ nothing left (uses_left = 0 or amount_left_egp = 0) |
| `granted_at` | `TIMESTAMPTZ NOT NULL DEFAULT now()` | |
| `expires_at` | `TIMESTAMPTZ` | referral credits; NULL for code credits |
| `ended_at` | `TIMESTAMPTZ` | set with a non-active status |
| CHECKs | `credit_source_ref` | `(source = 'promo_code') = (promo_code IS NOT NULL)`; `(source <> 'promo_code') = (referral_id IS NOT NULL)`; `source = 'referral_inviter' ⇒ listing_id IS NULL`; `source IN ('promo_code','referral_friend') ⇒ listing_id IS NOT NULL` |

Indexes: `(customer_id, status)`, `(listing_id) WHERE listing_id IS NOT NULL`, `(promo_code, granted_at) WHERE promo_code IS NOT NULL`, `(expires_at) WHERE status = 'active' AND expires_at IS NOT NULL`. Unique: one **active or exhausted** credit per `(listing_id, promo_code)`.

### 2.2 `commission_credit_event` (append-only, **DH024**) [R2]

| Column | Type | Rule |
|---|---|---|
| `event_id` | `BIGSERIAL PK` | |
| `credit_id` | `UUID NOT NULL REFERENCES commission_credit` | |
| `kind` | `TEXT NOT NULL` | `CHECK IN ('granted','spent','expired','voided')` |
| `order_id` | `UUID REFERENCES "order"` | iff `spent` |
| `ordinary_commission_egp`, `charged_commission_egp`, `reduction_egp` | `NUMERIC(18,4)` | iff `spent`; `reduction = ordinary − charged > 0` |
| `amount_left_after_egp` / `uses_left_after` | `NUMERIC(18,4)` / `SMALLINT` | after the event |
| `reason` | `TEXT` | for `voided`/`expired` (`listing_withdrawn`, `listing_rejected`, `listing_taken_down`, `code_removed`, `code_replaced`, `expired`) |
| `actor_customer_id`, `actor_staff_id` | `UUID` | `CHECK (num_nonnulls(...) = 1)` (Principle I); system sweeps use the system staff |
| `created_at`, `txid` | `TIMESTAMPTZ DEFAULT clock_timestamp()`, `BIGINT DEFAULT txid_current()` | |

Triggers (SQLSTATE **DH024**):
- `trg_credit_event_immutable BEFORE UPDATE OR DELETE` — append-only.
- `trg_commission_credit_guard BEFORE UPDATE ON commission_credit` — only `uses_left`, `amount_left_egp`, `status`, `ended_at` move, and only forward (never up, never from a non-active status).
- `trg_commission_credit_recorded` (**deferred constraint trigger**, AFTER UPDATE): a counter or status that moved must have an event with the same `txid` for that credit.

### 2.3 `listing_promo` [R6]

| Column | Type | Rule |
|---|---|---|
| `listing_id` | `UUID PK REFERENCES listing` | one code per sale |
| `promo_code` | `TEXT REFERENCES promo_code(code)` | |
| `referral_inviter_id` | `UUID REFERENCES customer` | for a referral code |
| `credit_id` | `UUID NOT NULL REFERENCES commission_credit` | the credit this application created |
| `applied_at` | `TIMESTAMPTZ NOT NULL DEFAULT now()` | |

`CHECK (num_nonnulls(promo_code, referral_inviter_id) = 1)`. **Removing** a code deletes the row **only** while the listing allows it; the guard `trg_listing_promo_guard` (**DH027**) refuses INSERT/UPDATE/DELETE when the listing's state is not in `draft, changes_requested, in_review, live` or when a buy request is `queued/accepted` on it. Forced RLS: owner (`listing.seller_id = current customer`) and staff; the `promo` scope writes for the caller's own listing.

### 2.4 `referral_code` and `referral` [R22]

`referral_code`: `customer_id UUID PK REFERENCES customer`, `code TEXT UNIQUE NOT NULL CHECK (code ~ '^[A-Z0-9]{8}$')`, `shares_count INT NOT NULL DEFAULT 0 CHECK (>= 0)`, `created_at`. RLS: owner + staff. A friend resolves a code by value through the function `referral_lookup()` (§2.7), never by reading the table.

`referral`:

| Column | Type | Rule |
|---|---|---|
| `referral_id` | `UUID PK` | |
| `inviter_id`, `friend_id` | `UUID NOT NULL REFERENCES customer` | `CHECK (inviter_id <> friend_id)`; `UNIQUE (friend_id)` — one reward per friend |
| `code` | `TEXT NOT NULL` | the inviter's code as used |
| `listing_id` | `UUID REFERENCES listing` | the friend's listing it was applied to |
| `device_fingerprint` | `TEXT` | the redemption's device (hash); NULL if unknown |
| `id_key` | `TEXT` | the friend's key at the time (self-referral and same-person checks) |
| `state` | `TEXT NOT NULL DEFAULT 'joined'` | `CHECK IN ('joined','rewarded','void')` |
| `joined_at` | `TIMESTAMPTZ NOT NULL DEFAULT now()` | |
| `reward_order_id` | `UUID REFERENCES "order"` | the friend's settled sale |
| `rewarded_at`, `void_reason` | | `rewarded` ⇒ `reward_order_id`, `rewarded_at`, `inviter_credit_id` all present |
| `friend_credit_id`, `inviter_credit_id` | `UUID REFERENCES commission_credit` | |

Forward-only state (`joined → rewarded|void`), guard in DH024. RLS: the inviter and the friend read their own rows; the `promo` scope inserts as the friend; the reward job (system) updates. Index `(inviter_id, state)`, `(device_fingerprint) WHERE device_fingerprint IS NOT NULL`.

### 2.5 `"order"` settlement-cost columns [R17, R4]

All nullable, written together at settlement; NULL for earlier orders.

| Column | Type |
|---|---|
| `commission_ordinary_amount` | `NUMERIC(18,4)` |
| `commission_given_up` | `NUMERIC(18,4)` |
| `commission_waiver_kind` | `TEXT` `CHECK IN ('none','free_relist','market_maker','credit')` |
| `commission_waiver_credit_id` | `UUID REFERENCES commission_credit` |
| `commission_floor_egp` | `NUMERIC(18,4)` |
| `commission_floor_applied` | `BOOLEAN` |
| `spread_given_up` | `NUMERIC(18,4)` |
| `settled_at` | `TIMESTAMPTZ` |

CHECKs: `order_cost_shape` — the eight are all NULL or all present, and present only with `final_buyer_total` (the existing settlement shape); `commission_given_up >= 0`; `commission_amount + commission_given_up = commission_ordinary_amount`; `(commission_waiver_kind = 'credit') = (commission_waiver_credit_id IS NOT NULL)`; `commission_waiver_kind <> 'none' OR commission_given_up = 0`; **`commission_floor_applied IS NOT TRUE OR commission_amount >= commission_floor_egp`** (R4); `spread_given_up >= 0`. Deferred trigger (**DH026**) `trg_order_waiver_reconciled`: a `credit` waiver has exactly one `spent` event with the same order and `reduction_egp = commission_given_up`. Index `(settled_at) WHERE commission_waiver_kind = 'market_maker'` for the cost panel.

### 2.6 The `promo` scope and RLS [R26]

- PHP: `DatabaseActor::SCOPES` gains `promo` (not in `ELEVATED`), with `DatabaseActor::promo(Closure)`; `SetDatabaseActor` is unchanged.
- Policies (forced RLS on every new table). `promo` scope: `promo_code` SELECT; `promo_code_use` SELECT/INSERT; `commission_credit` INSERT where `customer_id` = caller; `commission_credit_event` INSERT; `listing_promo` all for the caller's own listing; `referral` INSERT as the friend; `referral_code` INSERT/UPDATE own. `order` scope: `commission_credit` SELECT/UPDATE and `commission_credit_event` INSERT where `customer_id` is the **seller of an order the caller is a party to**; `first_sale_advance` SELECT/UPDATE likewise (§3). Owner policies: a customer reads their own `commission_credit(_event)`, `referral`, `referral_code`, `first_sale_advance` rows. Staff (elevated) all. Nobody but staff reads `promo_code_use`, `first_sale_enablement`, `listing_view`.

### 2.7 Functions (integers/booleans only, `listing_price_locked()` scope-switch pattern)

- `promo_code_id_uses(p_code TEXT, p_id_key TEXT, p_since TIMESTAMPTZ) RETURNS int` — non-voided credits of the code for the key since `p_since` (NULL = ever).
- `referral_device_count(p_device TEXT) RETURNS int` and `referral_inviter_count(p_inviter UUID) RETURNS int` — rows by device / by inviter (not `void`).
- `referral_lookup(p_code TEXT) RETURNS uuid` — the inviter's id for a code, so a friend can resolve a code without reading `referral_code`.

---

## 3. M3 — first-sale advance

### 3.1 `first_sale_enablement` [R15]

`enablement_id UUID PK`, `seller_id UUID NOT NULL REFERENCES customer`, `enabled_by UUID NOT NULL REFERENCES staff`, `reason TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 1000)`, `created_at`, `revoked_by UUID`, `revoked_at` (pair CHECK), `used_advance_id UUID` (FK to the advance, set once). Partial unique `(seller_id) WHERE revoked_at IS NULL AND used_advance_id IS NULL` — one open enablement per seller. Guard **DH025**: only `revoked_*` and `used_advance_id` may be set, each once; no delete.

### 3.2 `first_sale_advance` [R11–R14]

| Column | Type | Rule |
|---|---|---|
| `advance_id` | `UUID PK` | |
| `order_id` | `UUID NOT NULL UNIQUE REFERENCES "order"` | |
| `seller_id` | `UUID NOT NULL UNIQUE REFERENCES customer` | **one advance per seller, ever** — the database's "first sale" |
| `listing_id` | `UUID NOT NULL REFERENCES listing` | |
| `amount_egp` | `NUMERIC(18,4) NOT NULL CHECK (amount_egp > 0)` | |
| `cap_applied_egp` | `NUMERIC(18,4) NOT NULL` | `amount_egp <= cap_applied_egp` |
| `provisional_proceeds_egp` | `NUMERIC(18,4) NOT NULL` | the proceeds the advance was cut from |
| `basis` | `TEXT NOT NULL CHECK IN ('promo_code','staff_enablement')` | |
| `promo_code` | `TEXT REFERENCES promo_code(code)` | iff `promo_code` basis |
| `enablement_id` | `UUID REFERENCES first_sale_enablement` | iff `staff_enablement` basis |
| `advance_txn_id` | `UUID NOT NULL REFERENCES ledger_transaction` | the `first_sale_payout` entry |
| `status` | `TEXT NOT NULL DEFAULT 'outstanding'` | `CHECK IN ('outstanding','recovered','written_off')` |
| `recovered_txn_id` | `UUID REFERENCES ledger_transaction` | iff `recovered` |
| `recovered_egp`, `shortfall_absorbed_egp` | `NUMERIC(18,4)` | iff `recovered`; `recovered + shortfall = amount` |
| `written_off_at`, `written_off_reason`, `written_off_amount_egp` | | iff `written_off`; reason `CHECK IN ('buyer_did_not_pay','cancelled_at_inspection','cancelled_by_staff','dispute_against_sale')`; amount = `amount_egp` |
| `created_at` | `TIMESTAMPTZ NOT NULL DEFAULT now()` | |

Guard **DH025** (`trg_first_sale_advance_guard BEFORE UPDATE OR DELETE`): no delete; the identity columns and amounts never change; `status` moves only `outstanding → recovered` or `outstanding → written_off`, once, setting the matching columns. Index `(status)` (exposure), `(listing_id)`. RLS: the seller reads their own row; staff elevated; `order` scope updates the advance of the order it settles.

The ledger needs **no change**: `first_sale_payout` already exists as an event kind, `external_equity` as an account.

### 3.3 `listing_price_locked(uuid)` replaced [R16]

```sql
CREATE OR REPLACE FUNCTION listing_price_locked(p_listing UUID) RETURNS boolean ... -- same scope-switch body, SELECT:
  EXISTS (SELECT 1 FROM market_maker_approval WHERE listing_id = p_listing)
  OR EXISTS (SELECT 1 FROM first_sale_advance WHERE listing_id = p_listing)
```

`down()` of M3 restores the spec 022 body. No trigger or API change.

---

## 4. M4 — listing views [R18]

`listing_view (listing_id UUID NOT NULL REFERENCES listing, day DATE NOT NULL, viewer_hash TEXT NOT NULL, PRIMARY KEY (listing_id, day, viewer_hash))`. Forced RLS: elevated only (read and write). Guard **DH027** `trg_listing_view_immutable BEFORE UPDATE`; DELETE allowed only when `day` is older than `listing.view_retention_days` (the prune command, maintenance scope) — otherwise DH027. `day` is the Cairo day.

---

## 5. Enums, models, factories (skill `laravel-eloquent-models`)

New: `PromoCodeKind` (`market_maker`, `first_sale`, `discount`), `CommissionMode`, `PromoAudience`, `PromoUseLimit`, `CreditKind`, `CreditSource`, `CreditStatus`, `WaiverKind`, `AdvanceStatus`, `AdvanceBasis`, `WriteOffReason`, `ReferralState`. Models: `CommissionCredit`, `CommissionCreditEvent`, `ListingPromo`, `ReferralCode`, `Referral`, `FirstSaleEnablement`, `FirstSaleAdvance`, `ListingView` (no model: written by the job with the query builder). Changed: `PromoCode`, `PromoCodeUse`, `Order` (casts + `@property`), `Listing` (`promo()`, `advance()`), `IdentityDocument`. Factories for every model; `PromoCodeFactory` states `marketMaker()`, `discount()`, `firstSale()`, `anyone()`. `SettingKey` gains 16 cases (§7); `StaffPermission` gains `FIRST_SALE_MANAGE`; `LedgerEventKind` unchanged; `OrderEvent` gains `ADVANCE_PAID`, `ADVANCE_UNAVAILABLE`; `AuditEvent` gains the cases of `contracts/codes-and-events.md`.

## 6. State machines

```
commission_credit  active ──► exhausted        (last use / balance spent, with its event)
                   active ──► expired | voided
referral           joined ──► rewarded | void
first_sale_advance outstanding ──► recovered | written_off      (each once)
first_sale_enablement open ──► revoked | used
```

No reverse move exists in any of them (guards DH024, DH025).

## 7. Settings (all new keys)

Read live inside the transaction (`Settings`), changed on *Commission rates* (`PATCH /dashboard/settings`), each change audited with the old value and a reason (existing behaviour). "Who" is the permission of the key's `SettingGroup`: **RATES** = `pricing.rates.manage` (the CEO and Finance), **OPERATIONS** = `settings.manage` (founders).

| # | Key | Meaning | Default | Bounds | Source | Group → who |
|---|---|---|---|---|---|---|
| 1 | `payout.first_sale_enabled` | master switch of the first-sale advance | **false** | bool | brief; OA D23-5 | RATES → CEO, Finance |
| 2 | `payout.first_sale_exposure_ceiling_egp` | EGP of advances outstanding across everyone; above it the advance is skipped | 1,000,000 | ≥ 0 | DD `p-rates` | RATES |
| 3 | `payout.first_sale_comp_offsets_writeoff` | the seller's share of a forfeited deposit goes to Dahab when an advance was paid on the order | **false** | bool | TS OI-3.3 default | RATES |
| 4 | `promo.seller_codes_enabled` | seller-side commission codes may be redeemed by customers | **false** | bool | OA (Finance D23-5: VAT, credit accounting) | RATES |
| 5 | `referral.enabled` | invitations and referral credits work | **false** | bool | OA | RATES |
| 6 | `referral.friend_percent_off` | the friend's % off the commission | 50 | 0 < x < 100 | AP "lower commission"; DD WELCOME 50 % | RATES |
| 7 | `referral.inviter_reward_egp` | the inviter's EGP commission credit | 200 | > 0 | AP "Earned so far 200 EGP" | RATES |
| 8 | `referral.friend_sales_covered` | sales the friend's % applies to | 1 | integer ≥ 1 | OA ("how many sales") | RATES |
| 9 | `referral.credit_expiry_days` | days a referral credit lasts | 90 | integer ≥ 1 | PROPOSAL | RATES |
| 10 | `referral.max_uses_per_code` | friends who may join with one code (the usage cap) | 10 | integer ≥ 1 | PROPOSAL (conservative) | RATES |
| 11 | `referral.max_invitations_per_account` | Share/Copy taps counted per account | 100 | integer ≥ 1 | PROPOSAL (P23-5) | RATES |
| 12 | `referral.max_per_device` | referral redemptions from one device | 1 | integer ≥ 1 | PROPOSAL (conservative) | RATES |
| 13 | `referral.min_friend_sale_egp` | smallest sale of the friend that earns the reward | 5,000 | ≥ 0 | PROPOSAL | RATES |
| 14 | `marketmaker.require_approval` | a market-maker purchase needs an approval (master switch) | **true** | bool | spec Story 6, DD `p-mm` | OPERATIONS → founders |
| 15 | `marketmaker.making_charge_warn_pct` | making charge above this % of gold value shows red on the approvals page | 30 | 0–1000 | PROPOSAL (P23-9) | OPERATIONS |
| 16 | `listing.view_retention_days` | days a view row is kept | 90 | integer ≥ 1 | PROPOSAL (R18) | OPERATIONS |

Existing settings this spec reads and does not change: `commission.minimum_egp` (200), `commission.gold_pct`, `commission.stone_pct`, `vat.pct`, `payout.first_sale_cap_egp` (100,000), `marketmaker.min_list_age_days` (7), `deposit.seller_forfeit_share_pct`, `buyrequest.price_tolerance_pct`.

`SettingPlaceholders::MAP` gains `first_sale_cap`, `referral_friend_pct`, `referral_reward_egp`, `referral_expiry_days`, `referral_friend_sales` (for app texts, R28). The spec 005 `setting` seed rows are inserted by M1 with unit and description; `SettingKey::isBool/isInteger/min/max/group` are extended.

## 8. App-text keys (customer-visible; R28)

English is the code's own wording; Arabic is **AR-DRAFT** (Egyptian, for the owner's review). `kind` = `app` unless noted. `{…}` are placeholders (named, validated by the registry).

| Key | English | Arabic (AR-DRAFT) |
|---|---|---|
| `promo.card.title` | Promo code | كود خصم |
| `promo.card.hint` | If you have a promo code, enter it here. | لو معاك كود خصم اكتبه هنا. |
| `promo.card.apply` | Apply | تطبيق |
| `promo.card.remove` | Remove | شيل الكود |
| `promo.card.enter_first` | Enter a code first. | اكتب الكود الأول. |
| `promo.result.percent` | {percent}% off the commission | خصم {percent}% على العمولة |
| `promo.result.waive` | No commission on this sale | من غير عمولة على البيعة دي |
| `promo.result.amount` | {amount} off the commission | خصم {amount} من العمولة |
| `promo.result.minimum` | The {minimum} EGP minimum commission still applies. | الحد الأدنى للعمولة {minimum} جنيه لسه ساري. |
| `promo.result.first_sale_only` | For your first sale only. | للبيعة الأولى بس. |
| `promo.result.applied` | Applied to this piece. | اتطبّق على القطعة دي. |
| `promo.result.removed` | Code removed. | الكود اتشال. |
| `promo.result.credit_waiting` | You have {amount} of commission credit. It is used on your next sale. | عندك رصيد عمولة {amount}. هيتخصم من بيعتك الجاية. |
| `promo.estimate.saving` | Promo saving | توفير الكود |
| `promo.advance.available` | Paid as soon as IGI confirms your piece, up to {cap}. | هتتدفعلك أول ما IGI تأكد القطعة، لحد {cap}. |
| `promo.advance.not_first_gold` | This code pays a first gold sale early. This is not your first gold sale. | الكود ده بيدفع أول بيعة دهب بدري. دي مش أول بيعة دهب ليك. |
| `promo.advance.unavailable` | Paying you early is not available right now. You are paid when the buyer pays. | الدفع بدري مش متاح دلوقتي. هتتدفعلك لما المشتري يدفع. |
| `promo.error.unknown` | That code is not valid. | الكود ده مش صحيح. |
| `promo.error.not_started` | This code has not started yet. | الكود ده لسه ما بدأش. |
| `promo.error.ended` | This code has ended. | الكود ده خلص. |
| `promo.error.first_sale_only` | This code is for your first sale only. | الكود ده لأول بيعة ليك بس. |
| `promo.error.already_used` | You have already used this code. | إنت استخدمت الكود ده قبل كده. |
| `promo.error.cap_reached` | This code has reached its limit for the month. | الكود ده وصل للحد بتاعه الشهر ده. |
| `promo.error.not_open` | Codes are not open yet. | الأكواد لسه ما اتفتحتش. |
| `promo.error.own_referral` | You cannot use your own invitation code. | ما تقدرش تستخدم كود الدعوة بتاعك. |
| `promo.error.referral_joined` | You have already joined with an invitation code. | إنت انضممت بكود دعوة قبل كده. |
| `promo.error.referral_full` | This invitation code has reached its limit. | كود الدعوة ده وصل للحد بتاعه. |
| `promo.error.device_limit` | This device has already been used with an invitation code. | الجهاز ده اتستخدم قبل كده مع كود دعوة. |
| `promo.error.locked` | The code cannot change once a buyer has asked for the piece. | الكود ما يتغيّرش بعد ما مشتري يطلب القطعة. |
| `promo.order.advance_paid` | Advance paid | السلفة اتدفعت |
| `promo.order.advance_note` | {amount} reached your wallet when IGI confirmed your piece. The rest follows when the buyer pays. | {amount} وصلوا محفظتك لما IGI أكدت القطعة. الباقي بيوصل لما المشتري يدفع. |
| `promo.order.paid_earlier` | Paid earlier | اتدفع بدري |
| `promo.order.commission_saved` | You saved {amount} on the commission | وفّرت {amount} من العمولة |
| `promo.order.commission_with_code` | with code {code} | بكود {code} |
| `promo.order.commission_with_credit` | with your commission credit | برصيد العمولة بتاعك |
| `referral.menu_sub` | Both of you pay less commission | إنتوا الاتنين بتدفعوا عمولة أقل |
| `referral.title` | Invite a friend | ادعو صاحبك |
| `referral.intro` | She pays {referral_friend_pct}% less commission on her first sale. You get {referral_reward_egp} EGP off yours. | هي بتدفع عمولة أقل {referral_friend_pct}% على أول بيعة. وإنت بتاخد {referral_reward_egp} جنيه خصم من عمولتك. |
| `referral.your_code` | Your code | الكود بتاعك |
| `referral.share` | Share | شارك |
| `referral.copy` | Copy | انسخ |
| `referral.copied` | Copied. | اتنسخ. |
| `referral.share_ready` | Ready to share. | جاهز للمشاركة. |
| `referral.share_message` | Sell your gold on Dahab with my code {code} and pay less commission on your first sale. | بيع دهبك على دهب بكودي {code} وادفع عمولة أقل على أول بيعة. |
| `referral.stands` | Where it stands | موقفك دلوقتي |
| `referral.invites_sent` | Invites sent | الدعوات المبعوتة |
| `referral.joined` | Joined | انضموا |
| `referral.sold` | Sold something | باعوا |
| `referral.earned` | Earned so far | اللي كسبته لحد دلوقتي |
| `referral.available` | Available now | المتاح دلوقتي |
| `referral.expires` | Expires {date} | بينتهي {date} |
| `referral.reward_note` | The reward lands as commission credit after your friend completes her first sale, not when she signs up. It lasts {referral_expiry_days} days. | المكافأة بتوصل رصيد عمولة بعد ما صاحبك تكمّل أول بيعة، مش لما تسجّل. وبتفضل {referral_expiry_days} يوم. |
| `referral.not_available` | Invitations are not open yet. | الدعوات لسه ما اتفتحتش. |
| `referral.load_failed` | Your invitation details could not be loaded. | مقدرناش نحمّل تفاصيل الدعوة. |
| `referral.rate_card_title` | Know someone with gold in a drawer? | تعرف حد عنده دهب في الدرج؟ |
| `referral.rate_card_body` | Share your code. She pays less commission on her first sale, and so do you on your next. | شارك كودك. هي بتدفع عمولة أقل على أول بيعة، وإنت كمان على اللي بعدها. |
| `referral.rate_card_button` | See my code | شوف كودي |

Customer messages (kind `notification`, catalogue `CatalogueData`, golden fixture, SMS + email + inbox):

| Key | English | Arabic (AR-DRAFT) |
|---|---|---|
| `order.advance_paid.sms` | {amount} reached your wallet before the buyer pays, for ({piece}). Order {order_ref}. | {amount} وصلوا محفظتك قبل ما المشتري يدفع، عن ({piece}). طلب {order_ref}. |
| `order.advance_paid.mail_subject` | Your first sale is paid ahead | بيعتك الأولى اتدفعت بدري |
| `order.advance_unavailable.sms` | We could not pay order {order_ref} ahead this time. You are paid when the buyer pays. | ما قدرناش ندفع طلب {order_ref} بدري المرة دي. هتتدفعلك لما المشتري يدفع. |
| `referral.reward_earned.sms` | Your friend completed a sale. {amount} of commission credit was added to your account. | صاحبك كمّل بيعة. اتضاف {amount} رصيد عمولة لحسابك. |
| `referral.reward_earned.mail_subject` | You earned a commission credit | كسبت رصيد عمولة |

## 9. Indexes, retention, sizes

`promo_code_use` stays small (one row per redemption attempt). `commission_credit_event` grows with sales. `listing_view` is the only high-volume table (one row per viewer per piece per day) and is pruned daily after 90 days (setting 16). No table here is read on a hot customer path except `commission_credit` (indexed by `customer_id, status` and `listing_id`) in the settlement and the preview.
