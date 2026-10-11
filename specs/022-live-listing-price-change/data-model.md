# Data model: Change the price of a live piece (spec 022)

## 1. Schema changes: one migration, `database/migrations/2026_10_15_000010_listing_price_change.php`

PostgreSQL only (`DB::getDriverName() !== 'pgsql'` → return, like the other spec migrations). Mirrored in `docs/Database schema/04_schema_market.sql` (new "spec 022" section after the listing guards) and `00_schema_full.sql`.

### 1.1 `listing_price_change` (new, append-only)

| Column | Type | Rule |
|---|---|---|
| `change_id` | `BIGSERIAL PRIMARY KEY` | order of the history |
| `listing_id` | `UUID NOT NULL REFERENCES listing(listing_id)` | |
| `field` | `TEXT NOT NULL` | `CHECK (field IN ('making_charge_per_g','asking_price'))` |
| `old_value` | `NUMERIC(18,4) NOT NULL` | `CHECK (old_value >= 0 AND old_value = round(old_value, 2))` |
| `new_value` | `NUMERIC(18,4) NOT NULL` | same CHECK; `CHECK (new_value <> old_value)`; `asking_price` rows need `new_value > 0` (`CHECK (field <> 'asking_price' OR new_value > 0)`) |
| `actor_customer_id` | `UUID REFERENCES customer(customer_id)` | |
| `actor_staff_id` | `UUID REFERENCES staff(staff_id)` | `CHECK (num_nonnulls(actor_customer_id, actor_staff_id) = 1)` (Constitution I; spec 022 writes only the seller, the staff column keeps the table usable by a later spec without a migration) |
| `changed_at` | `TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()` | |
| `txid` | `BIGINT NOT NULL DEFAULT txid_current()` | ties the row to the change (§1.3) |

Index: `idx_listing_price_change_listing ON listing_price_change (listing_id, change_id)`.

**RLS** (forced, like `listing_state_change`):

- `listing_price_change_isolation FOR ALL USING (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l WHERE l.listing_id = listing_price_change.listing_id AND l.seller_id = dahab_current_customer_id()))`
- `WITH CHECK (dahab_rls_elevated() OR (actor_customer_id = dahab_current_customer_id() AND actor_staff_id IS NULL AND EXISTS (… the caller's own listing …)))`
- No `market`, `queue` or `order` policy: nobody but the seller and staff reads it.

**Append-only**: `trg_listing_price_change_immutable BEFORE UPDATE OR DELETE` → `RAISE … USING ERRCODE = 'DH023'` ('listing price history is append-only').

### 1.2 `listing_price_locked(p_listing uuid) RETURNS boolean`

`LANGUAGE plpgsql`, the `free_relist_origin_ok()` pattern: remember `current_setting('app.rls_scope', true)`, `set_config('app.rls_scope','system', true)`, `SELECT EXISTS (SELECT 1 FROM market_maker_approval WHERE listing_id = p_listing)`, restore the scope, return. It answers only a boolean about one listing; it reveals nothing else. Spec 023 extends this function (an advance paid on the piece) instead of adding another check.

### 1.3 Listing price guards (DH023)

`listing_guard()` is **not** redefined. Two new triggers on `listing`:

```
trg_listing_price_guard   BEFORE UPDATE OF making_charge_per_g, asking_price ON listing FOR EACH ROW
  IF NEW.making_charge_per_g IS DISTINCT FROM OLD.making_charge_per_g
     OR NEW.asking_price IS DISTINCT FROM OLD.asking_price THEN
    IF OLD.state NOT IN ('draft','changes_requested','live')  → DH023 'listing % price cannot change in state %'
    IF OLD.state = 'live' AND NEW.state IS DISTINCT FROM OLD.state → DH023 'listing % price and state cannot change together'
    IF OLD.state = 'live' AND listing_price_locked(OLD.listing_id) → DH023 'listing % price is locked by a market-maker approval'
  END IF

trg_listing_price_recorded  AFTER UPDATE OF making_charge_per_g, asking_price ON listing
                            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
  IF OLD.state = 'live' AND (a figure is distinct) AND NOT EXISTS (
       SELECT 1 FROM listing_price_change c
        WHERE c.listing_id = NEW.listing_id AND c.txid = txid_current()
          AND c.new_value = COALESCE(NEW.making_charge_per_g, NEW.asking_price))
  → DH023 'listing % price changed without a history row'
```

The existing CHECKs `listing_price_shape` and `listing_amounts` keep the figure valid in the database.

### 1.4 `down()`

Refuses with a `RuntimeException` when `listing_price_change` has a row ("Spec 022 price changes exist: this migration cannot be rolled back."). Otherwise drops both listing triggers and their functions, `listing_price_locked()`, the immutability trigger and function, and the table.

### 1.5 Settings, permissions, seeders

None. No setting (D22-3), no permission (Story 2 reuses `listing.*`), no seed data.

## 2. Models and factories

- **New `App\Models\ListingPriceChange`**: table `listing_price_change`, key `change_id`, `$timestamps = false`, casts `old_value`/`new_value` `decimal:4`, `changed_at` `immutable_datetime`; `listing()`, `customer()` (actor), `staff()`; `field` cast to the new enum. No `update`/`delete` path in code.
- **`Listing`**: `priceChanges(): HasMany` ordered by `change_id`; `priceField(): string` (`making_charge_per_g` for gold, else `asking_price`); `currentPriceFigure(): string`.
- **Factory**: `ListingPriceChangeFactory` (for staff-detail and migration tests; inserts with the seller as actor in the elevated test scope). `ListingFactory` gains `approvedForMarketMakers()` if the spec 019 tests do not already provide one (reuse theirs when they do).

## 3. Enums and constants

| Where | Addition |
|---|---|
| `App\Enums\ListingPriceField` (new) | `MAKING_CHARGE_PER_G = 'making_charge_per_g'`, `ASKING_PRICE = 'asking_price'`; `for(PieceCategory)` |
| `App\Enums\AuditEvent` | `LISTING_PRICE_CHANGED = 'listing.price_changed'`, label "Listing price changed", category `LISTINGS` |
| `App\Exceptions\DomainApiException` | `listingHasRequests()` 409 `listing_has_requests` "Someone has already asked to buy this piece, so its price can't change."; `listingPriceLocked()` 409 `listing_price_locked` "This piece's price is fixed for now. Contact us if you need to change it."; `listingNotLiveForPriceChange()` 409 `listing_not_live` "Only a live piece's price can be changed."; `listingPriceHistoryImmutable()` 409 `listing_price_history_immutable` |
| `bootstrap/app.php` | `DH023` in both SQLSTATE maps (R7 of research) |

## 4. State

No new listing state, no new `listing_transition` row (D22-1). The price change is not a state move and writes no `listing_state_change` row; `state_changed_at` and `listed_at` do not move (the piece keeps its place in "newest first" and its age for the market-maker rule).
