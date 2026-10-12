# Error codes, SQLSTATE, audit events, permissions, idempotency, throttles (spec 023)

Planning artefact. Implementation adds each code as a `DomainApiException` factory with a customer-safe or staff-safe English message, to the catalogue test, and to `docs/platform/api-contract.md`.

## 1. API error codes

| Code | HTTP | When | New? |
|---|---|---|---|
| `promo_code_invalid` | 422 | a code cannot be used; `details.reason` below | existing code, new reasons |
| `promo_code_exists` | 409 | the code (or a referral code) already exists | existing |
| `promo_customer_not_market_maker` | 422 | a market-maker tie to a customer who is not one | existing |
| `promo_account_not_found` | 422 | `tied_to` matches no customer | new |
| `promo_not_applied` | 404 | `DELETE …/promo` on a listing with none | new |
| `listing_promo_locked` | 409 | the code cannot change once a buyer is in line / wrong state (DH027 maps here) | new |
| `first_sale_not_eligible` | 409 | staff enablement for a seller who already had an advance | new |
| `first_sale_enablement_open` | 409 | an enablement is already open for the seller | new |
| `first_sale_enablement_not_found` | 404 | revoke with none open | new |
| `referral_not_open` | 409 | `referral.enabled` is false | new |
| `commission_credit_immutable` | 409 | DH024 reached through the API (should not be) | new (mapping only) |
| `first_sale_advance_immutable` | 409 | DH025 | new (mapping only) |
| `settlement_terms_mismatch` | 409 | DH026 deferred check failed: the waiver and the credit event disagree; the payment rolls back | new (mapping only) |
| `listing_price_locked` | 409 | spec 022; now also true after an advance | existing |
| `promo_kind_not_available` | — | **removed** (never returned; factory deleted with its tests) | removed |

`promo_code_invalid.details.reason` — seller side: `unknown`, `not_open`, `not_started`, `ended`, `first_sale_only`, `already_used`, `cap_reached`, `own_referral`, `referral_joined`, `referral_full`, `device_limit`. Buy side (spec 019, unchanged): `inactive`, `not_your_code`, `not_approved`, `too_new`, `cap_reached`; only the approval and age reasons now follow the code's own terms and the two settings (research R21).

## 2. SQLSTATE (guards)

| SQLSTATE | Meaning | Mapped by `bootstrap/app.php` to |
|---|---|---|
| DH020 | spec 019; **extended**: the new `promo_code` terms are immutable | `promo_history_immutable` (existing) |
| **DH024** | `commission_credit` counters/status move forward only, each move has its event in the same txid; events and `referral` rows are append-only / forward-only | `commission_credit_immutable` |
| **DH025** | `first_sale_advance` and `first_sale_enablement` change once, forward, never deleted | `first_sale_advance_immutable` |
| **DH026** | a `credit` waiver on an order has exactly one matching `spent` event (deferred) | `settlement_terms_mismatch` |
| **DH027** | `listing_promo` outside its allowed states; `listing_view` rows are never edited, deleted only past the retention | `listing_promo_locked` / internal |
| DH012 | spec 016 invoice reconciliation — **unchanged** (research R5) | existing |
| DH023 | spec 022 price guard — **unchanged**; `listing_price_locked()` is extended | existing |

Table CHECKs (not SQLSTATE codes): the minimum-commission floor and the settlement-cost shape on `"order"` (R4, R17); the term rules on `promo_code`; the amount rules on credits and advances.

## 3. Audit events (`AuditEvent`, `RecordAuditLogAction`; actor named on every row)

| Event | Actor | Payload highlights |
|---|---|---|
| `promo.created` (extended) | staff | every term |
| `promo.updated` | staff | cap, on/off, before |
| `promo.applied` | customer | listing, code, kind, credit id, `id_key_kind` (`id`/`account`), never the key |
| `promo.removed` | customer / system | listing, code, reason |
| `commission_credit.granted` | customer / system | credit, source, kind, figures |
| `commission_credit.spent` | customer (buyer, order scope) | order, ordinary, charged, reduction |
| `commission_credit.expired` / `.voided` | system / customer / staff | credit, reason |
| `referral.joined` | customer | inviter, friend, device hash present |
| `referral.rewarded` | system | referral, order, credit |
| `referral.void` | system | reason |
| `first_sale.enabled` / `.revoked` | staff | seller, reason |
| `first_sale.advanced` | system or the enabling staff | order, amount, cap, basis, txn |
| `first_sale.skipped` | system | order, reason (`switch_off`, `ceiling`, `not_eligible`, `suspended`) |
| `first_sale.recovered` | customer (buyer, order scope) | order, recovered, shortfall |
| `first_sale.written_off` | system / staff | order, amount, reason |
| `market_maker.left` | staff | listing, note, figures seen |
| `identity.reviewed` (extended) | staff | `id_number_recorded: true` |
| `setting.changed` (existing) | staff | the 16 new keys |

The audit presenter masks nothing new (no ID number, no hash). `tests/Unit/Audit/AuditCatalogueTest.php` lists every case with a label and a category.

## 4. Permissions

| Permission | New? | Use | Seeded to |
|---|---|---|---|
| `first_sale.manage` | **new** | enable/revoke the advance for a seller; the exposure panel and list | Finance (the CEO holds every code) |
| `promo.manage`, `market_maker.approve` | existing | codes, uses, cost; approvals, queue, leave, queue media | Finance (unchanged) |
| `pricing.rates.manage`, `settings.manage` | existing | the new settings by group | unchanged |
| `identity.review` | existing | the optional `id_number` | unchanged |

The new permission is added to `StaffPermission` (label, group "Market makers"), `seedRoles()`, the permission catalogue test and `docs/Technical Spec/dahab-dashboard-authorization.md`'s table.

## 5. Idempotency and throttles

| Route | `idempotent` | Throttle |
|---|---|---|
| `POST /customer/me/commission-preview` | no (read-only) | `customer.promo` (new limiter, `config/dahab-promo.php`: `promo_checks_per_minute` 10) |
| `PUT|DELETE /customer/me/listings/{l}/promo` | yes | `customer.listings` (shared) |
| `POST /customer/me/referral/shares` | yes | `customer.promo` |
| `POST /customer/me/listings` with `promo_code` | yes (existing) | `customer.listings` |
| all staff POST/PATCH/DELETE in `staff-promo-api.md` | yes | default `api` limiter |

## 6. Classification summary

Nothing in this spec removes or renames a field, a URL, a method or an error code that a consumer uses, and nothing changes the envelope. The only removal is the `promo_kind_not_available` code, which no client handled. **Overall: non-breaking, with the potentially-breaking enum additions listed in the two contracts.**
