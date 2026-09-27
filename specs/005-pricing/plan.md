# Implementation Plan: Pricing — Settings, Gold Prices, Price Feed, Price Math

**Branch**: `feature/pricing` (backend + dashboard) | **Date**: 2026-09-27 | **Spec**: [spec.md](./spec.md)

## Summary

This feature has five parts:
- **Settings with history**: the schema §3 catalogue, changed with a reason, grouped as rates or operations.
- **An immutable gold price record**: the 24K bid and ask, fed every minute by the provider (the same two calls the previous platform made). A manual price is accepted only while the feed is down, with a dynamic confirmation above the deviation threshold.
- **Per-karat, per-side adjustments**: fixed or percentage.
- **One pure price calculator**: Part 3 §2, amended for bid/ask.
- **Three Dashboard views**: Gold pricing, Commission rates, and the Karats price column.

The Customer App is not affected. Switches, price locks and founder alerts are out of scope.

## Technical Context

**Language/Version**: PHP 8.3+ / Laravel 12 (backend); Vue 3 + TypeScript + Vuetify (dashboard)
**Primary Dependencies**:
- bcmath (money maths, scale 8, half-up to 4 dp)
- the Laravel HTTP client (feed)
- the Laravel scheduler (`everyMinute`)
- Spatie permission (5 catalogue codes)
- TanStack Query (dashboard)

**Storage**: PostgreSQL 16. Six tables: `setting`, `setting_history`, `gold_price`, `manual_gold_price`, `karat_price_adjustment` (+ its history), `price_feed_status`. None has customer rows, so there is no RLS.
**Testing**: Pest:
- a database-free calculator suite (SC-001);
- HTTP feature tests per endpoint;
- feed tests with `Http::fake`.

Dashboard: type-check, lint and build.
**Target Platform**: Linux containers / Laragon; the scheduler must run `schedule:run` every minute in each environment.
**Project Type**: Web service + staff SPA
**Performance Goals**: a feed tick < 15 s (one HTTP login at most every 10 min + one price call); `GET /gold-prices/current` < 100 ms.
**Constraints**:
- data, not code: no rate, deadline or adjustment literal in logic;
- credentials only in the environment;
- the feed has priority over manual prices;
- money is never a float.

**Scale/Scope**: 5 karats, ~20 settings, ~1,440 feed checks a day (rows only on a change); 10 endpoints; 2 new Dashboard pages + 1 column.

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Staff writes are audited with the staff actor; the feed command runs under the system actor | ✅ |
| II. Staff authz by permission data | Five new catalogue codes, seeded, and editable from the Dashboard; the confirmer rule is a setting | ✅ |
| III. Docs are the source of truth | Schema additions, the Part 3 §2 amendment and the new Part 4 (price feed) are written **before** code (R9); the blueprint is not used | ✅ (tracked) |
| IV. Foundation before modules | Settings and prices come before listings, the ledger and settlement | ✅ |
| V. Test the boundary and the money | Calculator worked examples to the piastre; HTTP tests for every endpoint; the feed tested against fakes; no floats | ✅ |
| Reversible migrations | `down()` drops the tables, triggers and seeds | ✅ |
| Secrets | Provider credentials stay in `.env` only — never in code, docs, tests, Postman or Git; `.env.example` has empty keys; tests use `Http::fake`; the staging check waits for the product owner to fill `.env`; the provider password is to be rotated (owner TODO) | ✅ |
| No assumptions from names | The provider's identity (Evolve vs the old host `mngm.com`) is pending confirmation; behaviour follows the observed calls only | ✅ (TODO tracked in research R5) |

## Project Structure

### Documentation (this feature)
```text
specs/005-pricing/{spec,plan,research,data-model,quickstart}.md, contracts/dashboard-pricing.md, checklists/requirements.md, tasks.md (next)
```

### Source Code
```text
backend
  docs/Database schema/{01_schema_core,00_schema_full}.sql       # tables first (III)
  docs/Technical Spec/dahab-spec-part3-logic.md (§2 amended) · dahab-spec-part4-integrations.md (new, price feed)
  database/migrations/2026_09_28_0000{10,20,30}_*.php            # settings; gold prices; adjustments + feed status
  app/Enums/{SettingKey,SettingGroup,PriceSource,AdjustmentKind,AdjustmentSide}.php, StaffPermission (+5), AuditEvent (+4)
  app/Models/{Setting,SettingHistory,GoldPrice,ManualGoldPrice,KaratPriceAdjustment,KaratPriceAdjustmentHistory,PriceFeedStatus}.php
  app/Support/Pricing/{PriceCalculator,MarketPrice,KaratPricing,PricingRates,Piece,KaratPrices,PriceBreakdown,Money}.php
  app/Support/Pricing/{PricingContext,Settings}.php                 # DB loaders
  app/Contracts/GoldPriceFeed.php · app/Services/PriceFeed/ProviderGoldPriceFeed.php · config/services.php (gold_feed)
  app/Console/Commands/PullGoldPriceFeed.php · routes/console.php (schedule)
  app/Actions/Pricing/{ChangeSetting,ChangeKaratAdjustments,EnterManualPrice,ConfirmManualPrice,RecordFeedPrice}Action.php
  app/Actions/Reference/CreateKaratAction.php (zero adjustments for a new karat)
  app/Http/Controllers/Api/V1/Dashboard/{SettingController,GoldPriceController,KaratAdjustmentController}.php
  app/Http/Requests/Dashboard/Pricing/*.php · app/Http/Resources/Pricing/*.php
  routes/api.php · postman · .env.example · docs (Part 2 §10, features/pricing.md, CLAUDE.md)
  tests/Unit/Pricing/PriceCalculatorTest.php · tests/Feature/Pricing/*
dashboard
  src/types/{api,pricing}.ts · src/api/endpoints.ts · src/services/pricing.service.ts · src/composables/usePricing.ts
  src/pages/pricing/index.vue (Gold pricing) · src/pages/rates/index.vue (Commission rates) · src/components/pricing/*
  src/pages/karats/index.vue (Price today column) · router · nav · PERMISSIONS (+5) · errors · docs/06, CLAUDE.md
```

**Structure Decision**: The existing layering on both sides, plus a new `Pricing` domain. The feed is behind an interface, so tests and the future production host swap it without code changes.

## Phase 0 / Phase 1 outputs

[research.md](./research.md) (R1–R9), [data-model.md](./data-model.md), [contracts/dashboard-pricing.md](./contracts/dashboard-pricing.md), [quickstart.md](./quickstart.md).

**Post-design Constitution re-check**: no new violations. The Part 3 §2 amendment is a documented product decision (spec Clarifications, 2026-09-27), not drift.

## Complexity Tracking

| Addition | Why needed | Simpler alternative rejected because |
|---|---|---|
| Separate `manual_gold_price` table | The price record must be immutable, but a manual request changes state (pending → effective/lapsed) | A mutable status on `gold_price` breaks "never edited" |
| `price_feed_status` table | "Feed down" gates a money action | Cache can be flushed, which would silently allow manual prices |
| Server-side preview endpoint | The Dashboard must not re-implement the price maths | Computing in the UI duplicates a Backend business rule |
