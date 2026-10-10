# Tasks: Controls and content (spec 019) — revised after review decisions D1–D7

**Input**: `specs/019-app-content/` — spec.md (Q1–Q5, D1–D7), plan.md, research.md (R1–R17), data-model.md, contracts/app-content-api.md, quickstart.md
**Repos**: `dahab-backend/` (B), `../dahab-dashboard/` (D), `../dahab-flutter/` (F) — branch `feature/app-content` in each.
**Tests**: required (spec *Test requirements*, Constitution V). Pest at the HTTP boundary on `dahab_wt019` only, `DB_DATABASE/DB_USERNAME/DB_PASSWORD` passed explicitly, sequential; targeted files between phases; full suite at most twice (T002 baseline, T094 end).
**Every task**: `#[OA]` on each new/changed endpoint; the Postman request in the same task (`postman/README.md`); audit via `RecordAuditLogAction` naming the version or value replaced (admin-roles §6); `idempotent` on every write; error codes, audit events and permission strings are added **in the phase that uses them** (to the catalogue, the inventory tests and `docs/platform/api-contract.md`); no commit or push unless the owner asks; no AI attribution in any git message.
**Blocked tasks** are marked `⛔ [SIGN-OFF]`: they are listed for completeness and MUST NOT be started before the named sign-off.

Format: `- [ ] T### [P?] [US?] description — repo`

**Phase numbers**: plan.md phases → task phases: plan 0 → 1 · plan 1 → 2–4 · plan 2 → 5 · plan 3 → 6 · plan 4 → 7 · plan 5 → 8 · plan 6 → 9 · plan 7 → 10 · plan 7B → 10B · plan 8 → 11 · plan 9 → 12. data-model.md and research.md use the plan's numbers.

## Phase 1: Setup

- [ ] T001 Verify `dahab_wt019` and `dahab_wt019_dev` exist and are owned by `dahab` (`psql -l`); if either is missing, STOP and ask the owner — never fall back to `dahab` — B
- [ ] T002 Baseline: `DB_DATABASE=dahab_wt019 … composer test` once (sequential, ~30 min) and `flutter test` in F; record in `specs/019-app-content/baseline.md` (expected: 3 Invoices failures, 2 Flutter withdraw failures) — B, F
- [ ] T003 [P] `docs/features/app-content.md` from `docs/features/_TEMPLATE.md` (impact analysis, classification per phase, consumers) — B

## Phase 2: Foundational for the first release

- [ ] T004 [P] Flutter `lib/core/text/text_key.dart` (`TextKey(key, en, {placeholders, maxLength})`), `lib/core/text/texts_controller.dart` (bundle in memory; `resolve(TextKey, lang, params)` → published value when present and every placeholder fillable, else `LangController.t(en)` filled), `lib/core/text/texts_store.dart` (`shared_preferences`), `context.k(TextKey, [params])`; provider in `lib/app.dart` — F
- [ ] T005 Stage 1 catalogue `lib/core/text/keys/orders_after_collection.dart`: one `TextKey` per visible sentence/label/button of `_FreeRelistCard` and the buyer/seller notes (`lib/features/orders/order_screen.dart`), `FreeRelistScreen` (form, estimate line, errors, success dialog) and `RateScreen` (`lib/features/orders/order_help_screens.dart`); names `order.free_relist.*`, `order.no_fee.*`, `order.rating.*` (Q1); replace literals with `context.k(...)`; no customer-visible word changes; no invite/referral content — F
- [ ] T006 Manifest: `test/text_manifest_test.dart` writes/validates `assets/text/keys.json` (key, kind `app`, area, where, default EN, default AR = `arabicFor(en)`, placeholders, `max_length = max(2 × len, 40)`); fails on a removed key unless listed as retired; copy to B `database/data/app_text_keys.json` — F, B
- [ ] T007 Migration `database/migrations/2026_10_12_000010_app_content.php` per data-model.md §1: `app_text_key` (incl. `read_only`, `default_changed_at`), `app_text_version` (incl. `is_default_marker`, `draft_version`; CHECK both texts non-empty unless `is_default_marker`; CHECK publisher/published_at when `state <> 'draft'`; partial uniques one draft / one published per key), `app_text_bundle`; trigger `app_text_version_guard` (drafts updatable/deletable; `published` → `superseded` only; `superseded` frozen; non-draft DELETE refused; INSERT refused for `read_only` keys — SQLSTATE `DH017`); seed **only** `content.edit` (COO, Operations); `down()` refuses when any non-draft version exists, detaches the permission before deleting it — B
- [ ] T008 [P] Mirror T007 in `docs/Database schema/00_schema_full.sql` and `05_schema_security.sql` ("Changed by spec 019") — B
- [ ] T009 [P] `content.edit` in `app/Enums/StaffPermission.php` (label *Edit app text*, group Content, seed COO + Operations); `tests/Feature/Authorization/PermissionCatalogueTest.php` — B
- [ ] T010 [P] Models `app/Models/{AppTextKey,AppTextVersion,AppTextBundle}.php` (casts, relations, scopes `published()`, `draft()`) — B
- [ ] T011 [P] Audit events `content.draft_saved`, `content.draft_discarded`, `content.published`, `content.reverted` and category `CONTENT` in `app/Enums/{AuditEvent,AuditCategory}.php`; audit-event inventory test — B
- [ ] T012 `app/Support/Content/SettingPlaceholders.php`: name → `SettingKey` + formatter for `{commission_gold_pct}`, `{commission_stone_pct}`, `{commission_minimum_egp}`, `{vat_pct}`, `{deposit_buyer_pct}`, `{reach_branch_hours}`, `{buyer_pay_days}`, `{collect_weeks}`, `{seller_return_weeks}`, `{free_relist_hours}`, `{withdrawal_pause_hours}`, `{weight_tolerance_pct}`, `{saved_max}`; `fingerprint()` = sha1 of the current values (R3) — B
- [ ] T013 `app/Support/Content/PlaceholderValidator.php` (`{snake_case}`, balanced braces, `content_invalid_placeholder`, `content_missing_required_placeholder`, `content_language_missing`, `content_too_long`, `content_unchanged`) + `tests/Unit/Content/PlaceholderValidatorTest.php`; add these codes to the error catalogue and `docs/platform/api-contract.md` — B
- [ ] T014 [P] Dashboard: `content.edit` in `src/types/staff.ts` and the permission labels — D

## Phase 3: US1 — Staff change a customer text and publish it (P1) 🎯 first release

**Independent test**: US1 scenarios 1–10.

- [ ] T015 [US1] `app/Console/Commands/SyncAppTexts.php` (`app-texts:sync`): reads `database/data/app_text_keys.json` (+ the template catalogue once T048 exists); upserts keys, updates defaults and stamps `default_changed_at` when a default changes, sets `retired_at` for keys no longer in the sources, never deletes; system actor; `tests/Feature/Content/SyncAppTextsTest.php` — B
- [ ] T016 [US1] Actions `app/Actions/Content/{SaveDraftAction,DiscardDraftAction,RevertTextAction}.php`: one draft per key, `draft_version` check → `409 content_draft_changed`; `read_only` → `409 content_key_read_only`; revert `default` → default-marker draft; revert `<version>` → draft copying that version; validation T013; audit — B
- [ ] T017 [US1] `app/Actions/Content/PublishTextsAction.php` (advisory lock, validate all drafts → `422 content_invalid` per key, bundle row, supersede, publish, audit with the version replaced; `422 content_nothing_to_publish`) — B
- [ ] T018 [US1] `AppTextController` + FormRequests + Resources + routes `/dashboard/app-texts*` (list with filters and `default_changed_since_override`, draft PUT/DELETE, revert, publish, history, CSV export) and `GET /dashboard/content/placeholders` (`content.edit`); `#[OA]`; Postman — B
- [ ] T019 [P] [US1] Tests `tests/Feature/Content/{AppTextDraftTest,AppTextPublishTest,AppTextRevertTest,AppTextPermissionsTest,AppTextExportTest,AppTextConcurrencyTest}.php`: drafts invisible; publish-all; revert-to-default removes the key from the bundle and survives a later default change; restore; DH017 on UPDATE/DELETE of history; stale draft 409; every validation code; 403 without `content.edit`; two publishes at once → consecutive bundles, no lost draft; replay with the same `Idempotency-Key`; audit names the replaced version — B
- [ ] T020 [P] [US1] Dashboard `src/types/content.ts`, `src/services/content.service.ts`, `src/composables/useContent.ts`, endpoints — D
- [ ] T021 [US1] Dashboard `src/pages/content/index.vue` + `src/components/content/{TextTable,TextEditDialog,PublishBar,TextHistoryDrawer}.vue`: area chips, Where/English/Arabic/Edit, draft badge, *default changed* badge, placeholder chips with live values, *Publish changes*, revert/restore, export; *Legal, locked* rows link to Terms and versions; un-hide `content` in `src/mock/nav.ts` (`permission: 'content.edit'`) — D

## Phase 4: US2 — The customer app shows published texts and never breaks (P1) 🎯 first release

**Independent test**: US2 scenarios 1–7.

- [ ] T022 [US2] `app/Support/Content/BundleBuilder.php` + `GET /reference/app-texts` in `ReferenceController`: overrides of kinds `app`/`faq` only, default markers omitted, `faq[]`, `settings{}`, `ETag "<bundle_version>-<fingerprint>"`, `304`; `#[OA]`; Postman — B
- [ ] T023 [P] [US2] `tests/Feature/Content/AppTextBundleTest.php`: drafts and notification keys never served; ETag changes on publish **and** on a setting change; 304; retired keys and default markers excluded; query count ≤ 3 — B
- [ ] T024 [US2] Flutter `lib/services/api/content_api.dart`; refresh on start, on `AppLifecycleState.resumed`, every 15 minutes; keep the saved copy on failure (Q3) — F
- [ ] T025 [P] [US2] Flutter tests `test/texts_test.dart` (network off → code text; saved bundle; override EN/AR; setting-only change refreshes values; unfillable placeholder → default; unknown key ignored; revert marker → code default) and `test/stage1_keys_test.dart` (no bare literals in the Stage 1 widgets) — F
- [ ] T026 [US2] Deploy note in `docs/features/app-content.md`: `php artisan app-texts:sync` after migrations; run it on `dahab_wt019_dev` — B

**Checkpoint — first release (T001–T026)**: staff publish Stage 1 texts; customers see them; outages never blank a screen; no other permission, code or route exists yet.

## Phase 5: US3 — Legal publishing and required acceptance (P1)

**Independent test**: US3 scenarios 1–10.

- [ ] T027 [US3] Migration `2026_10_12_000020_legal_publishing.php`: trigger `legal_document_immutable` (`DH018`), index `(code, version DESC)`, seed `legal.publish` with **no role** (CEO only, D1); `down()` refuses when a `legal_document` row exists beyond the migration-seeded v1 declarations, drops the trigger first; mirror schema SQL — B
- [ ] T028 [US3] `legal.publish` in `StaffPermission` (label *Edit legal text and publish a new version*, group Content, no seed role); audit events `legal.published`, `legal.accepted`; error codes `legal_acceptance_required`, `legal_document_not_current`, `legal_acceptance_not_required`, `legal_body_required`, `legal_code_unknown`, `legal_version_conflict`; inventories — B
- [ ] T029 [US3] `LegalDocumentCode`: add the four declaration codes with `kind()`; `GET /reference/legal-documents` keeps `data[]` and adds `declarations[]` — B
- [ ] T030 [US3] `app/Actions/Legal/PublishLegalVersionAction.php` (lock latest row of the code; version = max+1; `UNIQUE(code, version)` violation → `409 legal_version_conflict`; audit with sha256 + length per body and the version replaced) — B
- [ ] T031 [US3] `LegalDocumentController`: list (live version, date, publisher, material, accepted count), versions history, publish (`legal.publish` only; reads also `content.edit`); `#[OA]`; Postman — B
- [ ] T032 [US3] `app/Support/Legal/LegalAcceptanceRequirement.php` (R8: `first` when no acceptance of `terms`/`privacy`, `material` when a newer material version exists) and `legal_acceptance_required[]` on `GET /customer/me` and sign-in / OTP / refresh payloads — B
- [ ] T033 [US3] `POST /customer/me/legal-acceptances` (`AcceptLegalVersionAction`; context `first_acceptance` or `material_reaccept`; allowed when suspended) — B
- [ ] T034 [US3] Middleware `customer.legal` on every state-changing customer route; passes only the acceptance POST, `logout`, `logout-all` and GETs (Terms §13); route-inventory test that every customer write is gated except those — B
- [ ] T035 [US3] Registration final step: optional `terms_legal_doc_id`, `privacy_legal_doc_id` → `signup` rows; not live → `422 legal_document_not_current`; absent → nothing recorded — B
- [ ] T036 [P] [US3] Tests `tests/Feature/Legal/{PublishLegalVersionTest,LegalDocumentListTest,FirstAcceptanceTest,MaterialReacceptTest,SignupAcceptanceTest,LegalGuardTest,LegalConcurrencyTest}.php`: CEO-only seed (COO 403); numbering; DH018; counts; existing `*_required` refusals still fire; first acceptance for a customer with none (D2); material; gate + pass-throughs; sign-up with, without and with a stale id; two publishes of one code at once — B
- [ ] T037 [P] [US3] Dashboard `legal.publish` string; `src/types/legal.ts`, `src/services/legal.service.ts`, `src/composables/useLegal.ts`, `src/pages/docs/index.vue` + `src/components/legal/{LegalVersionDialog,LegalHistoryDrawer}.vue` (design p-docs, material checkbox, a warning that publishing `terms`/`privacy` asks every customer without an acceptance to accept); un-hide `docs` (`anyPermission: ['legal.publish','content.edit']`), *New version* only with `legal.publish` — D
- [ ] T038 [US3] Flutter: `legal_acceptance_required` on the me/session models; `lib/features/account/legal_accept_screen.dart` shown before anything else (keys `legal.accept.*`, Arabic AR-DRAFT per spec Appendix B); any `409 legal_acceptance_required` routes there; sign-up sends the shown `terms`/`privacy` ids; legal list shows declarations; `test/legal_accept_test.dart` — F
- [ ] T039 [US3] Copy spec Appendix A to `docs/legal/terms-5-5-proposal.md` marked "draft for legal review — not published"; seed nothing — B
- [ ] T040 [US3] Release rule in `docs/features/app-content.md` and the Step 5 report: the Phase 5 app must be live before the CEO publishes any `terms`/`privacy` version or any material version; publishing `terms` v1 gates every existing customer (D2) — B

## Phase 6: US4 — Notification templates (P2)

**Independent test**: US4 scenarios 1–6.

- [ ] T041 [US4] Golden test FIRST: `tests/Feature/Notifications/Templates/GoldenMessagesTest.php` renders every event/audience of the 20 customer notification classes (mail subject, mail body, SMS, inbox EN/AR) with fixed inputs into `tests/Fixtures/notification-golden.json`; passes on the unchanged code — B
- [ ] T042 [US4] `app/Notifications/Templates/Catalogue.php`: one entry per `<class>.<event>.<audience>.<part>` with default EN/AR as `{placeholders}`, allowed names, required `{code}`/`{link}`, `inbox: false` and `read_only: true` for codes/links (D7) — B
- [ ] T043 [US4] `app/Notifications/Templates/TemplateRenderer.php` (latest published, cached per bundle version, else default; never throws; setting placeholders live) — B
- [ ] T044 [US4] Refactor `OrderNotification`, `BuyRequestNotification`, `PayoutNotification`, `ListingDecisionNotification`, `AccountNotification`, `WalletNotification` — B
- [ ] T045 [US4] Refactor the 14 single-message classes; T041 still byte-identical — B
- [ ] T046 [P] [US4] Tests `tests/Feature/Notifications/Templates/{TemplateOverrideTest,TemplateFallbackTest,ReadOnlyTemplateTest}.php`: override reaches SMS, mail and inbox; corrupt/missing → default; drafting a code/link template → `409 content_key_read_only`; inbox still masks collection codes — B
- [ ] T047 [US4] Dashboard *Notifications* tab (kind `notification`, grouped by event and audience, parts side by side, code/link templates shown read-only with a lock) — D
- [ ] T048 [US4] Extend `app-texts:sync` to the catalogue; run on `dahab_wt019_dev` — B

## Phase 7: US5 + US9 + clean-up (P2)

- [ ] T049 [US5] `CreateFaqEntryAction`, `ReorderFaqAction`; `POST /dashboard/faq`, `PATCH /dashboard/faq/{slug}/position` (`content.edit`, drafts); `is_hidden`; bundle `faq[]` by `position`; `#[OA]`; Postman — B
- [ ] T050 [US5] Seeder (dev DB only): the nine prototype FAQ entries as **unpublished drafts**, numbers replaced by setting placeholders, Arabic AR-DRAFT added to spec Appendix B — B
- [ ] T051 [P] [US5] `tests/Feature/Content/FaqTest.php` — B
- [ ] T052 [US5] Dashboard *Help* tab (add, edit, reorder, hide) — D
- [ ] T053 [US5] Flutter `HelpScreen` from the bundle's `faq`; empty → "Nothing here yet" + Contact us (`help.*`); remove `mockFaq`, `ContentRepository.faq`; `R.help` leaves `mockScreens`; Help menu row `mock: false` — F
- [ ] T054 [US9] Flutter: hide *Invite a friend* and make `R.invite` redirect to Account; rating screen unchanged — F
- [ ] T055 Flutter: delete `lib/features/admin/admin_screens.dart`, routes `R.codes`, `R.codeuses`, `R.mmapprove`, `R.compensate`, their menu rows and the *Show payout averages* toggle; update `mock_flag.dart`, routing, `test/{mock_flags_test,routes_smoke_test}.dart` — F

## Phase 8: US2 continued — App text stages 2–7

Each stage: catalogue file, literals → `context.k`, its `i18n.dart` patterns → keys with placeholders, manifest regenerated (T006), Backend copy + `app-texts:sync`, screen test with network off and one override; no customer-visible word changes.

- [ ] T056 [US2] Stage 2 — Home, Browse, piece page (`home_screen.dart`, `browse_screen.dart`, `detail_screen.dart`, `shared/{piece_card,listing_ui}.dart`) — F, B
- [ ] T057 [US2] Stage 3 — Orders and buying (rest of `features/orders/*`, `buy_screens.dart`, `shared/order_card.dart`) — F, B
- [ ] T058 [US2] Stage 4 — Selling (`sell{1,2,3}_screen.dart`, My listings; commission numbers → placeholders) — F, B
- [ ] T059 [US2] Stage 5 — Wallet and money (`features/wallet/*`, `bank_screens.dart`, invoices) — F, B
- [ ] T060 [US2] Stage 6 — Account, settings, legal list, inbox shell, contact changes, close account — F, B
- [ ] T061 [US2] Stage 7 — Sign-in, sign-up, splash, shared widgets, toasts, errors; repo-wide check that no customer-visible literal is outside a catalogue (allow-list: brand, icons, numbers) — F, B

## Phase 9: US7 — Switches (P3)

**Independent test**: US7 scenarios 1–5.

- [ ] T062 [US7] Migration `2026_10_12_000030_category_controls.php` per data-model.md §3 (`category` NOT NULL as schema §14; `reason`; cleared pair CHECK; partial unique active; clearing-only update trigger); four `visibility.*` settings; seed `category.stop_new` (COO, Operations) and `category.pause`, `platform.stop_everything`, `visibility.manage` (COO) — D1; `down()` refuses when any control row exists or a visibility value differs from its default; mirror schema SQL — B
- [ ] T063 [US7] Enums: four permission codes, `SettingGroup::VISIBILITY`, `SettingKey` cases; generic settings PATCH refuses the group (`setting_not_editable_here`); audit events `control.set`, `control.cleared`, `visibility.changed`; error codes `category_stopped`, `category_paused`, `platform_stopped`, `control_already_active`; inventories — B
- [ ] T064 [US7] `app/Support/Controls/Controls.php` and Actions `SetCategoryControlAction` (stop everything = one row per category in one transaction), `ClearCategoryControlAction`, `SetVisibilityAction` — B
- [ ] T065 [US7] Effects: listing create/submit → `409 category_stopped|platform_stopped`; `MarketQuery` and every market read hide paused `live` pieces (requested pieces stay and accept new requests); buy requests → `409 platform_stopped`; on pause every `waiting` extension request in the category refused via `AnswerExtensionRequestAction::refuse` (actor = pausing staff, note "category paused") and new ones `409 category_paused` (D4); stop everything → new withdrawals and staff release `409 platform_stopped`, customer cancel allowed (D5) — B
- [ ] T066 [US7] `CategoryNotification` (sellers with listings in a paused category, after commit) and `FounderAlertNotification` (mail to every `is_founder` staff on set and clear of stop everything, D5) — B
- [ ] T067 [US7] `CategoryControlController`, `VisibilityController`, public `GET /reference/controls`; `#[OA]`; Postman — B
- [ ] T068 [P] [US7] Tests `tests/Feature/Controls/{CategoryControlTest,PauseEffectsTest,PauseExtensionRequestTest,StopEverythingTest,FounderAlertTest,VisibilityTest,ControlsPermissionsTest,MarketReadInventoryTest}.php`: locked-price orders finish; requested pieces stay requestable; refusals name the pausing staff and leave deadlines; release refused and cancel allowed; founders mailed on set and clear; COO allowed, Operations refused for pause; every market route honours the pause — B
- [ ] T069 [P] [US7] Dashboard strings; `src/types/controls.ts`, service, composable, `src/pages/switches/index.vue` + `src/components/controls/{CategoryControlDialog,StopEverythingDialog,VisibilityTable}.vue`; Stop-everything banner in the app layout from `/reference/controls`; un-hide `switches` — D
- [ ] T070 [US7] Flutter: read `/reference/controls` with the bundle refresh; stop message on the sell flow; paused banner (`app.stopped.*`, AR-DRAFT); handle the new 409s on listing, buy, withdrawal and extension requests; honour `visibility.sharing`, `visibility.listing_stats`, `visibility.payout_averages`; `test/controls_test.dart` — F

## Phase 10: US6 — Market makers, staff side (6A) (P3)

- [ ] T071 [US6] Migration `2026_10_12_000040_market_makers.php`: `promo_code`, `promo_code_use`, `market_maker_approval` **exactly as schema §15** (CHECK `kind IN ('first_sale','market_maker')` unchanged) + code format, `monthly_cap_egp > 0`, deactivated pair CHECK; `promo_code_use` forced RLS (staff only); append-only triggers on uses and approvals; seed `promo.manage`, `market_maker.approve` (Finance); `down()` refuses when any code, use or approval exists; mirror schema SQL — B
- [ ] T072 [US6] Models, enums, audit events `promo.created`, `promo.updated`, `market_maker.approved`, error codes `promo_kind_not_available`, `promo_customer_not_market_maker`, `already_approved`, `listing_too_new`, `listing_not_live`; inventories — B
- [ ] T073 [US6] `CreatePromoCodeAction` (market_maker only, tied customer of type `market_maker`), `UpdatePromoCodeAction` (cap, on/off), `ApproveForMarketMakersAction` (live, aged per `marketmaker.min_list_age_days`, numbers seen) — B
- [ ] T074 [US6] `PromoCodeController`, `MarketMakerController` (`/dashboard/promo-codes*`, `/uses`, `/dashboard/market-maker/{queue,approvals,cost}`; views *not measured*); `#[OA]`; Postman — B
- [ ] T075 [P] [US6] Tests `tests/Feature/MarketMakers/{PromoCodeTest,ApprovalTest,CodeUseLogTest,CostTest,PromoRlsTest}.php` (first_sale refused, non-MM customer refused, aged gate, unique approval, empty log, zero cost, customers read no use rows) — B
- [ ] T076 [P] [US6] Dashboard strings; `src/types/marketMaker.ts`, service, composable, `src/pages/promos/index.vue` (market-maker codes only) and `src/pages/market-maker/index.vue` (queue, Price is sound / Leave, cost panel); un-hide `promos` and `mm`, drop the static badge — D

## Phase 10B: Market-maker purchases (6B) — ⛔ [SIGN-OFF Finance, D3]

Not started until Finance confirms in writing the spread allocation to the dealer and its ledger, invoice and VAT treatment. Kept here so the work is visible.

- [ ] T077 ⛔ [SIGN-OFF] [US6] 6B migration: `buy_request.promo_code`, `promo_code_use.buy_request_id/amount_egp/device_fingerprint` and whatever ledger kind Finance specifies; schema docs — B
- [ ] T078 ⛔ [SIGN-OFF] [US6] Code use at `SendBuyRequestAction` under a row lock on `promo_code` (cap counts uses whose buy request is open or settled); `422 promo_code_invalid {reason}`; use rows allowed/blocked — B
- [ ] T079 ⛔ [SIGN-OFF] [US6] Settlement per Finance's confirmed treatment (D3 recommendation: commission/VAT 0, positive spread to the dealer's `cust_available`, negative spread on `dahab_spread`, buyer invoice only) — B
- [ ] T080 ⛔ [SIGN-OFF] [US6] `GET /customer/me/market/listings` (`mm_approved`, filter) — B
- [ ] T081 ⛔ [SIGN-OFF] [US6] Tests: ledger lines balance, seller proceeds equal an ordinary sale's, spread credited to the dealer, cap race (two requests at once), another account blocked and logged, stop everything refuses MM buys — B
- [ ] T082 ⛔ [SIGN-OFF] [US6] Flutter mark, filter and code field (`buy.mm.*`, AR-DRAFT); refusal reasons in plain words — F

## Phase 11: US8 — People to watch (P3)

- [ ] T083 [US8] Migration `2026_10_12_000050_people_to_watch.php` seeding `people_to_watch.view` (COO) — D1; enum case; audit event `people_to_watch.viewed`; `down()` detaches and deletes the code — B
- [ ] T084 [US8] `ListPeopleToWatchAction` per research R12 (threshold `flag.pattern_txn_threshold`; four figures; sort by strongest ratio; no thresholds — D6) + `GET /dashboard/people-to-watch` and `/export` (audited read); `#[OA]`; Postman — B
- [ ] T085 [P] [US8] `tests/Feature/PeopleToWatch/PeopleToWatchTest.php` (each figure, threshold boundary, period filter, sort order, no writes, IGI 403) — B
- [ ] T086 [US8] Dashboard string; panel `src/components/inspections/PeopleToWatchTable.vue` in `src/pages/inspections/index.vue`, rendered only with `people_to_watch.view`; period chips; export — D

## Phase 12: Polish and verification

- [ ] T087 `tests/Feature/Migrations/Spec019RollbackTest.php`: each of the five migrations rolls back on an empty database and refuses with data present — B
- [ ] T088 `composer swagger:generate`; Postman complete for every new/changed route — B
- [ ] T089 [P] Technical Spec "Changed by spec 019": Part 1 §4.1–§4.3 (codes and seeds per D1, superseding the "both founders" reading for legal and the CEO-only cells for pause/stop/visibility), Part 2 §10 and §12, Part 3 §8 (6A built, 6B awaiting Finance) and §9.3 (D6) — B
- [ ] T090 [P] `docs/platform/api-contract.md` and `docs/features/app-content.md` final — B
- [ ] T091 Dashboard gates: `npm run type-check`, `npm run lint`, `npm run build` — D
- [ ] T092 Flutter gates: `flutter analyze`, `flutter test`, `flutter build web --release` (report the 2 known withdraw failures) — F
- [ ] T093 Backend gates on `dahab_wt019` / `dahab_wt019_dev`: `./vendor/bin/pint --test`, `migrate:fresh --seed`, `app-texts:sync` — B
- [ ] T094 Full `composer test` once (report the 3 known Invoices failures); `CLAUDE.md` current state; Step 5 report listing every AR-DRAFT string and every open sign-off — B

## Dependencies

- Phase 1 → Phase 2 (T004–T006 Flutter catalogue and manifest **before** T007–T015) → Phase 3 → Phase 4 = **first release (T001–T026)**.
- After the first release, Phases 5, 6, 7, 8, 10 and 11 are independent; Phase 9 after Phase 7 (the app's admin toggle goes first); Phase 10B after Phases 9 and 10 **and** Finance's sign-off.
- Phase 5's app must be live before any terms/privacy/material publication (T040).
- Phase 12 last. Inside every phase: Backend → contract → Dashboard → Customer App.

## Parallel examples

- Phase 2: T004 with T008–T011 and T014 once T006 has produced the manifest shape.
- US1: T019 and T020 alongside T016–T018.
- After the first release: Phases 5, 6 and 11 in parallel; stages T056–T061 one after another (shared manifest).

## Implementation strategy

1. **First release** = T001–T026: content platform + Stage 1.
2. Legal (Phase 5) — then the CEO times the first terms publication after the lawyer approves the text.
3. Templates, FAQ and clean-up; stages 2–7 at the owner's pace.
4. Switches, market-maker staff side, People to watch.
5. Market-maker purchases only after Finance signs off D3.
6. Stop and ask on any spec/code conflict; never choose silently.
