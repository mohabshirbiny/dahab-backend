# API contract: referral and commission credits (spec 023, Story 4) — planning artefact

Envelope and rules as `customer-sell-api.md`. Under `/api/v1/customer/me`; trade gate except where noted.

## 1. `GET /customer/me/referral` — new

Any signed-in customer (the Invite screen is reachable as soon as the customer can sign in; the *code* is created on the first read). Not throttled beyond the default.

```json
{ "data": {
  "enabled": true,
  "code": "K7QH3M9T",
  "invites_sent": 3, "joined": 1, "sold": 1,
  "earned_egp": "200.0000", "used_egp": "0.0000", "available_egp": "200.0000",
  "available_until": "2027-01-10T23:59:59+02:00",
  "terms": { "friend_percent_off": "50.0000", "friend_sales_covered": 1,
             "inviter_reward_egp": "200.0000", "credit_expiry_days": 90, "min_friend_sale_egp": "5000.0000" }
} }
```

- `enabled` mirrors `referral.enabled`. When false the answer has `code: null` and zero counters, and the app hides *Invite a friend*. Nothing is created while the switch is off.
- `invites_sent` = Share/Copy taps counted (capped by `referral.max_invitations_per_account`, P23-5); `joined` = the inviter's referrals not `void`; `sold` = `rewarded`.
- `earned_egp` = sum of granted `amount` credits from referral; `used_egp` = sum of their `reduction_egp`; `available_egp` = sum of `amount_left_egp` of active, unexpired ones; `available_until` = the earliest expiry among them.
- `terms` are the live settings (the screen's words use the `{referral_*}` text placeholders instead; the numbers are here for any screen that needs them).

## 2. `POST /customer/me/referral/shares` — new

`idempotent`. No body. Counts one Share/Copy tap (up to the setting). `200 { "data": { "invites_sent": 4 } }`. When `referral.enabled` is false: `409 referral_not_open`.

## 3. `GET /customer/me/commission-credits` — new

Any signed-in customer. Newest first, active ones first, max 100.

```json
{ "data": [ { "credit_id": "…", "source": "referral_inviter", "kind": "amount",
              "percent_off": null, "amount_egp": "200.0000", "amount_left_egp": "200.0000",
              "uses_left": null, "listing_id": null, "expires_at": "…", "status": "active" } ] }
```

A credit is **not money**: it is never in `GET /customer/me/wallet*`, cannot be withdrawn or transferred, and is used only by lowering the commission of a settlement (research R2).

## 4. The friend's side — no endpoint of its own

The friend types the inviter's code in the sell form's code field. The Backend resolves a code against promo codes first, then referral codes (`referral_lookup()`), so `POST /customer/me/commission-preview` and `PUT /customer/me/listings/{listing}/promo` handle it (`customer-sell-api.md` §1–§2): the preview answers `code.kind = "referral"`; recording creates the `referral` row (`joined`) and the friend's bound credit. Referral-specific refusals arrive as `promo_code_invalid` with `details.reason` `own_referral | referral_joined | referral_full | device_limit | not_open` (a code of a switched-off programme is `not_open`).

## 5. How the inviter is rewarded

Not an endpoint. When the friend's order settles (a sale of at least `referral.min_friend_sale_egp`), `PayBalanceAction` dispatches `GrantReferralRewardJob` after commit; the job (system actor) moves the referral to `rewarded`, grants the inviter one `amount` credit, audits `referral.rewarded` + `commission_credit.granted` and sends the inbox + SMS notice `referral.reward_earned`. `referrals:reward-pending` (every 5 minutes) heals a lost job. A friend whose sale is below the minimum, or whose credit expired unused and who never sells, leaves the referral `joined`; after `referral.credit_expiry_days` from the join the sweep marks it `void` (`expired`).

## 6. Staff

No staff endpoint reads referrals in this spec (nobody asked for one). The audit log carries `referral.joined / rewarded / void` and `commission_credit.*`; the audit viewer finds them by event and by customer. If staff need a referral list, it is a new request.

## 7. Classification

All new endpoints: non-breaking. No existing response changes in this file.
