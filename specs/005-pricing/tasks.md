# Tasks: Pricing — Settings, Gold Prices, Price Feed, Price Math

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)
**Tests**: required (Constitution V). Within each story, tests are written first and must fail before the implementation.
**Standing rules for every task**:
- No provider credential anywhere except `.env`. That means none in code, docs, tests, fixtures, Postman or Git; tests use `Http::fake` with dummy values.
- The provider's identity is pending confirmation (research R5 TODO), so nothing is named or inferred from "Evolve".
- Money is bcmath strings only, never floats.
- Every endpoint updates Postman in the same task (CLAUDE.md).

## Phase 1: Setup — docs first (Constitution III)

- [X] T001 Schema docs: in `docs/Database schema/01_schema_core.sql` §3 (and the `00_schema_full.sql` mirror):
  - `setting.updated_by` FK;
  - `setting_history.reason TEXT NOT NULL`, the `changed_by` FK and the append-only trigger note;
  - the new tables `gold_price` (with `recorded_by NOT NULL`), `manual_gold_price`, `karat_price_adjustment`, `karat_price_adjustment_history`, `price_feed_status` (data-model);
  - the `price_correction.*` seed rows replaced by a pointer to `karat_price_adjustment`;
  - the new keys `manualprice.confirmer_must_differ` (bool true), `manualprice.pending_expiry_hours` (24), `pricefeed.stale_after_minutes` (5).
- [X] T002 [P] Technical Spec Part 3 §2 (`docs/Technical Spec/dahab-spec-part3-logic.md`): rewrite for the 24K bid/ask, `× purity ÷ 0.999`, per-karat per-side adjustments (fixed or percent), and the gold-with-diamond protected value at `mid × W`. Add a note restating §3.3/§3.4 inputs (bid = ask = 5,994; 21K ±13.125 fixed). Cite spec 005's Clarifications.
- [X] T003 [P] New `docs/Technical Spec/dahab-spec-part4-integrations.md`, price feed section only:
  - the two observed calls;
  - fields `bidPrice` and `askPrice`;
  - validation;
  - schedule, staleness and failure handling;
  - the credentials policy (`.env` only);
  - **open items**: provider identity pending (Evolve vs the old host), unit to verify on staging, token lifetime unknown, and rotating the old password.

  The other integrations (Rapaport, IGI, ETA) are listed as not yet written.
- [X] T004 [P] `.env.example` + `config/services.php`: the `gold_feed` block (`base_url`, `username`, `password`, `timeout` = 10) reading `GOLD_FEED_*`. `.env.example` has the keys empty. `phpunit.xml`/tests set no real values.

## Phase 2: Foundational (blocks every story)

- [X] T005 Migration `2026_09_28_000010_create_settings.php`: `setting` and `setting_history` with the §3 columns, FKs and the append-only trigger on history; seed per research R1 (without `price_correction.*`, with the three new keys). Reversible.
- [X] T006 Migration `2026_09_28_000020_create_gold_prices.php`:
  - `gold_price`, with checks (`bid > 0`, `ask ≥ bid`, manual requires `manual_gold_price_id`), `recorded_by UUID NOT NULL REFERENCES staff` (Constitution I), the index, and an update/delete-refusing trigger;
  - `manual_gold_price`, with checks, a partial unique index on one `pending`, and a trigger allowing only `pending` → `effective | superseded | lapsed` plus the confirm columns;
  - `price_feed_status`.

  Reversible.
- [X] T007 Migration `2026_09_28_000030_create_karat_price_adjustments.php`: `karat_price_adjustment` (PK `(karat_code, side)`, checks on `side`/`kind`, `percent > −100`) and `karat_price_adjustment_history` (append-only trigger). Seed buy −15 fixed and sell +15 fixed for every existing karat. Reversible.
- [X] T008 [P] Enums + catalogue:
  - `SettingKey` (type, unit, group, range, for every seeded key) and `SettingGroup`;
  - `PriceSource`, `AdjustmentSide`, `AdjustmentKind`, `ManualPriceStatus`;
  - `StaffPermission` +5, per research R7 (label, group "Pricing", seed roles);
  - `AuditEvent` +4;
  - `DomainApiException`: `noGoldPrice` (409), `priceInverted` (422), `priceFeedHealthy` (409), `manualPriceNotPending` (409), `confirmerMustDiffer` (403).
- [X] T009 Fix the 004/002 tests the 5 new seed permissions change: the expected permission lists in `StaffDashboardAccessTest`, `StaffAuthorizationMigrationTest`, `StaffListTest`, `StaffRoleAssignmentTest`, `RoleEscalationTest`, `RoleManagementTest`. Lands in the same change as T008 (otherwise the suite goes red). Keep the full suite green.
- [X] T010 [P] Models (+ factories where tests need them): `Setting`, `SettingHistory`, `GoldPrice` (`scopeCurrent`), `ManualGoldPrice`, `KaratPriceAdjustment`, `KaratPriceAdjustmentHistory`, `PriceFeedStatus`; `Karat::adjustments()` relation; `KaratFactory::afterCreating` inserts fixed-0 adjustments on both sides (mirrors `CreateKaratAction`).
- [X] T011 `app/Support/Pricing/Settings.php`: typed reads of current values by `SettingKey` (numeric as a bcmath string, bool). This is the only way code reads a setting (FR-005). A test asserts that the `SettingKey` cases and the seeded table rows are the same set.

**Checkpoint**: migrate fresh/rollback as `dahab`; the suite is green.

## Phase 3: US4 — One calculator (P1) 🎯 foundation for every price screen

- [X] T012 [P] [US4] `tests/Unit/Pricing/PriceCalculatorTest.php` (database-free, SC-001):
  - Part 3 §3.3 → buyer 55,631.2500, seller proceeds 54,684.7500, spread 262.5000, commission 600, VAT 84;
  - Part 3 §3.4 at 9.9 g;
  - percentage adjustments on both sides;
  - a real bid/ask gap;
  - karat prices × purity ÷ 0.999 for all 5 seeded karats;
  - pure diamond;
  - gold with diamond (mid-price protection; value above gold floored at 0);
  - a commission raised to the minimum;
  - a waiver (no minimum);
  - the invariant on every case;
  - an inverted karat → `price_inverted`; no price → `no_gold_price`;
  - rounding half-up at 4 dp, including a weight with 3 dp whose residue lands in the spread while the invariant still holds exactly.
- [X] T013 [US4] `app/Support/Pricing/` value objects (`Money` bcmath helper, `MarketPrice`, `Adjustment`, `KaratPricing`, `PricingRates`, `Piece`, `KaratPrices`, `PriceBreakdown`) and `PriceCalculator` (pure) — until T012 passes.
- [X] T014 [US4] `app/Support/Pricing/PricingContext.php`: loads the current `gold_price`, every karat with its purity and adjustments, and the rates from `Settings`; `karatPrices()` and `breakdown(Piece)`. Plus `tests/Feature/Pricing/PricingContextTest.php` (DB: the latest effective price wins; a karat with no adjustment row fails loudly; disabled karats are priced).

## Phase 4: US2 — Manual price while the feed is down (P1)

- [X] T015 [P] [US2] `tests/Feature/Pricing/ManualGoldPriceTest.php`, covering every acceptance scenario of US2:
  - the first price with no confirmation → 201;
  - within the threshold → 201, audited, previous and deviation recorded;
  - above it → 202 `manual_price_confirm_required`, and the price is not current;
  - confirm by another holder → 200 current;
  - the same person with `confirmer_must_differ` on → 403, off → 200;
  - no confirm permission → 403;
  - a superseded / lapsed / overtaken pending → 409 `manual_price_not_pending`;
  - `change_pct` input;
  - ask < bid / ≤ 0 → 422; no reason → 422 `reason_required`;
  - feed healthy → 409 `price_feed_healthy`; feed not configured → allowed;
  - COO / Operations → 403.
- [X] T016 [US2] `app/Support/Pricing/FeedHealth.php` (not configured / healthy / down, from `price_feed_status` + `pricefeed.stale_after_minutes`); `EnterManualPriceAction` and `ConfirmManualPriceAction`, both under `pg_advisory_xact_lock('dahab.gold_price')`. They mark an older pending request as superseded. A pending request past `expires_at` is treated as lapsed by every read (computed, no write), and its status moves to `lapsed` only inside the next write transaction. `recorded_by` = the confirmer, or the entrant when no confirmation was needed. The confirmer rule reads the setting.
- [X] T017 [US2] `EnterManualPriceRequest` (bid/ask or change_pct, reason), `ManualGoldPriceResource`, `GoldPriceResource`, `GoldPriceController::manual|confirm` with `#[OA]`, routes, and Postman "Enter manual price" / "Confirm manual price".

## Phase 5: US1 — The live feed (P1)

- [X] T018 [P] [US1] `tests/Feature/Pricing/PriceFeedTest.php` (`Http::fake`, dummy credentials):
  - a changed bid/ask → new `feed` row current;
  - unchanged → no row, `last_success_at` updated;
  - auth refused / 500 / timeout / missing field / bid ≤ 0 / ask < bid → nothing written, `last_failure_at` + error (with no credential or token in it);
  - not configured → skipped, state `not_configured`;
  - a 401 on price → one re-auth;
  - the token is cached;
  - a manual price current + a feed reading → the feed takes over and the pending request is superseded;
  - the schedule is registered every minute without overlap.
- [X] T019 [US1] `app/Contracts/GoldPriceFeed.php`, `GoldQuote`, `app/Services/PriceFeed/ProviderGoldPriceFeed.php` (only the two observed calls; provider-neutral names; token cache 10 min), binding in a service provider, and `RecordFeedPriceAction` (lock, write only on change with `recorded_by` = the system actor, supersede pending, update status).
- [X] T020 [US1] `app/Console/Commands/PullGoldPriceFeed.php` (`pricing:pull-feed`, runs under `DatabaseActor::elevate('system')`), the schedule in `routes/console.php`, and a CLAUDE.md/quickstart note that `schedule:run` must run every minute.

## Phase 6: US3 — Rates and per-karat adjustments (P1)

- [X] T021 [P] [US3] `tests/Feature/Pricing/RateSettingsTest.php`:
  - Finance changes `commission.gold_pct` / `vat.pct` / `manualprice.*` with a reason → saved, history, audit;
  - no reason → 422; out of range / wrong type → 422;
  - unknown key → 404;
  - COO → 403 on rates keys;
  - the next `PricingContext` calculation uses the new value (SC-007).
- [X] T022 [P] [US3] `tests/Feature/Pricing/KaratAdjustmentTest.php`:
  - PUT both sides (fixed → percent) with a reason → saved, history per side, audit, the response shows the new prices;
  - a change that inverts at the current price → 422 `price_inverted`;
  - percent ≤ −100 → 422;
  - unknown karat → 404; COO → 403;
  - the history endpoint;
  - `CreateKaratAction` (004) now creates zero adjustments.
- [X] T023 [US3] `ChangeSettingAction` (group permission check, range/type validation from `SettingKey`, history + audit, one transaction) and `ChangeKaratAdjustmentsAction` (lock, inversion check via `PricingContext`, history + audit); `CreateKaratAction` inserts `fixed 0` on both sides.
- [X] T024 [US3] Requests/resources/controllers with `#[OA]`:
  - `SettingController` (`index`, `update`, `history`);
  - `KaratAdjustmentController` (`update`, `history`);
  - `GoldPriceController::current|index|preview` (`preview` = no write; bid/ask or change_pct; optional adjustments).

  Also: routes, and the Postman folder **Dashboard → Pricing** (every request, dummy variables, saved ids).

## Phase 7: US5 — Operational settings (P2)

- [X] T025 [US5] `tests/Feature/Pricing/OperationalSettingsTest.php`:
  - the COO changes `deadline.seller_reply_hours` / `deposit.buyer_pct` with a reason → saved, history, audit;
  - Finance → 403 on operations keys;
  - `GET /settings` lists every key with group, unit, range, last changed by;
  - Operations can view, not change.

  (Endpoints from T024; this story adds only the coverage and any missing validation.)

## Phase 8: US6 — Price next to each karat (P3)

- [X] T026 [US6] `tests/Feature/Pricing/CurrentPricesTest.php`: `GET /gold-prices/current` shape per the contract (price, feed state, pending, karats with market/published prices, adjustments, inverted); no price → `price: null`, `karats: []`; without `pricing.view` → 403.

## Phase 9: Dashboard (depends on Phases 4–8 being merged into the branch; the contract is frozen at T024)

- [X] T027 Dashboard foundation:
  - `src/types/api.ts` (+ the pricing wire types) and `src/types/pricing.ts`;
  - `src/api/endpoints.ts`;
  - `src/services/pricing.service.ts` (money kept as strings; formatting only);
  - `src/composables/usePricing.ts` (queries + mutations; invalidate `pricing` and `reference` keys);
  - `PERMISSIONS` +5;
  - `services/errors.ts` (`no_gold_price`, `price_feed_healthy`, `manual_price_not_pending` → conflict, `confirmer_must_differ` → forbidden, `price_inverted` → validation);
  - `components/pricing/pricingErrors.ts`.
- [X] T028 `src/pages/pricing/index.vue` (Gold pricing), with components under `src/components/pricing/`:
  - the karat prices table (market bid/ask, sellers get, buyers pay, difference, inverted flag);
  - an adjustments editor per karat and side (kind + value, previewed through `POST /gold-prices/preview`, saved with a reason);
  - the feed status card (healthy / down / not configured, last reading);
  - the manual price form (bid+ask or change %, server preview of every karat with disabled ones greyed, required reason; 201 → toast, 202 → pending banner);
  - the pending card with Confirm (gated on `gold_price.confirm`; disabled for the entrant when the setting says so, the Backend still decides);
  - the recent changes list (price history + adjustment history).

  No Rapaport panel. The route is gated on `pricing.view`; controls are gated on their own permissions.
- [X] T029 `src/pages/rates/index.vue` (Commission rates), per the design: the "Commission" panel (gold %, stones %, minimum, VAT) and the "Deadlines and deposits" panel. Each field shows its unit, range and last changed by. Save asks for a reason. Each panel is gated on its group permission (`pricing.rates.manage` / `settings.manage`), and the rest is read-only for viewers. Include the per-key history.
- [X] T030 Karats page: the "Price today, sellers get" column from `GET /gold-prices/current`, shown only with `pricing.view`; "No price yet" when none. Router: replace the `pricing` and `rates` placeholders. Nav: unhide Gold pricing and Commission rates (gated on `pricing.view`). Leave Switches hidden.
- [X] T031 Dashboard gates: `npm run type-check`, lint (changed files), `npm run build`; update `docs/06-BACKEND-API-INTEGRATION.md` and `CLAUDE.md` status.

## Phase 10: Polish & cross-cutting

- [X] T032 Docs: Part 2 §10 as-built (routes, permissions, `202 pending` replacing the `409` flow, error codes); `docs/features/pricing.md` (impact: Backend/Dashboard yes, Customer App not affected + the future Backend requirement; deploy note: existing databases run `db:seed --class=DashboardRolesAndPermissionsSeeder` after migrating, for the 5 new permissions); the CLAUDE.md "Current state" section.
- [X] T033 Secrets check: grep the diff and the Git history of `feature/pricing` for the provider host credentials, the username and any password-like literal, and for `GOLD_FEED_*` values in tracked files. Confirm `.env` is gitignored. It must come back empty (SC-006).
- [X] T034 Quality gates (`laravel-quality-gates`):
  - Pint on the changed files;
  - the full Pest suite;
  - migrate fresh + rollback as `dahab`;
  - `composer swagger:generate` + the `OpenApiGenerationTest` path/schema lists + the `PrincipalIsolationTest` route count (+10);
  - a browser check of Gold pricing, Commission rates and the Karats column against the running Backend (manual prices only; feed not configured), timing a full manual-price entry with preview (SC-005: under 2 minutes) and `GET /gold-prices/current` (under 100 ms).
- [ ] T035 (Blocked on the product owner) Staging check, quickstart 7:
  - after the owner has put the credentials in `.env`, run `pricing:pull-feed` once;
  - confirm the unit (EGP per gram of 24K) and the field names;
  - record the result in Part 4.

  Not required to merge; required before production. The owner's TODOs stay open in Part 4: rotate the password, confirm the provider's identity.

## Dependencies

```
Phase 1 (docs) ──► Phase 2 (migrations, enums, models, Settings, test fixes)
                        │
                        ▼
                 Phase 3 US4 calculator + PricingContext ──┬──► Phase 4 US2 manual price ──┐
                                                           ├──► Phase 5 US1 feed ─────────┤ (US1 needs FeedHealth from T016)
                                                           └──► Phase 6 US3 rates/adjust ─┤
                                                                   └──► Phase 7 US5 (uses T024 endpoints)
                                                                   └──► Phase 8 US6 (uses T024 current)
                                                                                           ▼
                                               Phase 9 Dashboard (needs the final contract: T017 + T024 + T026)
                                                                                           ▼
                                               Phase 10 polish (T032–T034); T035 waits for the owner
```

- **Backend → Dashboard**: the Dashboard starts only after T024/T026 fix the response shapes. T027 can begin once T017 + T024 are merged in the backend branch. T028 needs US1 (feed state), US2 (manual/confirm) and US3 (adjustments, preview). T029 needs US3 + US5. T030 needs US6.
- **US1 after US2**: the feed reuses `FeedHealth` and the supersede logic from T016.
- **004 touchpoint**: T023 changes `CreateKaratAction` (zero adjustments), and T022 tests it.
- **Permission lists**: T009 must land with T008, or the suite goes red (as in 004).

## Parallel opportunities

- T002, T003, T004 (different docs/config files) in parallel with T001.
- T008 (+ T009) and T010 in parallel after the migrations.
- Test tasks T012, T015, T018, T021, T022 can be written in parallel once Phase 2 is done.
- Phases 4, 5 (after T016) and 6 can be implemented in parallel by different people; they share only `GoldPriceController` (T017/T024), which is edited sequentially.
- Dashboard T029 and T030 can go in parallel after T027; T028 is the largest and independent of them.

## Implementation strategy

1. **MVP** = Phases 1–4 (docs, tables, calculator, manual price): the platform can hold and publish a price with no feed.
2. Then US1 (feed) and US3 (rates/adjustments) — the full Backend contract.
3. Then US5/US6 and the Dashboard in one pass against the frozen contract.
4. Polish and gates. The staging check (T035) is scheduled with the product owner separately.

**Counts**: 35 tasks.

| Phase | Tasks |
|---|---|
| Setup | 4 |
| Foundational | 7 |
| US4 | 3 |
| US2 | 3 |
| US1 | 3 |
| US3 | 4 |
| US5 | 1 |
| US6 | 1 |
| Dashboard | 5 |
| Polish | 4 |
