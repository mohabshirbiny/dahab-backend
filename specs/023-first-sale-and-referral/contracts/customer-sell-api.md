# API contract: customer side of spec 023 — codes, preview, advance (planning artefact)

Envelope as `docs/platform/api-contract.md`. All routes under `/api/v1/customer/me` need the customer token and `customer.gate:trade` (verified, not suspended, not closed) unless said. POST / PUT / DELETE need `Idempotency-Key`. The Customer app is the only consumer (`lib/services/api/*`, `lib/services/sell_draft.dart`, `lib/features/sell/*`, `lib/features/orders/*`); the current mock `ContentRepository.promo()` calls nothing.

## 1. `POST /customer/me/commission-preview` — new

Stateless: records nothing, spends nothing. Throttle `customer.promo` (10 / minute per customer, from `config/dahab-promo.php`). Not `idempotent` (safe).

```json
{ "category": "gold", "karat": 21, "weight_g": "10.000",
  "making_charge_per_g": "300.00", "asking_price": null, "code": "WELCOME" }
```

`category` and the piece figures follow the listing creation rules (`ListingRules`): gold ⇒ `karat`, `weight_g`, `making_charge_per_g`; diamond ⇒ `asking_price`; gold with diamond ⇒ all. `code` is optional (without it the answer shows the credits already in the seller's pool).

### Answer `200`

```json
{ "data": {
  "code": { "value": "WELCOME", "kind": "discount", "source": "promo_code",
            "commission": { "mode": "percent", "percent": "50.0000" },
            "first_sale_only": true, "pays_advance": false } ,
  "waiver": { "kind": "credit", "source": "promo_code", "credit_id": null },
  "commission": { "ordinary_egp": "600.0000", "charged_egp": "300.0000", "vat_egp": "42.0000",
                  "saved_egp": "300.0000", "minimum_egp": "200.0000", "floor_applied": true },
  "you_receive": { "without_promo_egp": "54684.7500", "with_promo_egp": "54984.7500" },
  "pays_advance": { "available": true, "cap_egp": "100000.0000", "reason": null },
  "indicative": true
} }
```

- `code` is null when none was sent; `source` ∈ `promo_code | referral` (a referral code answers `kind: "referral"`, `commission` = the friend's reward).
- `waiver.kind` = what the **settlement would apply today**: `none`, or `credit` (the lowest of the code's credit and the pool's credits — never a sum, research R3). `credit_id` is null for a code not yet recorded.
- `commission.*` are the figures of the spec 005 calculator with the resolver applied; `floor_applied` tells whether the minimum limited the saving ("The 200 EGP minimum still applies" is shown when true and `saved_egp` is lower than the code promises).
- `you_receive.*` are indicative; the settlement recomputes on the IGI weight and the locked rates (as `GET /reference/quote`).
- `pays_advance` is null unless the code pays the advance or the seller has a staff enablement. `available:false` carries `reason` ∈ `switch_off | ceiling | not_first_gold | not_gold | seller_not_eligible` (the app words each).

### Refusals

| Status · code | When |
|---|---|
| 401, 403 `verification_required \| account_suspended \| account_closed` | the trade gate |
| 422 `promo_code_invalid` | `details.reason` ∈ `unknown`, `not_open`, `not_started`, `ended`, `first_sale_only`, `already_used`, `cap_reached`, `own_referral`, `referral_joined`, `referral_full`, `device_limit` (research R7, R22). An account-tied code of someone else, an inactive code and a missing code are all `unknown` |
| 422 `validation_failed` | the piece figures |
| 429 `too_many_requests` | the limiter |

## 2. `PUT /customer/me/listings/{listing}/promo` and `DELETE /customer/me/listings/{listing}/promo` — new

Trade gate, `throttle:customer.listings`, `idempotent`. Body of PUT `{ "code": "WELCOME" }` (a promo code or a referral code). `{listing}` is the caller's own (404 otherwise).

- **PUT** redeems the code for this listing in one transaction (research R6): the gate of R7 under the code's row lock, one allowed `promo_code_use`, one bound `commission_credit` (or, for a referral code, the `referral` row and the friend's credit), `listing_promo` row, audit `promo.applied`. Replacing an existing code voids the earlier credit (`promo.removed`) and applies the new one atomically. `200` returns the seller's `CustomerListing` (§4).
- **DELETE** voids the credit and removes the row (`promo.removed`). `200` returns the listing; `404 promo_not_applied` if none.
- Refusals: `409 listing_promo_locked` (a buyer is in line, or the listing is not in `draft`/`changes_requested`/`in_review`/`live`), `422 promo_code_invalid` (as §1), `404 not_found`, `409 platform_stopped` only for `PUT` under "stop everything" (the same rule as other listing writes), `idempotency_*` from the middleware.

## 3. `POST /customer/me/listings` — changed (optional field)

`StoreListingRequest` gains optional `promo_code`. When present it is redeemed as in §2 in the same transaction as the draft; a failed code fails the creation with `422 promo_code_invalid` and nothing is created. Without it, nothing changes. `UpdateListingRequest` is **unchanged** (a code is changed only through §2).

## 4. `CustomerListing` — changed (additive)

```json
"promo": { "code": "WELCOME", "kind": "discount", "source": "promo_code",
           "commission": { "mode": "percent", "percent": "50.0000" },
           "pays_advance": false, "status": "active", "can_change": true } ,
"pays_advance": { "available": true, "cap_egp": "100000.0000", "reason": null }
```

`promo` null when none. `status` ∈ `active | spent | voided`; `can_change` is false once a buyer is in line. `pays_advance` null unless the code or an enablement gives it. `you_would_receive` and `current_price` are unchanged (the ordinary quote; the saving is in the preview, so the list stays one query).

## 5. `CustomerOrder` (seller) — changed (additive)

For the seller only (null for the buyer):

```json
"advance": { "amount_egp": "52000.0000", "status": "outstanding", "paid_at": "…",
             "recovered_egp": null, "shortfall_egp": null },
"commission": { "recorded": true, "ordinary_egp": "600.0000", "charged_egp": "300.0000", "saved_egp": "300.0000",
                "waiver": "credit", "code": "WELCOME" }
```

`commission.recorded` is false (and the numbers null) before settlement or for orders settled before this spec. `waiver` ∈ `none | free_relist | market_maker | credit` (the seller sees `market_maker` only as "no commission"; the label does not reveal the dealer). The existing spec 018 `no_fee` stays. `advance.status` `written_off` is shown to the seller as "kept" (no wording of Dahab's loss).

## 6. Wallet — no change

The movement kind `first_sale_payout` already exists in `GET /customer/me/wallet/movements` and the app already labels it (`shared.first_sale_paid_early`). The settlement shows the seller's remainder as the usual `settlement_seller`/`balance_payment` movement; the equity legs are not customer-visible.

## 7. `GET /customer/me/orders/{order}` and the inbox — events

New order notices (`OrderEvent::ADVANCE_PAID`, `ADVANCE_UNAVAILABLE`) go out after commit through the template catalogue (SMS + email + inbox); the inbox row links to the order. Details: `data-model.md` §8.

## 8. Classification

| Change | Class |
|---|---|
| New endpoints (preview, PUT/DELETE promo) | non-breaking |
| Optional `promo_code` on listing creation; additive fields on listing and order | non-breaking |
| New values of `promo_code_invalid.details.reason` | potentially breaking only for a client that exhaustively maps reasons; the app maps unknown to *not valid* |
