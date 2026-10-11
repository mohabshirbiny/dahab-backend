# Research — spec 019 (Controls and content)

Decisions the plan rests on. Each: **Decision · Rationale · Alternatives**. Q1–Q5 = the clarify answers in `spec.md`.

## R1 — Where the key catalogue lives (source of truth for keys and defaults)

- **Decision**: The Flutter code is the source of the customer-app keys and their defaults. Each migrated screen declares its texts as `const TextKey('order.free_relist.card_title', 'Sell it again with no commission')` in a per-area catalogue file (`lib/core/text/keys/<area>.dart`). A Flutter test writes/validates `assets/text/keys.json` (key, area, where, default EN, default AR, placeholders, max length); the Arabic default is `LangController.arabicFor(en)` — the existing dictionary — so no Arabic is duplicated or re-typed. The Backend keeps a copy at `database/data/app_text_keys.json` and an artisan command `app-texts:sync` upserts the registry (adds keys, updates defaults, marks keys missing from the file as `retired`; never deletes). Notification keys are declared in PHP (R6) and synced by the same command.
- **Rationale**: Q1 (readable keys, current strings as defaults, screen by screen). Defaults must equal what the code shows, or "fallback" and "default" would disagree. Generated, checked-in manifests make drift a failing test, not a surprise.
- **Alternatives**: hand-maintained seeder in the Backend (drifts from the app); English-sentence keys (rejected in Q1); runtime registration by the app (the Backend would learn keys only from traffic).

## R2 — Storage model for texts

- **Decision**: `app_text_key` (registry, one row per key, `kind ∈ {app, faq, notification}`, `read_only` for code/link templates — D7), `app_text_version` (key, version, `text_en`, `text_ar`, `is_default_marker`, state `draft|published|superseded`, edited_by/at, published_by/at, bundle_version), `app_text_bundle` (one row per publish). One draft per key (A2): saving updates that draft row while it is a draft; discarding **deletes** it; publish flips every draft to `published` and the previous published row of each key to `superseded` in one transaction under an advisory lock. Guard trigger: a draft may be updated or deleted; a `published` row may only become `superseded`; a `superseded` row never changes; neither may be deleted (`DH017`). **Revert to default** = a draft with `is_default_marker = true` and empty texts; once published it ends the override and the bundle omits the key, so the app shows its own current default (even one changed by a later release). **Restore** = a draft copying an earlier version's texts. A key whose `default_*` changed in a sync after its live override was published is flagged `default_changed_since_override` in the list.
- **Rationale**: Q2; history kept forever; a revert must never pin an old default (review fix).
- **Alternatives**: storing the default text as the override (pins stale wording — rejected in review); JSON blob per bundle (no per-key history).

## R3 — Public bundle read and caching

- **Decision**: `GET /api/v1/reference/app-texts` (no auth, `throttle:public.market`) returns `{ data: { version, published_at, texts: { "<key>": { "en", "ar" } }, faq: [...], settings: { "<placeholder>": value } } }` with published overrides of kinds `app` and `faq` only (never `notification`; default markers omitted). **Version tag**: `ETag: "<bundle_version>-<sha1 of the setting placeholder values>"` — it changes when a text is published **or** any setting behind a placeholder changes; `If-None-Match` equal → `304`. The app stores the last bundle in `shared_preferences`, loads it at start, refreshes on start, on resume and every 15 minutes while open; any failure keeps what it has.
- **Rationale**: Q3; overrides-only keeps the payload small; the settings fingerprint fixes the stale-number bug found in review.
- **Alternatives**: bump the bundle on every setting change (couples the settings module to content); separate settings endpoint (two polls).

## R4 — App-side lookup without rewriting every widget

- **Decision**: `TextsController` (provider) holds the bundle; `context.k(TextKey key, [Map<String,Object?> params])` returns: published value in the current language if present, every `{name}` fillable and non-empty → filled; otherwise `context.t(key.en)` with the same fill. Widgets (`T`, `DRow`, `DNote`, …) keep taking strings; their internal `context.t()` of an already-resolved string is harmless (an override in Arabic is not a dictionary key, so it passes through). A lint-style test per migrated screen asserts no bare string literal remains in that file's widget calls (allow-list for brand names, icons, numbers).
- **Rationale**: FR-005/FR-007 with the smallest change per screen; Arabic defaults keep coming from the existing dictionary.
- **Alternatives**: rewrite widgets to take keys (touches every widget, larger risk); code generation of a typed class (good later, not needed for the slice).

## R5 — Placeholders (Q4)

- **Decision**: `{snake_case}` only. Each key/template lists its allowed names; setting placeholders are a fixed map in `App\Support\Content\SettingPlaceholders` → `SettingKey` with a formatter: `{commission_gold_pct}`, `{commission_stone_pct}`, `{commission_minimum_egp}`, `{vat_pct}`, `{deposit_buyer_pct}`, `{reach_branch_hours}`, `{buyer_pay_days}`, `{collect_weeks}`, `{seller_return_weeks}`, `{free_relist_hours}`, `{withdrawal_pause_hours}`, `{weight_tolerance_pct}`, `{saved_max}`. The bundle carries their current values (`settings: {...}`) so the app fills them; notifications fill them server-side. Validation on draft save and on publish: unknown name → `content_invalid_placeholder`; a template that carries `{code}`/`{link}` must keep it; braces must balance. Legal bodies: no placeholders (FR-022).
- **Rationale**: Q4; the design's "{braces} pulls its number from settings".
- **Alternatives**: ICU MessageFormat (plurals) — not needed by any current string; free-form names (rejected in Q4).

## R6 — Notification templates

- **Decision**: Extract every customer message text into `App\Notifications\Templates\Catalogue` — one entry per `<class>.<event>.<audience>.<part>` (`part ∈ sms, mail_subject, mail_body`) with default EN/AR using `{placeholders}` and its allowed names, the `{code}`/`{link}` requirement and `inbox: false` and `read_only: true` for codes/links (D7). Each notification calls `TemplateRenderer::render($key, $lang, $params)` which uses the latest published version, else the default, and on any problem (missing language, unfillable name, exception) the default; it never throws. **Golden test first**: render every message of every class for fixed inputs before the refactor and assert byte-identical output after it with no overrides. The inbox keeps working unchanged because `RendersInbox` re-renders `toMail`/`toSms`. Rendering happens in the queued job (as today), so a queued message uses the template current at send time — A4 is corrected accordingly (see R13).
- **Rationale**: FR-040–FR-045 with zero behaviour change by default; ~90 messages.
- **Alternatives**: Blade/DB views (HTML not wanted, harder to validate); only override SMS (the brief says SMS, email and inbox).

## R7 — Legal publishing

- **Decision**: `POST /dashboard/legal-documents/{code}/versions` `{ body_en, body_ar, is_material }` (`legal.publish`, idempotent, audited with sha256 of each body and lengths). Version = max+1 under `SELECT … FOR UPDATE` on the code's latest row (or an advisory lock when none). New trigger `legal_document_immutable` refuses UPDATE/DELETE. `LegalDocumentCode` gains the three declarations + `payout_account_declaration` with a `kind` (`document` shown in the legal list | `declaration` ticked in a form); the public list keeps returning only `document` codes plus a new `declarations` array (non-breaking: the existing array is unchanged in shape). Reads `GET /dashboard/legal-documents` (live version, published date, accepted-by count of the live version) and `/{code}/versions` (history; body on request) with `legal.publish` or `content.edit`.
- **Rationale**: FR-020–FR-030; D5 (no draft state in `legal_document`, whole versions only — Q2's note).
- **Alternatives**: a draft column on `legal_document` (schema change for no stated need).

## R8 — Required legal acceptance and sign-up acceptance

- **Decision**: `LegalAcceptanceRequirement::for(Customer)` — for each of `terms` and `privacy` that has a published version: required if the customer has **no** acceptance of that code (D2, context `first_acceptance`) or if a **material** version newer than their latest accepted version exists (Terms §13, context `material_reaccept`). Computed on read; no stored flag. Exposed as `legal_acceptance_required: [{code, version, legal_doc_id, reason: first|material}]` on `GET /customer/me` and the sign-in / OTP / refresh success payloads. `POST /customer/me/legal-acceptances` `{ legal_doc_id }`. Gate: middleware `customer.legal` on **every** state-changing customer route; allowed through: the acceptance POST, sign-out (`logout`, `logout-all`) and all GETs (Terms §13 "before you continue using the platform"; reads let the app show the documents). No other allow-list. Sign-up: the final registration step accepts optional `terms_legal_doc_id` / `privacy_legal_doc_id`; when present and live → `signup` acceptance rows; not live → `422 legal_document_not_current`; absent (older app) → nothing recorded, D2 applies at first sign-in.
- **Rollout constraint**: the gate and the app's acceptance screen ship before any `terms`/`privacy` version or any material version is published; publishing `terms` v1 gates every existing customer at once (D2) — timed by the CEO after the lawyer approves the text.
- **Rationale**: FR-025–FR-027a; D2, D14; the earlier withdrawal/top-up-cancel allow-list was invented and is removed (review).
- **Alternatives**: block sign-in (locks people out of reads); store a flag per customer (fan-out job on publish).

## R9 — Permissions

- **Decision**: new `StaffPermission` cases, each seeded **in the migration of the phase that ships its feature** (so no dead permission appears in Roles early): Phase 1 `content.edit` *Edit app text* (COO, Operations); Phase 2 `legal.publish` *Edit legal text and publish a new version* (no role → CEO only, D1); Phase 6 `category.stop_new` (COO, Operations), `category.pause`, `platform.stop_everything`, `visibility.manage` (COO; the CEO holds every code — D1); Phase 7 `promo.manage` (Finance), `market_maker.approve` (Finance); Phase 8 `people_to_watch.view` (COO, D1). The Dashboard adds each string in the same phase. The People to watch panel sits on the Inspections page, which IGI may open — the panel and its read are gated by `people_to_watch.view` alone.
- **Rationale**: D1, D3 of discovery, admin-roles §3, blueprint §7–8.
- **Alternatives**: reuse `settings.manage` for switches (would hand Stop everything to whoever holds settings).

## R10 — Switches (category controls and visibility)

- **Decision**: migrate `category_control` **as the schema has it** (`category` NOT NULL) plus a partial unique index on active `(category, level)`, `reason TEXT NOT NULL`, cleared pair CHECK, append-only except clearing. *Stop everything* = one `stop_everything` row per category written in one transaction (cleared together); "stopped" = any active `stop_everything` row. Effects:
  - stop new / pause: `CreateListingAction`, `SubmitListingAction` → `409 category_stopped` with the message;
  - pause: `MarketQuery` and every market read (list, piece, saved pieces, quote) exclude the category's listings in state `live`; `reserved` and later stay visible and may still be requested (no document blocks joining a line); every `waiting` extension request on an order of the category is refused through `AnswerExtensionRequestAction::refuse` with the pausing staff member as actor and the note "category paused"; new extension requests refused `409 category_paused` (D4); `CategoryNotification` (SMS + email + inbox) to sellers with listings in the category, after commit;
  - stop everything: listings and buy requests refused `409 platform_stopped`; new withdrawals refused; staff release refused; customer cancel allowed; locked-price flows untouched; `FounderAlertNotification` (mail) to every staff with `is_founder` on set and on clear; the Dashboard shows a banner on every page from `GET /reference/controls` (D5).
  Visibility: four boolean settings in `SettingGroup::VISIBILITY` (`visibility.rapaport_reference`, `visibility.payout_averages`, `visibility.listing_stats`, `visibility.sharing`; defaults from the design's "Now" column), changed only through `PATCH /dashboard/visibility/{key}` (`visibility.manage`), audited with the old value; the generic settings PATCH refuses the group. Public `GET /reference/controls`.
- **Rationale**: FR-070–FR-073; schema §14; blueprint §7–8; D4, D5.
- **Alternatives**: nullable `category` for stop everything (contradicts the schema — rejected in review); hiding by moving listings to `suspended_hold` (mixes causes).

## R11 — Market makers (Q5; purchases SIGN-OFF D3)

- **Decision — 6A (buildable now)**: migrate `promo_code`, `promo_code_use`, `market_maker_approval` **as schema §15 has them** (CHECK `kind IN ('first_sale','market_maker')` unchanged); `CreatePromoCodeAction` refuses any kind but `market_maker` (`422 promo_kind_not_available`) and requires the tied customer to be of type `market_maker`. Staff: `/dashboard/promo-codes*` (create, change cap, turn off/on, list), `/dashboard/promo-codes/{code}/uses` (empty until 6B), `/dashboard/market-maker/queue` (live, `listed_at ≤ now − min_list_age_days`, not approved; numbers from `ListingPricer`; views *not measured*), `POST /dashboard/market-maker/approvals`, `/dashboard/market-maker/cost?month=` (zero until 6B). The code is bound to the account, valid on any of its devices (blueprint §4); the device is recorded on use rows.
- **Design — 6B ([SIGN-OFF Finance], not built before)**: `buy_request.promo_code`, `promo_code_use.buy_request_id/amount_egp/device_fingerprint` (added by a later migration in 6B, not in 6A); optional `promo_code` on `POST /customer/me/buy-requests` validated under `SELECT … FOR UPDATE` on the `promo_code` row (cap = sum of `amount_egp` of allowed uses whose buy request is open or settled this Cairo month + this one ≤ `monthly_cap_egp`); pricing unchanged (normal locked price, deposit and tolerance); `OrderSettlement` uses `commissionWaived = true` and, when the spread is positive, posts it to the dealer's `cust_available` instead of `dahab_spread` (negative spread stays on `dahab_spread`); invoices: buyer `-B` only (DH012 keys on commission 0); `GET /customer/me/market/listings` with `mm_approved` and the filter. Finance must confirm: the spread credit to the dealer (an allocation that is not a price), its ledger event kind (`balance_payment` line or a separate entry), whether the buyer invoice shows it, and its VAT treatment.
- **Rationale**: Part 3 §8 ("the dahab_spread leg is instead credited to the dealer"), blueprint §4, D3; keeping the schema CHECK avoids a widening migration later (review).
- **Alternatives**: pricing the dealer at the buy-side rate at request time (spread differs when rates move between request and acceptance — rejected D3).

## R12 — People to watch (D6)

- **Decision**: `GET /dashboard/people-to-watch?from&to` (+ CSV) computed on read in an elevated read scope; a customer is listed when sold + bought ≥ `flag.pattern_txn_threshold` in the period. Figures per person, no thresholds: **weight under** — n of m sold pieces whose first inspection weight is below the stated weight, average difference %; **not paid** — n of m accepted requests that ended `cancelled_buyer_nopay`; **same counterparty** — the counterparty with the most completed orders and the count; **sent back** — n of m listings that went to `changes_requested`. Rows sort by the highest ratio among the four (counterparty count ÷ transactions). `people_to_watch.view`; each read audited.
- **Rationale**: D6, design examples, Part 3 §9.3 (a person decides).
- **Alternatives**: thresholds (invented — rejected D6); nightly flag table (needs OI-1.3).

## R13 — Corrections found while planning and reviewing

- **A4**: notifications render in the queued job, so a message queued before a publish and sent after it uses the new text (spec A4 updated).
- **Pause and requests for more time**: extension requests hold no deposit (spec 014); D4 answers waiting ones *refused — category paused*; the design's "deposits returned" has nothing to return.
- **Views on a listing** have no data source; the MM queue shows *not measured*, and the *Views and requests* switch hides only what the app shows today.
- **DH012** keys on `commission_amount = 0`, so MM sales need no invoice-check change.
- Review fixes recorded in R2 (revert), R3 (version tag), R8 (gate), R9 (per-phase seeds), R10 (schema), R11 (schema, 6A/6B), R12 (D6), R16 (rollback).

## R14 — Staging of app text after Stage 1 (owner asked for a proposal)

| Stage | Screens (Flutter files) | Why this order | Approx. strings |
|---|---|---|---|
| 1 | Spec 018: free-relist card + notes (`order_screen.dart`), `FreeRelistScreen`, `RateScreen` (`order_help_screens.dart`) | owner's first slice | ~45 |
| 2 | Home, Browse, piece page (`home_screen.dart`, `browse_screen.dart`, `detail_screen.dart`, `shared/piece_card.dart`, `listing_ui.dart`) | the design's own App text examples are Home; most-read marketing copy | ~160 |
| 3 | Orders and buying (`orders_screen.dart`, the rest of `order_screen.dart`, `order_flows.dart`, `order_help_screens.dart`, `buy_screens.dart`, `shared/order_card.dart`) | rule-heavy copy with deadlines and amounts (placeholders) | ~330 |
| 4 | Selling (`sell1–3_screen.dart`, My listings) | commission/minimum numbers move to setting placeholders | ~200 |
| 5 | Wallet and money (`wallet_screens.dart`, `withdraw_confirm_screen.dart`, `bank_screens.dart`, invoices) | money wording, many patterns | ~330 |
| 6 | Account, settings, help, legal list, inbox shell, contact changes, close account (`account/*`) | lower churn | ~300 |
| 7 | Sign-in, sign-up, splash, shared states, toasts and error messages (`auth/*`, `widgets/*`, `account_messages.dart`) | first screens customers see — moved last, after the mechanism has run in production | ~400 |

Each stage: catalogue file + manifest + Backend sync + a screen test with the network off and with an override. A stage never changes a customer-visible word. Regex patterns of `i18n.dart` become keys with placeholders as their screen moves; the dictionary stays as the Arabic default source until Stage 7 ends.

## R15 — Database and environment

- Tests: `DB_DATABASE=dahab_wt019 DB_USERNAME=dahab DB_PASSWORD=…`; dev seed `dahab_wt019_dev`. **Neither exists in this environment and PostgreSQL is not running here** — the owner creates them before implementation. Full suite twice at most (baseline, end); targeted files between.
- Migrations after `2026_10_11_000010_after_collection.php`, one per phase: `2026_10_12_000010_app_content.php` (Phase 1: text tables, guard, `content.edit`), `_000020_legal_publishing.php` (Phase 2: guard, `legal.publish`), `_000030_category_controls.php` (Phase 6: table, visibility settings, four codes), `_000040_market_makers.php` (Phase 7/6A: three tables as schema §15, two codes), `_000050_people_to_watch.php` (Phase 8: one code); the 6B migration is written only after Finance's sign-off.

## R16 — Rollback protection

- **Decision**: every `down()` first checks for data it would destroy and throws with a clear message when any exists — published text versions; legal versions above the migration-seeded v1 rows; any `category_control` row; any promo code, use or approval — following spec 018's migration (`… OR EXISTS (SELECT 1 FROM listing WHERE relisted_from_order_id IS NOT NULL)`). Permission rows are detached from roles before deletion. Rolling back the legal migration drops its trigger first, so earlier migrations' `down()` deletes of their seed rows still work. Tests: rollback on an empty database succeeds; rollback with data is refused.
- **Rationale**: FR-104; Constitution "Migrations are reversible" with a reviewed note when reversal would destroy data.

## R17 — Release order and compatibility

- Each phase ships Backend → contract → Dashboard → Customer App and is usable alone: the app treats a missing bundle (404) as "no overrides"; new refusal codes appear only with the phase whose app update handles them; permissions appear only with their phase; no field is renamed or removed.
- Legal: R8 rollout constraint. Market makers: 6B waits for Finance. Deploy: `php artisan app-texts:sync` after migrations in every phase that adds keys.

