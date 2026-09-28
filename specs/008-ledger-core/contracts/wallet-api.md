# Contract: Wallet reads (Customer + Dashboard)

Every endpoint is under `/api/v1`. The envelope and errors follow `docs/platform/api-contract.md`: success is `{ data, meta? }`, errors are `{ message, code, errors? }`. Money is a decimal string with 4 places. Times are ISO-8601 with the Cairo offset. The code, through `#[OA]`, wins over this file. All endpoints here are reads; none is idempotency-keyed.

## Customer (`auth:customer`, `abilities:customer:access`, `customer.gate:verified`)

### `GET /customer/me/wallet`

**200**:

```json
{ "data": { "available": "600.0000", "held": "400.0000", "total": "1000.0000", "currency": "EGP" } }
```

**Errors**:
- 403 `verification_required` (pending or rejected customer);
- a suspended customer **passes** (reads are allowed).

### `GET /customer/me/wallet/transactions?cursor=&per_page=`

`per_page` is 1–100 (default 25). Results are newest first, with keyset pagination.

**200**:

```json
{
  "data": [
    { "id": "uuid", "kind": "deposit_hold", "created_at": "2026-10-01T10:04:00+03:00",
      "available_change": "-400.0000", "held_change": "400.0000",
      "available_after": "600.0000", "held_after": "400.0000", "reference": null }
  ],
  "meta": { "per_page": 25, "next_cursor": null }
}
```

- `kind` is mapped to text by the app (en/ar). The staff memo is never returned.
- **422** `validation_failed`: a malformed cursor.

## Dashboard (`auth:staff`, `abilities:staff:access`, `staff.standing`, `staff.permission:wallet.view`)

Without `wallet.view`: **403 `permission_denied`**, audited as `auth.staff.permission_denied`.

### `GET /dashboard/wallets/overview`

The safety figure and the customer wallets panel (the Overview; FR-018).

```json
{ "data": { "available": "…", "held": "…", "total_owed": "…",
            "bank": "…", "headroom": "…", "system_total": "0.0000" } }
```

`headroom = bank − total_owed`. A negative value means customer money is short.

### `GET /dashboard/customers/{customer}/wallet`

The customer file's wallet panel.

```json
{ "data": { "available": "…", "held": "…", "total": "…" } }
```

**404** `not_found`: an unknown customer. Not audited (opening the file already is).

### `GET /dashboard/wallet-statement`

**Query**:

| Param | Rules |
|---|---|
| `view` | required: `customer` \| `customers` \| `dahab` |
| `customer_id` | required when `view=customer`, UUID of an existing customer; prohibited otherwise |
| `from`, `to` | required dates `YYYY-MM-DD` (Cairo days, inclusive), `from ≤ to`, range ≤ 366 days |
| `grain` | `each` (default) \| `day` \| `month` |
| `cursor`, `per_page` | keyset, oldest first; `per_page` 1–200, default 50 |

**200**:

```json
{
  "data": {
    "summary": { "view": "customer", "from": "2026-08-01", "to": "2026-08-31",
                 "opening": "23808.0000", "in": "146972.0000", "out": "114020.0000", "closing": "56760.0000",
                 "available": "32760.0000", "held": "24000.0000", "total": "56760.0000",
                 "customer": { "id": "uuid", "display_ref": "4417", "full_name": "Mona Hassan Ibrahim" } },
    "rows": [
      { "id": "uuid", "created_at": "…", "kind": "deposit_hold", "label": "Deposit held",
        "reference": null, "memo": null, "by_hand": false, "actor": { "type": "customer", "name": "Mona Hassan Ibrahim" },
        "before": "49028.0000", "in": "0.0000", "out": "24000.0000", "after": "25028.0000", "held_after": "24000.0000" }
    ]
  },
  "meta": { "per_page": 50, "next_cursor": "…" }
}
```

What differs by view:
- **`customer`**: the running balance is **available**; a hold is `out`, a release is `in`, and `held_after` is shown beside it. `summary.available` + `summary.held` = `summary.total`, and `closing` = the available balance at `to`.
- **`customers`**: the running balance is the total owed (available + held). Rows add `wallet: { customer_id, display_ref }` and `moved_to_held` (the amount moved between available and held; its net is 0). `summary.customer` is absent.
- **`dahab`**: the running balance is commission + spread. `summary.vat_payable` gives VAT payable at `to`.
- **`grain=day|month`**: each row is `{ "period": "2026-08-02" | "2026-08", "count": 1, "before", "in", "out", "after" }`.

**Invariants** (tested):
- `opening + in − out = closing`;
- each row's `before + in − out = after`;
- the first row's `before` = `opening`, and the last row's `after` (on the last page) = `closing`.

**Audited**: `view=customer` writes `ledger.statement.viewed`. The subject is the customer, and `after` holds `{view, from, to, grain}`. Only the first page is audited: a request with `cursor` is not.

**Errors**: 422 `validation_failed`; 404 `not_found` (unknown `customer_id`).

### `GET /dashboard/wallet-statement/export`

Same query, minus `cursor` and `per_page`. Returns `text/csv` (UTF-8) with an opening line, a summary line and one line per row, capped at 50,000 rows with a closing note when truncated (the spec 006 pattern).

**Audited**: `ledger.statement.exported` (every view).

## Error codes added

| Code | HTTP | When |
|---|---|---|
| `insufficient_funds` | 409 | an entry would take a customer account below zero (raised by the money service; no endpoint in this feature can trigger it) |
| `ledger_already_reversed` | 409 | reversing an entry twice (service-level) |

## Permission added

`wallet.view`, "View wallets and statements", group "Money". Seeded to `ceo` and `finance`, not `coo`.
