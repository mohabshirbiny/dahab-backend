# Data Model: Ledger Core (spec 008)

Source: `docs/Database schema/03_schema_ledger.sql` and the `ledger_event_kind` definition in `01_schema_core.sql`. The additions (research R1) are written into the SQL docs before the migration.

## Enums (PostgreSQL types)

**`account_kind`** — `cust_available`, `cust_held`, `escrow`, `dahab_commission`, `dahab_spread`, `vat_payable`, `bank`, `external_equity`.
PHP: `App\Enums\AccountKind`, with `isCustomer()`.

**`ledger_event_kind`** — `topup`, `deposit_hold`, `deposit_release`, `deposit_forfeit`, `settlement_seller`, `first_sale_payout`, `commission`, `spread`, `vat`, `balance_payment`, `withdrawal`, `compensation`, `external_bank_movement`, `weight_adjustment`, `reversal`.
PHP: `App\Enums\LedgerEventKind`, with `staffLabel()` (research R9).

## `account`

| Column | Type | Rules |
|---|---|---|
| `account_id` | UUID PK | `gen_random_uuid()` |
| `kind` | `account_kind` | NOT NULL |
| `customer_id` | UUID FK → `customer` | set **iff** the kind is a customer kind (`customer_accounts_have_owner`) |
| `currency` | CHAR(3) | NOT NULL, default `EGP` |
| `created_at` | TIMESTAMPTZ | default `now()` |

- UNIQUE `(customer_id, kind)`; partial UNIQUE `(kind) WHERE customer_id IS NULL` (one of each internal kind).
- Seeded: the 6 internal singletons (migration). Per customer: `cust_available` and `cust_held`, created by the `trg_customer_accounts` AFTER INSERT trigger on `customer` and backfilled for existing customers.
- Model `App\Models\Account` (UUID key, no timestamps besides `created_at`), with `customer()` and `postings()`. Static `internal(AccountKind)` returns a cached id per request. Scope `forCustomer($id)`.

## `ledger_transaction` (a ledger entry)

| Column | Type | Rules |
|---|---|---|
| `ledger_txn_id` | UUID PK | |
| `event_kind` | `ledger_event_kind` | NOT NULL |
| `listing_id`, `order_id`, `buy_request_id`, `withdrawal_id` | UUID NULL | FKs added by their features |
| `customer_id` | UUID FK → `customer` NULL | customer actor |
| `staff_id` | UUID FK → `staff` NULL | staff actor (the system actor included) |
| `memo` | TEXT NULL | staff-facing; a reversal's reason |
| `created_at` | TIMESTAMPTZ | default `now()` |
| `reverses_txn_id` | UUID FK → self NULL | **new** partial UNIQUE (reversed at most once) |

- CHECK `ledger_txn_has_actor`. Append-only trigger `ledger_txn_no_update`. Index on `order_id`; **new** index on `created_at`.
- Model `App\Models\LedgerTransaction`: read-only by convention (`save()` on an existing row throws), with `postings()`, `reverses()`, `reversal()`, `staff()` and `customer()`.

## `ledger_posting` (a ledger line)

| Column | Type | Rules |
|---|---|---|
| `posting_id` | BIGSERIAL PK | global order; an entry's sequence key is `MIN(posting_id)` |
| `ledger_txn_id` | UUID FK | NOT NULL |
| `account_id` | UUID FK | NOT NULL |
| `amount` | NUMERIC(18,4) | NOT NULL, `<> 0`; signed |
| `created_at` | TIMESTAMPTZ | default `now()` |

- Deferred constraint triggers:
  - `trg_txn_balanced`: the entry's lines sum to 0, else SQLSTATE `DH002`;
  - `trg_customer_nonneg`: a customer account's balance is ≥ 0, else `DH001`.
- Append-only trigger `ledger_posting_no_update`.
- Indexes: `ledger_txn_id`; **replaced** `account_id` → `(account_id, posting_id) INCLUDE (amount, ledger_txn_id)`.
- Model `App\Models\LedgerPosting`, with `amount` cast as a decimal string (never float).

## Views (derived, never stored)

`account_balance`, `customer_wallet`, `solvency_check` (`bank_balance`, `owed_to_customers`, `headroom`) and `ledger_global_zero` (`must_be_zero`), as documented, **except** that `solvency_check.bank_balance` is `−SUM(bank lines)` (research R15): money arriving is posted as `bank −X`, so the cash held is the negation of the bank's ledger balance. The APIs show `bank` as cash (positive).

## Row-level security (research R3)

| Table | SELECT | INSERT |
|---|---|---|
| `account` | elevated · `ledger` · `customer_id = me` | elevated · `ledger` |
| `ledger_transaction` | elevated · `ledger` · has a posting on my account | elevated · `ledger` |
| `ledger_posting` | elevated · `ledger` · account is mine | elevated · `ledger` |

`account` and `ledger_transaction` also have a lock-only UPDATE policy: `USING (scope = 'ledger') WITH CHECK (false)`. `SELECT … FOR UPDATE` only sees rows that pass an UPDATE policy, and this one allows the money service's locks while every actual UPDATE is still refused. There are no DELETE policies. `FORCE ROW LEVEL SECURITY` is on for all three, and all three are added to `CustomerTableIsolationTest`'s expectations. The trigger functions read under a transaction-local `ledger` scope and restore the caller's scope afterwards.

## Posting contract (the money service)

```
LedgerEntry {
  kind: LedgerEventKind
  actorCustomerId: ?uuid        # at least one of the two actors is required (ledger_txn_has_actor)
  actorStaffId: ?uuid
  memo: ?string                 # ≤ 1000
  refs: { listingId?, orderId?, buyRequestId?, withdrawalId? }
  reverses: ?uuid               # set only by ReverseLedgerEntryAction
  lines: list<{ accountId: uuid, amount: string /^-?\d{1,14}(\.\d{1,4})?$/, ≠ 0 }>  # ≥ 2, Σ = 0
}
```

**Validation**, in order: an actor is present; there are ≥ 2 lines; each amount's format is valid and non-zero; the sum is 0; the accounts exist.

**Then**: lock the customer accounts `FOR UPDATE` (ordered), check each one's projected balance is ≥ 0 (otherwise `insufficient_funds`, 409), and insert. The caller must already be inside a DB transaction.

**Reversal**: `ReverseLedgerEntryAction(txnId, staffId, reason)` negates every line, sets `kind = reversal`, `memo = reason` and `reverses_txn_id`, and copies the references. It refuses when the entry is already reversed (409 `ledger_already_reversed`), and the overdraft check applies.

## Read models (not tables)

- **Wallet**: `{ available, held, total }` per customer.
- **History row** (customer): `txn_id`, `kind`, `created_at`, `available_change`, `held_change`, `available_after`, `held_after`, `reference` (null until orders exist).
- **Statement summary**: `view`, `from`, `to`, `opening`, `in`, `out`, `closing`, plus `available` + `held` for the `customer` view and `vat_payable` for the `dahab` view.
- **Statement row**:
  - grain `each`: `txn_id`, `created_at`, `kind`, `label`, `wallet` (`customers` view: customer ref + id), `reference`, `memo`, `by_hand`, `actor`, `before`, `in`, `out`, `after`, `moved_to_held` (`customers` view), `held_after` (`customer` view);
  - grain `day` / `month`: `period`, `count`, `before`, `in`, `out`, `after`.
- **Ledger health**: `available`, `held`, `total_owed`, `bank`, `headroom`, `system_total` (must be `"0.0000"`).

All money is a decimal string with 4 places.
