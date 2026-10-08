# Data model: After collection — free relist and rating

Mirrors the style of `docs/Database schema/*.sql` (to be updated by task group 8). One migration: `database/migrations/2026_10_11_000010_after_collection.php` (sorts after `2026_10_10_000010_customer_account.php`), reversible, PostgreSQL only.

## Changed tables

### `collection` (existing)
| Column | Type | Notes |
|---|---|---|
| `free_relist_until` | `TIMESTAMPTZ NULL` | End of the free-relist window, set once by the staff handover; null = no offer. |
| CHECK `collection_free_relist_shape` | | `free_relist_until IS NULL OR (collected_at IS NOT NULL AND free_relist_until > collected_at)` |

Existing RLS (`collection_isolation`) unchanged: the buyer reads their own row. A guard keeps `free_relist_until` write-once (set only when `collected_at` is set in the same statement; never changed after).

### `listing` (existing)
| Column | Type | Notes |
|---|---|---|
| `relisted_from_order_id` | `UUID NULL REFERENCES "order"(order_id)` | Set only on INSERT; never changes. Its presence = "this listing is a free relist" = the 0% commission waiver. |
| `UNIQUE INDEX one_free_relist_per_order` | partial, `WHERE relisted_from_order_id IS NOT NULL` | One relist per origin order (FR-004). |

`listing_guard` (BEFORE INSERT OR UPDATE) additions:
- INSERT with a link: the origin order is `completed`, `order.buyer_id = NEW.seller_id`, its collection's `free_relist_until >= now()`, and the origin listing has **no** link; otherwise `RAISE … USING ERRCODE = 'DH004'`. (Reads the other tables with the same scope handling as `tax_invoice_reconciled`.)
- UPDATE: `relisted_from_order_id` is immutable (joins the existing identity-column check).
- The move `draft → live` is allowed only when `NEW.relisted_from_order_id IS NOT NULL`.

### `listing_transition` (existing, seed table)
New row: `('draft', 'live', 'free relist: no review')`.

### `tax_invoice` (existing)
Unchanged columns and CHECKs. New deferred constraint trigger `trg_free_relist_seller_invoice` (SQLSTATE DH012): after a settlement, an order whose listing has a link has **no** `seller` invoice; an order without a link and with `commission_amount > 0` has one.

### `order` (existing)
No new column. A waived settlement stores `commission_amount = 0`, `vat_amount = 0` (all-or-none shape check still holds).

## New table: `order_rating`
| Column | Type | Notes |
|---|---|---|
| `rating_id` | `UUID PK DEFAULT gen_random_uuid()` | |
| `order_id` | `UUID NOT NULL REFERENCES "order"(order_id)` | |
| `party_role` | `party_role NOT NULL` (`seller`/`buyer`) | existing enum |
| `customer_id` | `UUID NOT NULL REFERENCES customer(customer_id)` | the author |
| `stars` | `SMALLINT NOT NULL CHECK (stars BETWEEN 1 AND 5)` | |
| `note` | `TEXT NULL CHECK (note IS NULL OR char_length(note) BETWEEN 1 AND 500)` | plain text; empty is stored as null |
| `created_at` | `TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()` | |
| `UNIQUE (order_id, party_role)` | | one per party per order |

Guards:
- `trg_order_rating_party` (BEFORE INSERT): `customer_id` is the order's `seller_id` for `seller`, `buyer_id` for `buyer`; the order has a settlement (`settlement_txn_id IS NOT NULL`) and is not a `cancelled_*` state; for `buyer`, the order is `completed`. Time windows are enforced by the Action (30 days from the opening instant), not by the engine (they depend on `order_state_change` history and the clock).
- `trg_order_rating_immutable` (BEFORE UPDATE OR DELETE): refuses, SQLSTATE **DH016**.
- `trg_order_rating_not_closed` (BEFORE INSERT): spec 017 pattern, DH013.

Forced RLS: `ENABLE` + `FORCE`; `order_rating_isolation FOR ALL USING (dahab_rls_elevated() OR customer_id = dahab_current_customer_id()) WITH CHECK (… same …)`; written in the `order` scope by the author only. Staff reads are made in the elevated scope inside `ShowOrderAction`, only when the viewer holds `rating.view`.

## Names across layers
`collection.free_relist_until` (database) = `free_relist.ends_at` (API) = *window end* (spec) = `freeRelist.endsAt` (Dashboard/Flutter). `listing.relisted_from_order_id` = `relisted_from_order { id, order_ref }` (API).

## Derived (no storage)
- **Offer status** `none|open|used|expired` — from `collection.free_relist_until`, the existence of a listing linking to the order, and `now()` (R10).
- **Rating window** — seller opens at the `order_state_change` row `→ ready_to_collect`; buyer at `order.completed_at`; both close 30 calendar days later (Cairo) (R12).

## Seeds and enums
- `StaffPermission::RATING_VIEW = 'rating.view'` seeded to CEO and COO by the migration/seeder path used for `listing_report.handle` in `9cb3fe1`.
- `AuditEvent::ORDER_FREE_RELISTED`, `ORDER_RATED`; `OrderEvent::FREE_RELISTED`.
- New `DomainApiException` factories: `freeRelistExpired` (409), `alreadyRelisted` (409), `ratingClosed` (409), `ratingNotAvailable` (409), `alreadyRated` (409).
- No change to `ListingState`, `OrderState`, `ledger_event_kind` (a waived settlement is still `balance_payment`).

## State transitions
```
collection.free_relist_until: NULL ──(handover, hours resolvable, not a free-relist sale, setting>0)──▶ set (immutable)
listing(link): INSERT draft ──(draft→live, link required)──▶ live ──(normal machine from here)──▶ reserved … sold | withdrawn
order_rating: absent ──(insert, in window)──▶ present (immutable)
```
