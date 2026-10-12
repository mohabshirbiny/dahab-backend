# First-sale advance, customer-side codes, referral, and the Promo codes / Market maker approvals pages

> File: `docs/features/first-sale-and-referral.md` · Branch: `feature/first-sale-and-referral` in Backend, Dashboard and Customer app
> Status: draft (planned, nothing built) · Date: 2026-10-12 · Spec: `specs/023-first-sale-and-referral/`

## Goal

Four things the product promised and the platform did not do:

1. **Promo codes** staff can create exactly as the Dashboard design draws them, for dealers (spec 019), for any seller (percentage off or no commission) and for the first gold sale.
2. A **commission credit** — one mechanism, not wallet money — that the percentage codes and the referral share, spent against a later commission, never summed with another waiver, never below the minimum commission.
3. The **first-sale advance** of Technical Spec Part 3 §6: a first gold seller is paid when IGI passes the piece, Dahab recovers it at the buyer's payment, and writes it off if the buyer never pays — behind a switch that is off until Finance signs.
4. **Invite a friend**, and a **Market maker approvals** page with views, files, *Against the guide* and a cost panel made of stored figures.

Owner's rules (2026-10-12, `spec.md`): the design wins where nothing else is written; every figure is a setting with the design's value as default. Finance still signs D23-5 (ledger, ceiling, write-off rule, VAT on a reduced commission) before the three switches are turned on.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES
Dashboard:    YES
Customer App: YES
Auth:         NO
Permissions:  YES
```

## Backend Impact

- **New**: `CommissionWaivers` resolver; `SpendCommissionCreditAction`, `ApplyListingPromoAction`, `RemoveListingPromoAction`, `PreviewCommissionAction`, `VoidListingCreditsAction`, `ExpireCommissionCreditsAction`; `AdvanceFirstSaleAction`, `WriteOffFirstSaleAdvanceAction`, `EnableFirstSaleAction`, `RevokeFirstSaleAction`, `ListFirstSaleAdvancesAction`; `ShowReferralAction`, `CountShareAction`, `JoinReferralAction`, `GrantReferralRewardAction`; `LeaveMarketMakerQueueAction`, `ReadQueueMediaAction`, `MarketMakerCostAction`; `SellerCodeGate`, `CustomerIdKey`; jobs `RecordListingViewJob`, `GrantReferralRewardJob`; commands `commission-credits:expire`, `referrals:reward-pending`, `listing-views:prune`; controllers `FirstSaleController`, `PromoController`, `ReferralController`; models/enums/factories of data-model §5; limiter `customer.promo`; database scope `promo`.
- **Changed**: `OrderSettlement` (resolver, advance reconciliation, cost columns), `PayBalanceAction`, `RecordInspectionResultAction`, `DecideAdjustmentAction`, `MovesOrder` (write-off hook), `MovesListing` (void credits), `ForfeitDepositAction` (offset setting), `PromoCodeGate`, `CreatePromoCodeAction`, `ListMarketMakerQueueAction`, `MarketMakerController`, `ReviewIdentityDocumentAction`, `StoreListingRequest`/`CreateListingAction`, customer and staff Resources, `MarketListingController@show`, `SettingKey`, `StaffPermission`, `OrderEvent`, `AuditEvent`, `SettingPlaceholders`, the template catalogue, `DomainApiException` (`promo_kind_not_available` removed), `bootstrap/app.php`, routes, Postman.
- **Not changed**: the ledger (no new account, no new event kind), `PriceCalculator`, `IssueTaxInvoices`, `InvoiceLines`, the DH012 reconciliation, the spec 022 guard (only its function is extended).

## Database Impact

Four migrations (`2026_10_16_0000{10,20,30,40}`), mirrored in `docs/Database schema/`; SQLSTATE DH024–DH027:

- `promo_code` terms (audience, commission mode and percent, use limit, first-sale-only, pays-advance, age, approval, dates), the widened `kind`, spec 019 rows backfilled to their old meaning; `promo_code_use` + listing/ID key; `identity_document.id_number_hash`; 16 settings.
- `commission_credit` + append-only `commission_credit_event`, `listing_promo`, `referral_code`, `referral`, eight cost columns on `"order"` with the minimum-commission CHECK, the `promo` scope policies, four integer-only functions.
- `first_sale_enablement`, `first_sale_advance` (one per seller, ever), and `listing_price_locked()` extended.
- `listing_view` (one row per viewer per piece per day, hashed, pruned).
- Rollback refuses once credits, referrals, advances or stored cost figures exist.

## API Changes

11 new endpoints, ~10 additive changes, nothing breaking. Full contracts: `specs/023-first-sale-and-referral/contracts/`.

| Endpoint | Change | Class |
|---|---|---|
| `GET|POST /dashboard/promo-codes`, `GET …/{code}/uses` | new terms and shapes; old body still works | Non-breaking |
| `POST /customer/me/commission-preview` | new | Non-breaking |
| `PUT|DELETE /customer/me/listings/{l}/promo`; `promo_code` on `POST /customer/me/listings` | new / optional field | Non-breaking |
| `GET /customer/me/referral`, `POST …/referral/shares`, `GET …/commission-credits` | new | Non-breaking |
| `GET /dashboard/market-maker/queue`, `…/cost` | additive fields | Non-breaking |
| `POST …/queue/{listing}/leave`, `GET …/queue/{listing}/media/{media}` | new | Non-breaking |
| `POST|DELETE /dashboard/customers/{c}/first-sale-advance`, `GET /dashboard/first-sale-advances` | new (`first_sale.manage`) | Non-breaking |
| identity review `id_number`; seller order `advance`/`commission`; listing `promo`/`pays_advance`; staff order blocks; Customer file `first_sale` | additive | Non-breaking |
| `kind` in the codes list gains `first_sale`, `discount`; `promo_kind_not_available` gone | enum addition / removed unused code | Potentially breaking only for exhaustive maps |

## Dashboard Impact

- **Promo codes** rewritten to `p-promos`: the 7-column table, **Add a code** with the 11 designed fields (plus two flagged extras), the Uses panel. **Market maker approvals** rewritten to `p-mm`: info note, queue with views, files and guide, **Price is sound** / **Leave**, *What this costs*. The old *Approved* list is dropped.
- *Commission rates*: the 16 settings, the three switches, the exposure panel. Customer file: *First-sale advance* card. Identity review: *ID number*. Staff order detail: advance and commission blocks.
- Types, services, composables as in `plan.md`; permission `first_sale.manage`.

## Customer App Impact

- One `PromoCard` on the sell form (both screens), `SellDraft.promoCode`, the estimate and "when your money reaches you" from the preview; seller order screen shows the advance and the commission saved; *Invite a friend* real; `mockPromoCodes`, `MockContentRepository.promo()`, `PromoCode`, the `MockMark` and `R.invite` as mock removed.
- Keys `promo.*` and `referral.*` (Arabic AR-DRAFT); manifest copied to the Backend `database/data/app_text_keys.json`; fake backend and flow tests.

## Authentication / Authorization

Customer token and `customer.gate:trade` for the sell side; the referral screen needs only sign-in. Staff surfaces under `auth:staff` with the permissions below. Row-level security: a new non-elevated `promo` scope; the settlement's `order` scope reads and spends the **seller's** credit of the order it is a party to; customers never read the use log, the views or the enablements.

## Permissions

New: **`first_sale.manage`** (Finance; the CEO holds every code). Reused: `promo.manage`, `market_maker.approve` (queue, leave, queue media, cost), `pricing.rates.manage` and `settings.manage` (the 16 settings by group), `identity.review` (the ID number).

## Validation

Backend (authoritative): the code terms and kind inference; the seller-code gate (switch, active, audience, dates, first-sale-only, use limit by ID, cap); the advance eligibility; the minimum commission (code and database CHECK); the ID number format. Dashboard and app mirror lengths and required fields for UX only and never decide a rule.

## Error Handling

New `code`s: `promo_account_not_found`, `promo_not_applied`, `listing_promo_locked`, `first_sale_not_eligible`, `first_sale_enablement_open`, `first_sale_enablement_not_found`, `referral_not_open`; `promo_code_invalid` gains seller-side reasons. The app words each reason with a `promo.error.*` key; unknown reasons read "not valid".

## UI States

Promo card: idle, checking, applied (with the saving and the minimum note), refused (reason), removed. Invite screen: loading, off ("Invitations are not open yet."), on, error. Order screen: advance paid / not available. Dashboard pages: loading, empty, error, permission-denied, and *not recorded* for earlier sales on the cost panel.

## Testing

Pest: migration, RLS, resolver unit, redemption, preview, settlement (ledger sums, invoice reconciliation, floor), advance and its settlement, referral, market-maker page; Dashboard type-check/lint/build; Flutter analyze/test/build web; the manual steps of `specs/023-first-sale-and-referral/quickstart.md`.

## Breaking Changes

None. The only removal is the `promo_kind_not_available` code, which no client handled.

## Migration / Compatibility

Deploy Backend first (nothing changes for customers: the three switches are off; the market-maker flow behaves as before). The Dashboard pages and the app can ship after. Rollback is refused once credits, referrals, advances or stored cost figures exist (second reviewer in the PR). Finance's D23-5 sign-off is a deployment gate for the switches, not for the build. Open planning decisions P23-1…11 are in `research.md` R31.
