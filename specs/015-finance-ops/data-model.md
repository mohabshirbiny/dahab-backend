# Data model: Finance operations and order export (spec 015)

One migration, `2026_10_08_000010_create_finance_ops.php`, mirroring the updated `05_schema_security.sql` §16–§17 (and `00_schema_full.sql`). Money columns `NUMERIC(18,4)`; times `TIMESTAMPTZ`, Cairo for display. All new tables are append-only or guarded; the ledger stays the only source of balances.

## 1. `compensation` (changed — spec 014 table)

| Column | Change |
|---|---|
| `dispute_id` | NOT NULL → nullable |
| `order_id` | NOT NULL → nullable |
| `party` | NOT NULL → nullable (`buyer | seller` when an order is named) |

- New CHECKs: `compensation_dispute_needs_order` — `dispute_id IS NULL OR order_id IS NOT NULL`; `compensation_party_with_order` — `(order_id IS NULL) = (party IS NULL)`.
- `compensation_recorded()` (deferred, DH009): the party check only when `order_id` is set; the ledger match uses `t.order_id IS NOT DISTINCT FROM NEW.order_id`.
- New index `idx_compensation_paid_at (paid_at, compensation_id)` for the list (keyset newest first).
- RLS unchanged (customer reads own rows; writes elevated).

## 2. `wallet_adjustment` (new)

| Column | Type | Rule |
|---|---|---|
| `adjustment_id` | UUID PK | `gen_random_uuid()` |
| `customer_id` | UUID FK customer | NOT NULL |
| `direction` | TEXT | `credit | debit` |
| `amount` | NUMERIC(18,4) | `> 0` |
| `reason` | TEXT | 10–1000 characters |
| `customer_status` | TEXT | the customer's status at the time (audit context) |
| `adjusted_by` | UUID FK staff | NOT NULL |
| `ledger_txn_id` | UUID FK ledger_transaction | NOT NULL UNIQUE |
| `adjusted_at` | TIMESTAMPTZ | `clock_timestamp()` |

- `trg_wallet_adjustment_immutable` — `block_mutation()` on UPDATE/DELETE.
- Deferred `wallet_adjustment_recorded()` (new SQLSTATE **DH011**): the entry is `event_kind = 'reversal'`, `reverses_txn_id IS NULL`, staff actor = `adjusted_by`, and posts `+amount` (credit) / `−amount` (debit) to this customer's `cust_available`.
- Index `(adjusted_at, adjustment_id)`, `(customer_id, adjusted_at)`.
- Forced RLS: `USING (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())`, `WITH CHECK (dahab_rls_elevated())`.

## 3. `bank_movement` (new — schema §17, extended)

| Column | Type | Rule |
|---|---|---|
| `movement_id` | UUID PK | |
| `movement_no` | BIGINT | from `bank_movement_no_seq`, shown `BM-{n}` |
| `kind` | TEXT | `capital_in | operating_expense | bank_charge | profit_draw | own_transfer | supplier_refund | other` |
| `amount` | NUMERIC(18,4) | signed, `<> 0` (+ in, − out) |
| `occurred_on` | DATE | the date on the bank statement; not after today in Cairo (validated in the request) |
| `reason` | TEXT | 10–500 characters |
| `proof_ref` | TEXT NULL | encrypted storage reference |
| `proof_mime` | TEXT NULL | set iff `proof_ref` |
| `recorded_by` | UUID FK staff | NOT NULL |
| `ledger_txn_id` | UUID FK ledger_transaction NULL UNIQUE | NULL iff `kind = 'own_transfer'` |
| `recorded_at` | TIMESTAMPTZ | `clock_timestamp()` |

- CHECK `bank_movement_entry_unless_own_transfer`: `(kind = 'own_transfer') = (ledger_txn_id IS NULL)`; CHECK proof pair.
- Append-only (`block_mutation()`); deferred `bank_movement_recorded()` (DH011): the entry is `external_bank_movement`, staff actor = `recorded_by`, `bank` posting = `−amount`, `external_equity` posting = `+amount`.
- Index `(recorded_at, movement_id)`, `(occurred_on)`.
- No customer RLS (no customer data); staff reads by permission.

## 4. `daily_close` (new — schema §17, extended)

| Column | Type | Rule |
|---|---|---|
| `close_date` | DATE PK | a Cairo day |
| `bank_balance` | NUMERIC(18,4) | typed: closing balance across all Dahab accounts |
| `books_bank` | NUMERIC(18,4) | ledger bank cash at the cut-off |
| `customer_available` | NUMERIC(18,4) | |
| `customer_held` | NUMERIC(18,4) | |
| `customer_liability` | NUMERIC(18,4) | available + held (schema column) |
| `dahab_wallet` | NUMERIC(18,4) | commission + spread |
| `escrow` | NUMERIC(18,4) | |
| `vat_payable` | NUMERIC(18,4) | |
| `movements_in` / `movements_out` | NUMERIC(18,4) | hand-recorded movements dated that day |
| `difference` | NUMERIC(18,4) | `bank_balance − books_bank` |
| `explanation` | TEXT NULL | 10–1000 |
| `is_locked` | BOOLEAN | default false |
| `saved_by` / `saved_at` | staff / TIMESTAMPTZ | last save |
| `closed_by` / `closed_at` | staff / TIMESTAMPTZ NULL | set when locked |

- CHECKs: `daily_close_locked_named` — `NOT is_locked OR (closed_by IS NOT NULL AND closed_at IS NOT NULL)`; `daily_close_explained` — `NOT is_locked OR difference = 0 OR explanation IS NOT NULL`; `difference = bank_balance − books_bank`; `customer_liability = customer_available + customer_held`.
- Trigger `daily_close_no_reopen` (schema): `BEFORE UPDATE OR DELETE … WHEN (OLD.is_locked) EXECUTE FUNCTION block_mutation()`.
- Lifecycle: *absent* → *saved (unlocked)* ⇄ re-saved → *locked* (final). No transition table (two states, enforced by the trigger and the CHECKs).

## 5. Permissions (data — Spatie `permissions`)

`wallet.adjust` (no role), `bank.record` (finance), `day.close` (finance); the founder seed holds every code. Added to `StaffPermission` with labels and to the Dashboard catalogue.

## 6. Derived values (no storage)

- **Held per buy request**: `SUM(p.amount)` over `ledger_posting p JOIN ledger_transaction t JOIN account a` where `a.kind = 'cust_held'`, `a.customer_id = buyer`, `t.buy_request_id = :id`. An order's held = its request's.
- **Bank cash at a time**: `−SUM(bank postings created before it)`.
- **Earnings this month**: SUM of postings to `dahab_commission` and `dahab_spread` created in the Cairo month.

## 7. Enums / codes

- `AuditEvent` +: `compensation.list_exported`, `wallet.adjusted`, `bank.movement_recorded`, `bank.movement_proof_viewed`, `bank.book_exported`, `bank.movements_exported`, `day.closed`, `day.saved`, `order.list_exported`. (`compensation.paid` reused with `dispute_ref: null`.)
- `UploadPurpose` + `bank_movement_proof` (staff only).
- Error codes +: `day_not_ended` (422), `day_already_closed` (409); SQLSTATE DH011 → 500-class integrity failure (never expected; logged).
- `BankMovementKind`, `AdjustmentDirection` enums.
