# Data Model: Pricing

The settings tables come from `docs/Database schema/01_schema_core.sql` §3. Everything marked **(005)** is new and goes into the schema doc first (research R1–R5). None of these tables has a customer owner column, so there is no row-level security (the spec 003 check passes).

## Tables

### `setting` (§3, + FK)
| Column | Type | Notes |
|---|---|---|
| `setting_key` | TEXT PK | e.g. `commission.gold_pct` |
| `value_numeric` | NUMERIC(18,4) | numeric keys |
| `value_text` | TEXT | unused so far |
| `value_bool` | BOOLEAN | bool keys (`manualprice.confirmer_must_differ`) |
| `unit` | TEXT | `percent`, `egp`, `hours`, `working_hours`, `days`, `weeks`, `minutes`, `count`, `bool` |
| `description` | TEXT NOT NULL | |
| `updated_by` | UUID **(005)** REFERENCES staff | null for seed |
| `updated_at` | TIMESTAMPTZ | |

### `setting_history` (§3, append-only)
The §3 columns, plus **(005)**:
- `reason TEXT NOT NULL`;
- `changed_by REFERENCES staff`;
- an update/delete trigger that refuses changes.

### `gold_price` (005, append-only)
| Column | Type | Rules |
|---|---|---|
| `gold_price_id` | BIGSERIAL PK | |
| `source` | TEXT | `feed` \| `manual` |
| `bid_24k` | NUMERIC(18,4) | > 0; EGP per gram of 24K |
| `ask_24k` | NUMERIC(18,4) | ≥ `bid_24k` |
| `manual_gold_price_id` | BIGINT FK NULL | required when `source = 'manual'` |
| `recorded_by` | UUID NOT NULL FK staff | Constitution I: the system actor for `feed` rows; the confirmer (or the entrant, when no confirmation was needed) for `manual` rows |
| `effective_at` | TIMESTAMPTZ NOT NULL | |
| `created_at` | TIMESTAMPTZ | |

Index `(effective_at DESC, gold_price_id DESC)`. The current price is the first row in that order.

### `manual_gold_price` (005)
| Column | Type | Rules |
|---|---|---|
| `manual_gold_price_id` | BIGSERIAL PK | |
| `bid_24k`, `ask_24k` | NUMERIC(18,4) | same checks |
| `previous_gold_price_id` | BIGINT FK NULL | the current price at entry |
| `deviation_pct` | NUMERIC(8,4) NULL | null when there was no previous price |
| `requires_confirmation` | BOOLEAN | deviation > `manualprice.confirm_deviation_pct` |
| `reason` | TEXT NOT NULL | ≥ 5 characters |
| `entered_by` | UUID FK staff | |
| `status` | TEXT | `pending` → `effective` \| `superseded` \| `lapsed`; or created `effective` |
| `confirmed_by`, `confirmed_at` | UUID FK / TIMESTAMPTZ NULL | |
| `expires_at` | TIMESTAMPTZ NULL | pending only: now + `manualprice.pending_expiry_hours`. A pending row past `expires_at` is **treated as lapsed** by every read (computed, no write); its status moves to `lapsed` only inside the next write transaction (manual entry, confirmation, feed write) |
| `created_at` | TIMESTAMPTZ | |

Trigger: only a status change from `pending` is allowed (plus setting `confirmed_by` / `confirmed_at`); nothing else is ever updated, and nothing is deleted. At most one `pending` row at a time (partial unique index on `status = 'pending'`); a newer entry supersedes it.

### `karat_price_adjustment` (005)
| Column | Type | Rules |
|---|---|---|
| `karat_code` | SMALLINT FK karat | |
| `side` | TEXT | `buy` (sellers get) \| `sell` (buyers pay) |
| `kind` | TEXT | `fixed` (EGP per gram of the karat) \| `percent` |
| `value` | NUMERIC(18,4) | `percent` > −100 |
| `updated_by` | UUID FK staff NULL | |
| `updated_at` | TIMESTAMPTZ | |

PK `(karat_code, side)`. Seed: for every karat, `buy` fixed −15 and `sell` fixed +15. A karat created later gets `fixed 0` on both sides (research R3).

### `karat_price_adjustment_history` (005, append-only)
`history_id`, `karat_code`, `side`, `old_kind`, `old_value`, `new_kind`, `new_value`, `changed_by`, `reason NOT NULL`, `changed_at`.

### `price_feed_status` (005)
`provider TEXT PK` (`default`), `last_success_at`, `last_failure_at`, `last_error TEXT`. One row, updated each minute by the feed command.

## Derived (not stored)

For karat `k` with purity `p`, from the current `gold_price`:

```
market_bid(k)  = bid_24k × p ÷ 0.999
market_ask(k)  = ask_24k × p ÷ 0.999
sellers_get(k) = adjust(market_bid(k), buy adjustment)
buyers_pay(k)  = adjust(market_ask(k), sell adjustment)
mid(k)         = (market_bid(k) + market_ask(k)) ÷ 2
adjust(x, fixed v)   = x + v
adjust(x, percent v) = x × (1 + v ÷ 100)
```

Each is rounded half-up to 4 dp. A karat with `buyers_pay < sellers_get` or any price ≤ 0 is **inverted** and cannot be quoted.

## Price breakdown (Part 3 §2, amended)

| Category | Buyer total | Seller gross | Spread | Commission base |
|---|---|---|---|---|
| gold | `buyers_pay × W + making × W` | `sellers_get × W + making × W` | `(buyers_pay − sellers_get) × W` | `making × W` × `commission.gold_pct` |
| diamond | `asking` | `asking` | 0 | `asking` × `commission.stone_pct` |
| gold_with_diamond | `asking` | `asking` | 0 | `(asking − mid × W)` × `commission.stone_pct` (≥ 0), `mid` = the midpoint of the unadjusted market bid and ask |

- `commission = waived ? 0 : max(round4(base), commission.minimum_egp)`.
- `vat = round4(vat.pct × commission)`.
- **Rounding and the invariant (Part 3 §3.5)**: `buyer_total` and `seller_gross` are each rounded half-up to 4 dp. `spread := buyer_total − seller_gross` and `seller_proceeds := seller_gross − commission − vat` are **derived by subtraction, never rounded on their own**, so any rounding residue lands in the spread (gold) and `buyer_total − seller_proceeds = commission + vat + spread` holds exactly by construction.

## Audit events (AuditEvent)

| Event | Payload |
|---|---|
| `pricing.setting.changed` | `{key, old, new}` + reason |
| `pricing.adjustment.changed` | `{karat_code, side, old, new}` + reason |
| `pricing.manual_price.entered` | `{manual_gold_price_id, bid, ask, deviation_pct, status}` + reason |
| `pricing.manual_price.confirmed` | `{manual_gold_price_id, gold_price_id}` |

## Permissions (StaffPermission)
`pricing.view`, `pricing.rates.manage`, `settings.manage`, `gold_price.enter`, `gold_price.confirm` — seed roles in research R7.
