# Implementation Plan: Controls and content

**Branch**: `feature/app-content` in all three repositories (created locally 2026-10-10 from Backend `28d058b`, Dashboard `e74282d`, Flutter `3bf8f7c`) | **Date**: 2026-10-10 (revised after review decisions D1–D7) | **Spec**: [spec.md](spec.md)

**Status**: Plan only. Nothing is implemented, committed or pushed. Market-maker purchases (6B) are **not planned for build** until Finance signs off D3.

## Summary

One content platform, then the controls that sit on it.

- **Texts** (Q1–Q4): a registry of readable keys whose defaults are the app's own strings (manifest generated from the Flutter catalogue, synced into the Backend), versions with one draft per key, *Publish changes* as one bundle, revert-to-default as a marker that ends the override, a public bundle whose version tag covers both publishes and setting values, an app-side `TextsController` that caches it and falls back to the code. FAQ entries and notification templates are rows of the same registry (`kind`).
- **Notifications**: every customer message moves to a PHP template catalogue with `{placeholders}`, rendered at send time with a never-throwing fallback; a golden test proves byte-identical output before any override; templates carrying a code or link are read-only (D7).
- **Legal**: CEO-only publishing (D1) of whole new versions (immutable by trigger), acceptance counts, the public list extended without breaking it, a computed acceptance requirement (first acceptance for everyone — D2 — and material changes — Terms §13) enforced on every customer write, and sign-up recording the versions the app showed. The gate and the app screen ship before any terms are published.
- **Controls** (after the platform): category stops/pause/stop-everything as schema rows, the D4 pause rule, the D5 founder email and banner, four visibility settings; market-maker **staff side** (6A); People to watch with figures, no thresholds (D6).
- **Clean-up**: the app's staff screens and payout-averages toggle are removed; Invite stays hidden.

## Impact analysis

```
Backend:          YES — 5 migrations (one per phase); Support/Content/*, Actions/Content/*, Actions/Legal/*, Http/Middleware/EnsureLegalAccepted,
                  Actions/Controls/*, Actions/MarketMakers/* (staff side), Actions/PeopleToWatch/*; Notifications/Templates/* + the 20 customer
                  notification classes + CategoryNotification + FounderAlertNotification; edits to Create/SubmitListingAction, SendBuyRequestAction,
                  extension-request create/refuse, withdrawal submit/release, MarketQuery and every market read, SubmitCustomerRegistrationAction,
                  me/login resources, ReferenceController, StaffPermission, AuditEvent, SettingKey/SettingGroup
Database:         YES — app_text_key/version/bundle (+guard), legal_document guard, category_control (schema §14), promo_code,
                  promo_code_use, market_maker_approval (schema §15), 4 settings, 9 permission codes (per phase)
API:              YES — new public, customer and dashboard endpoints; optional fields; new refusal codes on existing POSTs (per phase)
Dashboard:        YES — App text (texts, Notifications, Help tabs), Terms and versions, Switches + Stop-everything banner, Promo codes and
                  Market maker approvals (staff side), People to watch panel; permission strings per phase; nav entries un-hidden
Customer App:     YES — text service + per-stage key catalogues, Help live, legal acceptance screen + sign-up ids, controls/visibility honoured,
                  admin screens and payout-averages toggle removed, Invite hidden, tests. Market-maker mark/filter/code: 6B only
Auth:             YES (light) — customer.legal middleware on every state-changing customer route
Permissions:      YES — content.edit, legal.publish, category.stop_new, category.pause, platform.stop_everything, visibility.manage,
                  promo.manage, market_maker.approve, people_to_watch.view (seeds per D1)
API models/types: YES — Dashboard src/types/{content,legal,controls,marketMaker,peopleToWatch,staff}.ts; Flutter lib/models/{texts,legal,controls}.dart, me (legal_acceptance_required)
```

**Classification**: mostly non-breaking. **Potentially breaking**: new refusal codes on existing POSTs (`legal_acceptance_required`, `category_stopped`, `category_paused`, `platform_stopped`) and new permission strings — each lands in the phase whose Dashboard and app updates handle it. Nothing renamed or removed; no existing response enum gains a value.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 12; Vue 3 + TypeScript (Vuetify 4, Pinia, TanStack Vue Query); Dart ^3.11, Flutter Web (provider, go_router, http, shared_preferences).
**Primary Dependencies**: Sanctum, Spatie Permission (`StaffPermission`), Horizon, l5-swagger, Pest 3; existing `RecordAuditLogAction`, `idempotent` middleware, `DatabaseActor` scopes, `Settings`, `ListingPricer`/`PriceCalculator`, `AnswerExtensionRequestAction`, notification pipeline with `RendersInbox`, `LangController` dictionary.
**Storage**: PostgreSQL 16; content tables without RLS (staff-only writes), `promo_code_use` forced RLS; tests on **`dahab_wt019`**, dev on **`dahab_wt019_dev`** only — **not present in this environment; PostgreSQL is not running here** (owner creates them before implementation).
**Testing**: Pest at the HTTP boundary; Dashboard type-check/lint/build (the repo has no test runner); Flutter analyze/test/build web.
**Project type**: three repositories, one platform.
**Performance Goals**: bundle read ≤ 1 query + a settings read when unchanged and ≤ 3 otherwise; publish O(drafts) in one transaction; template render adds ≤ 1 cached lookup per message.
**Constraints**: a missing key never breaks a screen or a send; published history immutable; legal bodies placeholder-free; no money behaviour change in this plan (market-maker settlement waits for Finance); no RLS weakened; every `down()` refuses while it would destroy data.
**Scale/Scope**: ~1,800 app strings (7 stages), ~90 notification messages × 3 parts × 2 languages, 8 legal codes, 9 FAQ seed entries.

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I Named actor | Every draft, publish, legal version, control, refusal on pause (pausing staff member), approval and code change names the staff member; acceptances name the customer; the sync command uses the system actor and writes only the registry | PASS |
| II Isolation / permission data | Nine permission codes, seeded per phase and editable; legal CEO-only by seed (D1); `promo_code_use` forced RLS; content holds no customer data | PASS |
| III Docs are the source of truth | Technical Spec "Changed by spec 019" (incl. D1 overriding the "both founders" readings), schema SQL, api-contract per phase, `docs/features/app-content.md`, `CLAUDE.md` | PASS (gated by tasks) |
| IV Foundation before modules | Market-maker staff side builds on schema §15 and Part 3 §8; purchases deferred to a Finance-approved design; every endpoint has Action, FormRequest/Resource, `#[OA]`, happy + refusal Pest test | PASS |
| V Test the boundary and the ledger | No ledger change in this plan; notifications covered by golden + override tests; every state change through HTTP | PASS |

Post-design re-check: **PASS**. Migrations whose reversal would destroy data refuse instead (R16) — the Constitution's "explicit note" case, recorded here.

## Project Structure

### Documentation

```text
specs/019-app-content/
├── spec.md · plan.md · research.md (R1–R17) · data-model.md · quickstart.md
├── contracts/app-content-api.md
├── checklists/requirements.md
└── tasks.md
```

### Source code

```text
dahab-backend/
├── database/migrations/2026_10_12_0000{10,20,30,40,50}_*.php     # one per phase (R15)
├── database/data/app_text_keys.json                              # copy of the Flutter manifest (R1)
├── app/Console/Commands/SyncAppTexts.php                         # app-texts:sync
├── app/Support/Content/{TextRegistry,PlaceholderValidator,SettingPlaceholders,BundleBuilder}.php
├── app/Actions/Content/{SaveDraftAction,DiscardDraftAction,RevertTextAction,PublishTextsAction,CreateFaqEntryAction,ReorderFaqAction}.php
├── app/Notifications/Templates/{Catalogue,TemplateRenderer}.php + the 20 customer notification classes
├── app/Actions/Legal/{PublishLegalVersionAction,AcceptLegalVersionAction}.php, app/Support/Legal/LegalAcceptanceRequirement.php
├── app/Http/Middleware/EnsureLegalAccepted.php (customer.legal)
├── app/Actions/Controls/{SetCategoryControlAction,ClearCategoryControlAction,SetVisibilityAction}.php, app/Support/Controls/Controls.php
├── app/Notifications/{CategoryNotification,FounderAlertNotification}.php
├── app/Actions/MarketMakers/{CreatePromoCodeAction,UpdatePromoCodeAction,ApproveForMarketMakersAction}.php   # 6A only
├── app/Actions/PeopleToWatch/ListPeopleToWatchAction.php
├── app/Http/Controllers/Api/V1/Dashboard/{AppTextController,LegalDocumentController,CategoryControlController,VisibilityController,PromoCodeController,MarketMakerController,PeopleToWatchController}.php
├── app/Http/Controllers/Api/V1/Market/ReferenceController.php (app-texts, controls, legal list), Customer/AccountController.php (legal acceptances)
├── edits: Listings/{Create,Submit}ListingAction, BuyRequests/SendBuyRequestAction, Orders/Staff/AnswerExtensionRequestAction + the customer
│          extension-request action, Withdrawals/*, Support/Listings/MarketQuery (+ every market read), Auth/Customer/SubmitCustomerRegistrationAction,
│          me/login resources, Enums/*
└── tests/Feature/{Content,Legal,Notifications/Templates,Controls,MarketMakers,PeopleToWatch,Migrations}/… + inventories extended

dahab-dashboard/
├── src/types/{content,legal,controls,marketMaker,peopleToWatch}.ts, staff.ts; src/api/endpoints.ts
├── src/services/*.service.ts, src/composables/use*.ts for each
├── src/pages/{content,docs,switches,promos,market-maker}/index.vue; People to watch panel in src/pages/inspections/index.vue
├── src/components/{content,legal,controls,market-maker,inspections}/…, Stop-everything banner in the app layout
└── src/mock/nav.ts: un-hide content, docs, switches, promos, mm with their permissions

dahab-flutter/
├── lib/core/text/{text_key.dart,texts_controller.dart,texts_store.dart}, lib/core/text/keys/<area>.dart (per stage)
├── assets/text/keys.json (generated + checked by test), lib/services/api/content_api.dart
├── lib/features/orders/{order_screen.dart,order_help_screens.dart} (Stage 1), later stages per research R14
├── lib/features/account/{settings_screens.dart (Help), legal_accept_screen.dart}, sign-up sends shown ids, controls/visibility handling
├── delete lib/features/admin/admin_screens.dart, its routes and menu rows, the payout-averages toggle
├── lib/widgets/mock_flag.dart, lib/routing/*, lib/services/{mock_repositories,repositories}.dart
└── test/{text_manifest_test,texts_test,stage1_keys_test,legal_accept_test,controls_test}.dart, flows/mock/routes tests updated
```

## Phases and order

| Phase | Content | Depends on |
|---|---|---|
| **0 Setup** | verify DBs (stop and ask if missing); baseline full run once; `docs/features/app-content.md` | — |
| **1 Content platform + Stage 1** (US1, US2) — **first release** | Flutter catalogue + manifest for the spec 018 screens **first**, then migration 10 (`content.edit`), registry + sync, drafts, publish, revert marker, bundle with settings-aware tag, Dashboard App text, Flutter text service | 0 |
| **2 Legal** (US3) | migration 20 (`legal.publish`); publish, list, history, counts; requirement + middleware + accept endpoint + sign-up ids; Dashboard Terms and versions; Flutter acceptance screen. **Release rule**: no terms/privacy/material publication until this phase's app is live | 1 |
| **3 Notification templates** (US4) | golden test → catalogue → renderer → 20 classes → Dashboard Notifications tab; code/link templates read-only (D7) | 1 |
| **4 FAQ and clean-up** (US5, US9) | FAQ rows + Help live; remove app admin screens and toggle; hide Invite; mock flags | 1 |
| **5 App text stages 2–7** | per research R14, one stage per change set | 1 |
| **6 Switches** (US7) | migration 30 (four codes, settings); controls + effects (D4, D5) + notifications; Dashboard Switches + banner; Flutter states | 1, 4 |
| **7 Market makers, staff side** (US6, 6A) | migration 40 (two codes); codes, approvals, use log (empty), cost (zero); Dashboard pages | 1 |
| **7B Market-maker purchases** (6B) | **[SIGN-OFF Finance] — not started** until D3's allocation, ledger, invoice and VAT treatment are confirmed in writing | 7, 6 |
| **8 People to watch** (US8) | migration 50 (one code); list + export; Dashboard panel | 1 |
| **9 Docs and verify** | OpenAPI, Postman, api-contract, Technical Spec, schema SQL, `CLAUDE.md`; full run once; Step 5 report | all |

Phases 2, 3, 4, 5, 7 and 8 are independent after Phase 1. Inside each: Backend → contract → Dashboard → Customer App. Every phase is shippable alone (R17).

## Risks and watch-points

- **Notification refactor** (90 messages): the golden test lands before the first class changes.
- **Key drift**: keys are append-only (Q1); the manifest test fails on a removed key unless it is listed as retired; changed defaults are flagged for review.
- **Legal gate** affects every customer when `terms` v1 is published (D2) — release order R8/FR-027a; a route-inventory test proves every write is gated except accept and sign-out.
- **Pause visibility** is a query predicate: a market-route inventory test proves every market read honours it.
- **Stop everything** blocks withdrawal release and new withdrawals; founders are emailed — staff `email` must be set for founders (checked in a test and in the Dashboard on save).
- **Visibility switches** for data that does not exist yet (views, Rapaport) only hide what exists.
- **Market-maker purchases** move money and are out of this plan's build until Finance signs off.
- **Arabic**: every new or changed Arabic string stays AR-DRAFT until the owner approves; seed drafts are never published automatically.
