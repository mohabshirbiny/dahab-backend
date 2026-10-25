# Promo codes by product and side

Status: built in all three projects (2026-10-25), nothing committed.
Market makers are untouched: their kind, queue, approvals and settlement work as before.

## Rule

A promo code names **a product** (`product_type`: `gold` · `diamond` · `gold_with_diamond`) and **a side**
(`side`: `seller` · `buyer`). Either side's code can be for anyone (`audience: anyone`, the default) or one
named account.

| | Seller | Buyer |
|---|---|---|
| **gold** | commission waived / reduced by a percentage / reduced by an amount | pays the seller's price: the positive spread is handed back; the deposit is unchanged |
| **diamond** | same | the deposit is reduced by a percentage or an amount |
| **gold_with_diamond** | same | same as diamond |

Why a buyer discount differs by product: only gold has a buy/sell spread (stones and gold-with-diamond are
priced with none, `PriceCalculator`), so on stones the buyer's gain is on the deposit.

## Data

- `promo_code`: `side` (derived from `kind` by trigger), `product_type`, `commission_amount_egp`,
  `deposit_mode` (`none|percent|amount`), `deposit_percent`, `deposit_amount_egp`. New kind `buyer`.
  `commission_mode` gains `amount`. All terms immutable (guard DH020, extended).
- `buy_request`: `promo_waives_commission`, `promo_gives_spread`, `deposit_discount_egp` — a snapshot of what
  the code does, because the settlement runs in the `order` scope which cannot read `promo_code`.
  Existing market-maker requests are backfilled with both flags on.

## Behaviour

- **Create** (`POST /dashboard/promo-codes`, additive, non-breaking): `side`, `product_type`,
  `commission_amount_egp`, `deposit_percent`, `deposit_amount_egp`, `kind: buyer`. A buyer code needs
  `product_type`; so does a seller code when `side` is sent (the older body without `side` still creates a
  code that covers every product). Gold buyer: no deposit fields; stones buyer: exactly one deposit field.
- **Seller** (`SellerCodeGate`): a code of another product is refused `promo_code_invalid` /
  `wrong_product`; an `amount` code becomes an `amount` commission credit (`CommissionWaivers` already
  supported it); buyer and dealer codes are `unknown` to a seller.
- **Buyer** (`PromoCodeGate`, on `POST /customer/me/buy-requests` `promo_code`): `inactive`, `not_your_code`
  (account codes), `wrong_product`, `not_started`, `ended`, `already_used`, `cap_reached`. A buyer code does
  not need the piece approved or aged (those stay dealer terms).
- **Deposit** (`DepositRule::discounted`): percent or amount off the deposit, never below 1 EGP (the ledger
  cannot hold zero; `deposit_positive`). The discount is stored in `deposit_discount_egp`.
- **Settlement**: the spread is handed back when `promo_gives_spread` (gold buyer code, and dealer codes as
  before); the seller's commission is waived only when `promo_waives_commission` (dealer codes). The seller's
  own code is a credit as before.

## Class

Non-breaking additive API (new optional request fields, new response fields `side`, `product_type`,
`what_it_does.deposit`, `commission.amount_egp`), new `kind` value `buyer` and new `commission.mode` value
`amount` (potentially breaking for exhaustive UI maps), new refusal reason `wrong_product`.

## Consumers

- Dashboard: New-code form (`src/components/promo/NewCodePanel.vue`), `src/composables/useMarketMakers.ts`
  types/body — add side, product, amount, deposit fields; show the new kind and `amount` mode.
- Customer App: the buy flow shows the promo field only to market-maker accounts; a buyer code is for any
  buyer, so the field must be offered to everyone, with the deposit shown after the code and the new
  `wrong_product` reason handled.

## Known gaps

- The market-maker cost panel counts dealer sales only; the spread a gold buyer code gives up is recorded on
  the order (`spread_given_up`) but not summed anywhere yet.
- `buy_request_guard` does not freeze the three new columns (the function is not redefined here).
