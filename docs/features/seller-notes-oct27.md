# Seller notes of 2026-10-27

The owner's testing notes on selling a piece, from the phone (screenshots of the piece page and My listings).

## What was asked

1. The seller's video does not play on the piece page, although staff see it.
2. A piece waiting for review shows no photo, and has no *Change making charge*: the seller wants to change it before approval.
3. My listings shows a placeholder instead of the piece's photo.
4. Buyers should see how many times the seller has sold.
5. The first-sale advance: a separate place showing what was paid ahead, to whom, on which order, how much room is left, who paid and who did not.

## Impact

```
Backend:          YES — price change in review, seller_sales_count, advance rows
Database:         YES — migration 2026_10_28_000020 (functions only, no table)
API:              YES — non-breaking (optional fields; one more state accepted)
Dashboard:        YES — First-sale advances page
Customer App:     YES — in-page video, thumbnails, sales count, photo retry
Auth:             NO
Permissions:      NO — the page uses first_sale.manage
API models/types: YES — AdvanceRow (Dashboard), PieceDetail (app)
```

## Backend

- `POST /customer/me/listings/{id}/price` also accepts a piece in `in_review` (the reviewer sees the new figure; the state, `state_changed_at` and the review stay). `can_change_price` is true in review. Guard DH023 (`listing_price_guard`, `listing_price_recorded`) allows `in_review` and requires the history row there as for `live`. Other states still answer `409 listing_not_live`.
- `GET /market/listings/{id}` has `seller_sales_count`: the seller's settled orders (`settlement_txn_id` set), read by the function `seller_sales_count(listing)` in the `system` scope; a count only, never who.
- `GET /dashboard/first-sale-advances` rows add `order_id`, `seller.customer_id`, `recovered_at` (the recovery entry's time) and `written_off_at`.

## How the advance ceiling works (unchanged)

- `payout.first_sale_cap_egp` (100,000): the most paid ahead on **one** sale — `min(seller's proceeds, cap)`. A piece worth more is still advanced, up to the cap; the rest comes at the buyer's payment.
- `payout.first_sale_exposure_ceiling_egp` (1,000,000): the most out at once **across every customer** — the sum of advances still `outstanding`. Above it, a new advance is skipped (the seller is paid at the buyer's payment as usual).
- The room comes back on its own: when the buyer pays, the advance becomes `recovered` and leaves the sum; a written-off one leaves it too (as a loss). To give more room, raise the ceiling setting in Platform settings.

## Dashboard

*First-sale advances* (Money menu, `first_sale.manage`): room left, paid ahead and not yet recovered, ceiling, cap per sale, written off, absorbed; every advance with its order and seller (links), amount, date and where it stands (waiting for the buyer / recovered on … / written off on …, why); filters All / Waiting for the buyer / Recovered / Written off. The Platform settings panel links to it.

## Customer app

- The video is the gallery's last slide and *Watch the video* opens it in a window; both play inside the page from the downloaded file (the media endpoints have no byte ranges, which Safari needs to play a video from an address).
- My listings shows the piece's first photo.
- A photo that fails to load is asked for once more before the placeholder stays.
- The piece page shows buyers "Verified seller" with "Has completed N sales on Dahab" (or "New on Dahab"). Texts `piece.seller_*`, `piece.video_*` (Arabic AR-DRAFT).
- *Change making charge* shows on a piece in review (it follows `can_change_price`).

## Not done

- Why the in-review piece showed no photo was not confirmed: the server code serves the owner's photos the same in review as live. Reading the listing's files on the server was not allowed from this session; the retry above covers a failed load only.
