# Data model: Buy requests (spec 011)

Mirrors `docs/Database schema/04_schema_market.sql` §8–§9, `05_schema_security.sql` (transitions, RLS) and
`01_schema_core.sql` (types, settings), with the additions marked **(011)**. The docs are updated first, in the same
change (Constitution III). One migration: `2026_10_04_000010_create_buy_requests.php`.

## Types

- `buy_request_state` — `queued`, `accepted`, `released_not_chosen`, `released_declined`, `released_expired`,
  `withdrawn_by_buyer` (schema, unchanged). Final: every value but `queued`.
- `order_state` — the schema's 11 values **+ `cancelled_staff` (011, final: staff cancelled the acceptance)**; only
  `awaiting_delivery` and `cancelled_staff` are reached in this feature.

## buy_request

| Column | Type | Notes |
|---|---|---|
| `buy_request_id` | UUID PK | `gen_random_uuid()` |
| `listing_id` | UUID FK listing | frozen |
| `buyer_id` | UUID FK customer | frozen; RLS owner |
| `state` | `buy_request_state` | default `queued`; moves only along `buy_request_transition` |
| `queue_position` | INTEGER | from `listing_queue_seq.next_pos`; `UNIQUE (listing_id, queue_position)`; frozen |
| `locked_unit_rate` | NUMERIC(18,4) **NULL (011)** | the karat's sell-side rate per gram at join; NULL for pure diamond (R5) |
| `locked_total_price` | NUMERIC(18,4) | calculator total at join; frozen |
| `deposit_amount` | NUMERIC(18,4) | `deposit_positive CHECK (> 0)`; `deposit.buyer_pct` of the price, half-up to 2 dp; frozen |
| `deposit_hold_txn_id` | UUID FK ledger_transaction **NOT NULL (011)** | the `deposit_hold` entry, posted just before the insert (see ledger FKs) |
| `deposit_acceptance_id` **(011)** | UUID FK agreement_acceptance, NOT NULL, UNIQUE | the deposit terms accepted (R7) |
| `requested_at` | TIMESTAMPTZ | default `now()` |
| `seller_reply_deadline` | TIMESTAMPTZ | `requested_at + deadline.seller_reply_hours` hours |
| `resolved_at` | TIMESTAMPTZ NULL | stamped by the guard on leaving `queued` |
| `notify_when_free` | BOOLEAN | default false; set on leave |
| `free_notified_at` **(011)** | TIMESTAMPTZ NULL | stamped when the notify-when-free message was sent (R12) |

Constraints and indexes: `deposit_positive`; `UNIQUE (listing_id, queue_position)`; partial unique
`one_active_request_per_buyer_listing (listing_id, buyer_id) WHERE state IN ('queued','accepted')` →
`already_in_queue`; **(011)** `buy_request_resolved_shape CHECK ((state = 'queued') = (resolved_at IS NULL))`;
`idx_buy_request_listing_state`, `idx_buy_request_buyer`; **(011)** partial `idx_buy_request_due
(seller_reply_deadline) WHERE state = 'queued'` for the sweep.

Triggers:
- `trg_buy_request_guard` **(011)** BEFORE INSERT/UPDATE/DELETE — insert only as `queued`; state only along
  `buy_request_transition`; frozen columns (above); no delete; stamps `resolved_at = clock_timestamp()` on leaving
  `queued`. SQLSTATE `DH005`.
- `trg_sync_queue` AFTER INSERT / UPDATE OF state — **(011, changed)** recounts `listing.active_queue_count` only
  (R3).
- `trg_buy_request_money` **(011)** deferred constraint trigger AFTER INSERT / UPDATE OF state — at commit, a new row
  has `deposit_hold_txn_id` pointing at a `deposit_hold` entry for this request, and a row that moved to a released /
  withdrawn state has one `deposit_release` entry tied to it (`ledger_transaction.buy_request_id`), in this
  transaction; an `accepted` request gets a `deposit_release` only in the transaction that moves its order to
  `cancelled_staff` (checked by the same trigger on `"order"` AFTER UPDATE OF state). It reads with
  `set_config('app.rls_scope', 'ledger', true)` and restores the previous scope, like spec 008's triggers. SQLSTATE
  `DH005`.

## buy_request_transition (schema, seeded)

`queued → accepted | released_not_chosen | released_declined | released_expired | withdrawn_by_buyer`.

## listing (exists — spec 010) — changes

- Moves used by this feature (all already in `listing_transition`): `live → reserved`, `reserved → live`,
  `reserved → accepted`, `reserved → withdrawn`, `reserved → suspended_hold`, `suspended_hold → live`.
- `trg_listing_queue_consistent` **(011)** deferred constraint trigger: at commit, `state = 'live'` ⇒ no queued
  request; `state = 'reserved'` ⇒ at least one. Reads under `queue` scope set inside the function. SQLSTATE `DH004`.
- `listing_change_recorded()` (spec 010) **(011, changed)**: reads `listing_state_change` under `queue` scope set
  inside the function (restored after), so a move written by one customer on another's listing is always visible to
  the check.
- New `listing_transition` rows **(011)**: `('accepted','live','staff cancelled the acceptance; back on the market')`,
  `('accepted','withdrawn','staff cancelled the acceptance and withdrew the piece')` — used only by the staff
  cancellation, which writes the staff reason as the history note. The existing `listing_change_note_required` CHECK
  already demands a note for a staff move to `withdrawn`; for `accepted → live` the CHECK is extended to require a
  note when `from_state = 'accepted'`.
- `listing_change_note_required` unchanged (staff take-down from reserved still needs its reason).
- History notes written by this feature (constants on `ListingStateChange`): `buy_request_queued`,
  `queue_emptied`, `buy_request_accepted`, `account_suspended` (existing).

## listing_queue_seq (exists — spec 010)

`next_pos` read and incremented under `FOR UPDATE` on join.

## order **(created by 011 from the schema)**

`order_id` UUID PK · `order_ref` TEXT UNIQUE (`DH-YYYY-NNNNNN`, `order_ref_seq`) · `listing_id` FK · `buy_request_id`
FK UNIQUE · `seller_id`, `buyer_id` FK customer · `state order_state` default `awaiting_delivery` · `branch_id` FK
branch (`trg_order_branch_subset`) · `accepted_by` FK customer (the seller) · `accepted_at` · `reach_branch_deadline`
· `locked_total_price` · `balance_due_deadline`, `collect_deadline`, `completed_at` (NULL here) · `created_at`.
Indexes `idx_order_state`, `idx_order_seller`, `idx_order_buyer`. `order_transition` seeded + `trg_order_transition`,
plus **(011)** `('awaiting_delivery','cancelled_staff','staff cancelled the acceptance; deposit refunded')`.
**(011)** columns `cancelled_by UUID REFERENCES staff`, `cancelled_at TIMESTAMPTZ`, `cancel_reason TEXT` with CHECK
`order_cancel_shape ((state = 'cancelled_staff') = (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND
cancel_reason IS NOT NULL))`.
RLS: `order_isolation` FOR ALL `USING/WITH CHECK (elevated OR seller_id = me OR buyer_id = me)`.

## ledger_transaction (exists — spec 008) — changes

FKs `lt_request_fk (buy_request_id) → buy_request` **DEFERRABLE INITIALLY DEFERRED** (the join pre-generates the
request id, posts the hold naming it, then inserts the request with `deposit_hold_txn_id`), `lt_order_fk (order_id) →
"order"`. Entries written:

| Event | Lines | Actor |
|---|---|---|
| `deposit_hold` | buyer `cust_available −d`, buyer `cust_held +d` | the buyer |
| `deposit_release` (leave) | buyer `cust_held −d`, buyer `cust_available +d` | the buyer |
| `deposit_release` (decline, not chosen, seller withdraw) | same | the seller |
| `deposit_release` (take-down, suspension) | same | the staff member |
| `deposit_release` (expiry) | same | the system actor |
| `deposit_release` (staff cancel of an accepted order) | same, with `order_id` | the staff member |

Each entry carries `listing_id` and `buy_request_id`; the accepted request's hold stays open (tied to the order in the
orders spec).

## legal_document / agreement_acceptance (exist — spec 010)

Seed `('deposit_agreement', 1, <EN>, <AR>, false, <system actor>)`. One acceptance per request, context `buy_request`.

## setting (exists — spec 005)

New key **`buyrequest.price_tolerance_pct`** = 0.5, unit `percent`, group operations, range 0–100 (`SettingKey`,
seeded by the migration, history row by the system actor).

## RLS summary (all FORCE)

| Table | Policies |
|---|---|
| `buy_request` | `buy_request_isolation` (elevated OR buyer = me); `buy_request_queue_read` SELECT (scope `queue`); `buy_request_queue_move` UPDATE (scope `queue` AND (buyer = me OR the listing's seller = me)) |
| `"order"` | `order_isolation` (elevated OR seller = me OR buyer = me) |
| `listing` | + `listing_queue_read` SELECT (scope `queue`, `listed_at IS NOT NULL`); + `listing_queue_move` UPDATE (scope `queue`, state live/reserved before and after) |
| `listing_queue_seq` | + `listing_queue_seq_queue_read` SELECT, `listing_queue_seq_queue_bump` UPDATE (scope `queue`) |
| `listing_state_change` | + `listing_state_change_queue_insert` INSERT (scope `queue`, `actor_customer_id = me`, no staff) + SELECT (scope `queue`) |
| `listing_branch_option` | + SELECT (scope `queue`) |
| `listing_media` | + SELECT (scope `queue`, not private) |
| `customer` | + `customer_queue_read` SELECT (scope `queue`, customer has a buy request) |

`CustomerTableIsolationTest` covers `buy_request` (buyer_id) and `"order"` (seller_id, buyer_id).

**Scope rules (research R2)**: a buyer's own reads run in the `customer` scope (isolation policy only); queue
operations run as `DatabaseActor::queue(fn () => DB::transaction(...))` so deferred checks fire inside the scope; the
new deferred triggers set their own read scope.

## State diagram

```
listing:   live ──first request──▶ reserved ──accept──▶ accepted (order: awaiting_delivery)
             ▲                        │ │ │
             └──queue emptied─────────┘ │ └──take-down / seller withdraw──▶ withdrawn (final)
                                        └──seller suspended──▶ suspended_hold ──reinstated──▶ live

request:   queued ──accept (head)──────────▶ accepted
                  ├─accept of another──────▶ released_not_chosen
                  ├─decline (head) / take-down / seller suspended ─▶ released_declined
                  ├─deadline passed────────▶ released_expired
                  └─buyer leaves───────────▶ withdrawn_by_buyer

order:     awaiting_delivery ──staff cancel (order.cancel)──▶ cancelled_staff  (deposit refunded;
                                                              listing accepted → live | withdrawn)
```
