# Certificate fee at settlement (package D)

**What.** The seller of a stone piece (diamond, gold with diamond) names the certificate they bring
(`listing.certificate_kind`: `igi` | `other_lab` | `none`). Its cost is the setting
`certificate.igi_cost_egp | other_lab_cost_egp | none_cost_egp` (Rates page; 0 / 1000 / 2000 demo values).
The cost is locked on `"order".certificate_cost` at acceptance and taken from the **seller's proceeds only**
at pay-balance, in the same `balance_payment` entry, onto the new internal account `igi_certificates`.
The buyer's total, the commission and the VAT do not change. A listing or order without the value
(everything before this, and all gold) pays nothing.

**Where it shows.**
- API: `certificate_kind`, `certificate_fee` on the listing (`you_would_receive` is already net); `certificate` param on
  `GET /reference/quote` (+ `certificate_fee`, net `payout`) and `POST /customer/me/commission-preview` (+ `certificate_fee_egp`);
  `certificate_fee` on the seller's order and on the staff order `settlement` (+ `certificate_cost`, `certificate_kind`);
  `certificate_fee` / `certificate_kind` in the seller invoice lines and PDF (informational, not a Dahab charge, no VAT).
- Dahab wallet statement: a `certificate` column per row and `certificate_in|out|certificates_payable` in the summary,
  beside the VAT — held for IGI, never in the balance (the entry also makes a row appear).
- Dashboard: order settlement row, listing review row, invoice row, statement column and card.
- Flutter: sell form sends `certificate_kind`, quote/preview carry it, "IGI certificate" row in the estimate,
  the seller's order and invoice show the fee.

**Classification.** Non-breaking (optional request field, new response fields, new CSV columns). One new
`account_kind` value (`igi_certificates`), migration `2026_10_25_000020_certificate_fees`.

**Not done.** Paying IGI out of `igi_certificates` (no operation yet); the fee is not refunded on a cancelled sale
because it is only taken at payment.
