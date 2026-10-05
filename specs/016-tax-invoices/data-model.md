# Data model: Tax invoices and credit notes (spec 016)

Mirrors `docs/Database schema/04_schema_market.sql` (`tax_invoice`, updated in the same change) and adds `credit_note`. Migration `2026_10_09_000010_create_tax_invoices.php`. All amounts `NUMERIC(18,4)` EGP.

## `ledger_event_kind` (+1 value)

`credit_note` — a credit note's refund of commission and VAT (research R6). Staff label "Invoice correction (credit note)"; customer label "Invoice correction".

## `tax_invoice` (schema table, extended)

| Column | Type | Rules |
|---|---|---|
| `invoice_id` | UUID PK | `gen_random_uuid()` |
| `invoice_no` | TEXT NOT NULL UNIQUE | **new** — `order_ref || '-S'` (seller) / `'-B'` (buyer) (R4) |
| `order_id` | UUID NOT NULL → `"order"` | |
| `party_role` | TEXT NOT NULL | `CHECK IN ('seller','buyer')` — the schema's `party_role` enum type was never built (compensation uses TEXT + CHECK); `UNIQUE (order_id, party_role)` |
| `customer_id` | UUID NOT NULL → `customer` | = the order's seller / buyer (DH012) |
| `net_amount` | NUMERIC NOT NULL | seller: `commission_amount`; buyer: `final_buyer_total`; `> 0` |
| `vat_amount` | NUMERIC NOT NULL | seller: `vat_amount`; buyer: 0; `>= 0` |
| `gross_amount` | NUMERIC NOT NULL | `= net + vat` (CHECK) |
| `vat_rate` | NUMERIC(6,3) NOT NULL | **new** — the `vat.pct` in force at settlement (seller), 0 (buyer) |
| `lines` | JSONB NOT NULL | **new** — snapshot (R3): `category`, `karat`, `karat_label`, `piece_type_id`, `piece_type_en`, `piece_type_ar`, `weight_g`, `unit_rate`, `gold_value`, `making_total`, `asking_price`, `subtotal` (seller gross / buyer total), `paid_to_wallet` (seller proceeds; seller only), `commission_pct` (seller) |
| `issuer` | JSONB NULL | **new** — Dahab's details copied from `config/dahab-invoices.php`; NULL until complete; set once (R5) |
| `party` | JSONB NULL | **new** — `{full_name, display_ref}`; set once by the document job (R5) |
| `eta_reference` | TEXT NULL | always NULL in this spec (FR-022) |
| `issued_at` | TIMESTAMPTZ NOT NULL | `clock_timestamp()` — the settlement moment |
| `storage_ref` | TEXT NULL | the encrypted PDF; set once |
| `document_at` | TIMESTAMPTZ NULL | **new** — when the PDF was stored; set with `storage_ref` |

Indexes: `(issued_at DESC, invoice_id)`, `(customer_id, issued_at DESC)`, `(order_id)`. Checks: `invoice_vat_on_seller_only CHECK (party_role = 'seller' OR (vat_amount = 0 AND vat_rate = 0))`, `invoice_gross CHECK (gross_amount = net_amount + vat_amount)`, `invoice_no_matches_party` (suffix ↔ role), `invoice_storage_pair CHECK ((storage_ref IS NULL) = (document_at IS NULL))`.

Triggers:
- `trg_tax_invoice_guard` BEFORE UPDATE OR DELETE — DELETE refused; UPDATE allowed only when every column is unchanged except `issuer`, `party`, `storage_ref`, `document_at` going from NULL to a value (DH012).
- `trg_tax_invoice_reconciled` deferred AFTER INSERT — the order has a `settlement_txn_id`, `customer_id` matches the order's party, amounts equal the order's settlement columns and (seller) the entry's `dahab_commission`/`vat_payable` lines (DH012, R7).

RLS (forced): `USING (elevated OR customer_id = current customer)`; `WITH CHECK (elevated OR (scope = 'order' AND EXISTS (order visible with buyer_id = current customer)))`.

## `credit_note` (new)

| Column | Type | Rules |
|---|---|---|
| `credit_note_id` | UUID PK | |
| `credit_note_no` | TEXT NOT NULL UNIQUE | `CN-YYYY-NNNNNN` from `credit_note_no_seq` (R4) |
| `invoice_id` | UUID NOT NULL → `tax_invoice` | a seller invoice only (DH012) |
| `customer_id` | UUID NOT NULL → `customer` | = the invoice's customer (for RLS) |
| `net_amount` | NUMERIC NOT NULL | `> 0`; gross − VAT |
| `vat_amount` | NUMERIC NOT NULL | `>= 0`; round½↑(gross × rate / (100 + rate), 4) |
| `gross_amount` | NUMERIC NOT NULL | `> 0`; `= net + vat` |
| `reason` | TEXT NOT NULL | 10–1000 characters |
| `issued_by` | UUID NOT NULL → `staff` | |
| `ledger_txn_id` | UUID NOT NULL UNIQUE → `ledger_transaction` | the `credit_note` entry |
| `issuer` | JSONB NULL | as on the invoice (set once) |
| `issued_at` | TIMESTAMPTZ NOT NULL | `clock_timestamp()` |
| `storage_ref`, `document_at` | TEXT / TIMESTAMPTZ NULL | set once |

Indexes: `(issued_at DESC, credit_note_id)`, `(invoice_id)`, `(customer_id, issued_at DESC)`.

Triggers:
- `trg_credit_note_cap` BEFORE INSERT — locks the invoice row; refuses a buyer invoice or `Σ gross (existing) + NEW.gross > invoice.gross` (DH012).
- `trg_credit_note_guard` BEFORE UPDATE OR DELETE — as on the invoice (only `issuer`, `storage_ref`, `document_at` once).
- `trg_credit_note_recorded` deferred AFTER INSERT — the entry is kind `credit_note`, names the invoice's order, its staff is `issued_by`, and it has exactly `dahab_commission −net`, `vat_payable −vat`, the customer's `cust_available +gross` (DH012).

RLS (forced): `USING (elevated OR customer_id = current customer)`; `WITH CHECK (elevated)`.

## Derived (not stored)

- **Credited amount** of an invoice: Σ `credit_note.gross_amount`. **Remaining**: gross − credited.
- **Status**: `credited` (credited = gross), `partly_credited` (0 < credited < gross), `issued`.
- **Figures** (`invoice.view`): month and period — invoices issued (count, both parties), net invoiced (Σ seller net), VAT collected (Σ seller VAT − Σ credit-note VAT), credit notes (count, Σ gross).

## Configuration — `config/dahab-invoices.php`

`issuer.{legal_name_en, legal_name_ar, address_en, address_ar, tax_registration_no, commercial_register_no}` (literal values, empty in this change), `export_cap` (10000), `render_tries` (5).

## Catalogue additions

- Permissions: `invoice.view`, `invoice.correct` — seeded to CEO and Finance.
- Audit events: `credit_note.issued`, `invoice.document_viewed`, `credit_note.document_viewed`, `invoices.exported`; `order.paid` context gains `invoice_numbers`.
- Errors: `invoice_not_creditable` (409), `credit_exceeds_invoice` (422), `document_not_ready` (409); SQLSTATE DH012.
- Notification: `WalletNotification` `credit_note_issued` (SMS + email, EN/AR).
