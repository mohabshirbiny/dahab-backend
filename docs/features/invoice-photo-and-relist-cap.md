# Invoice photo, price-difference label and the free-relist fee cap

Two owner notes, 2026-10-25.

## 1. Invoices

- The buyer's gold invoice names the gap between the buyer's and the seller's rates **Price difference (spread)** /
  **فرق السعر** instead of "Commission" (PDF, the customer app, the Dashboard invoice detail). Figures, VAT 0 and the
  totals are unchanged; the seller's commission lines are untouched.
- Both invoices (`-S` and `-B`) carry the piece's first public photo. `InvoiceLines` snapshots `lines.photo_media_id`
  (the media id only, never the stored object's name) at issue. The PDF renderer reads the file and embeds it; when the
  file cannot be read the PDF is made without it. Invoices issued before this change have no photo.
- The customer app shows the same photo on the invoice screen through the order's media route
  (`GET /customer/me/orders/{order}/media/{media}`). The Dashboard shows no photo (no staff route by order).

## 2. Free relist

- The new making charge per gram may not be higher than the one on the sale (the origin listing's
  `making_charge_per_g`); equal or lower is accepted. Higher → `422 validation_failed` on `making_charge_per_g`.
  Stones and gold with a stone (an asking price) are not capped.
- `free_relist.previous_making_charge_per_g` on the customer order (gold, open offer; else `null`) lets the app write
  the old fee on the form and check before sending. The Backend stays the authority.

Classification: non-breaking (one optional response field and a `lines` key; a new validation rule on an existing
endpoint, potentially breaking only for a client that sent a higher fee, which the app now prevents).
