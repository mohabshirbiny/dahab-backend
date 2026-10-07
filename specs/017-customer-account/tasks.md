# Tasks: Customer account (spec 017)

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/account-api.md](./contracts/account-api.md), [quickstart.md](./quickstart.md)

**Tests**: required (Constitution V; the brief: security events, concurrency where requests race). Test-first within each story: write the test, see it fail for the expected reason, implement, run only that file (`grep -E "✓|⨯|Tests:"`).

**Rules** (as spec 016, not restated in each task): run artisan/tests as `dahab` with `DB_DATABASE=dahab_wt017` (tests) / `dahab_wt017_dev` (seeding) passed explicitly, never the main `dahab` DB, suite sequential; new test helpers prefixed `acc017…`; tests that commit data put back every kept table they change; backend LF, Dashboard/Flutter CRLF; Pint / `dart format --line-length 180` on changed files only; edits with the file tools or a saved Python script (no heredocs); Postman folders inserted as text; no commit/push unless told; check `git status` and mtimes in all three projects before each phase.

**Every endpoint task** includes its `#[OA\…]`, its Postman request (body = FormRequest, `Idempotency-Key` pre-request script on POSTs, saved ids `phone_challenge_id`, `email_change_token`, `session_id`, `notification_id`, `listing_report_id`), the route middleware (`idempotent`, limiter, permission) — not done without them.

**Paths**: backend = this worktree (switch to branch `feature/customer-account`); Dashboard = `D:\laragon\www\dahab-dashboard\.claude\worktrees\customer-account`; Flutter = `D:\laragon\www\dahab-flutter\.claude\worktrees\customer-account` (public repo — demo support values only).

User stories: **US1** phone change (P1) · **US2** email change (P1) · **US3** password + devices (P1) · **US4** inbox (P1) · **US5** saved pieces (P2) · **US6** help & legal (P2) · **US7** close account (P2) · **US8** listing reports (P2) · **US9** Customer file (P3).

---

## Phase 1: Setup (docs first — Constitution III)

- [X] T001 Rename this worktree's branch to `feature/customer-account`; create the Dashboard and Flutter worktrees on `feature/customer-account` from `main` 82535f7 / 4de46fe (leave the main checkouts' uncommitted `.env.development` / `app_config.dart` untouched); `composer install --ignore-platform-req=ext-pcntl`; copy `.env` from the spec 016 worktree with `DB_DATABASE=dahab_wt017_dev`, `MAIL_MAILER=log`; run the current suite on `dahab_wt017` in the background to a log and confirm green.
- [X] T002 Write `docs/features/customer-account.md` from `_TEMPLATE.md` (impact + classification from plan.md, the 28 clarifications in short, endpoint table, permission, deviations, follow-ups: switchable settings and the two deferred events, FAQ (spec 019), legal publishing, retention/anonymisation, reopening); index it in `docs/features/README.md`.
- [X] T003 Update the schema docs per data-model.md, each change marked "spec 017": `02_schema_identity.sql` (customer `closed_*` + CHECKs, token/device columns, `one_time_token` purpose, `customer_notification`, `saved_listing`, DH013/DH014 functions, RLS), `04_schema_market.sql` (`withdrawal_pause.trigger_kind` + CHECK, four listing transitions, `listing_report` + `listing_report_no_seq` + DH015 guard + RLS), `01_schema_core.sql` setting seed `saved.max_per_customer` = 200; mirror in `00_schema_full.sql`.
- [X] T004 Amend the Technical Spec, "Changed by spec 017" with a link: Part 1 §2 (phone change by code to the new number, email change by link, password change, sessions/devices, new-device alert, closed accounts and `account_closed`), §5.1 (RLS on the three tables); Part 2 (the endpoints of contracts/account-api.md, errors R11, status `closed`, the sweep `listing-reports:close-gone`), spec 013 note (pause trigger kinds).
- [X] T005 [P] Update `docs/platform/api-contract.md`: new surfaces, idempotency list, error codes (R11), status `closed`, the inbox item shape and link kinds (generic for later specs), rate limits.

---

## Phase 2: Foundational (blocks every story)

- [X] T006 Write `tests/Feature/Account/AccountSchemaTest.php` (fails first): the migration's columns, CHECKs and indexes of data-model.md (closed reason six codes, "`closed_note` ≤ 500 only with `other`", `trigger_kind` IN (`payout_account`,`phone_change`,`email_change`) with the account rule, inbox `link_kind` list and "`link_id` NULL iff kind in `wallet, account, none`", titles ≤ 200, bodies ≤ 1000, `UNIQUE (customer_id, dedupe_key)`, report reasons/states, note ≤ 1000, partial unique open report); DH013 refuses inserts for a closed customer on every R8 table incl. `ledger_posting`; DH014 inbox only `read_at` NULL→value, no delete; DH015 report only `open →` final, no delete; forced RLS on the three tables; the four new listing transitions exist; `down()` refuses once data exists.
- [X] T007 Migration `database/migrations/2026_10_10_000010_customer_account.php` mirroring T003 (no `?` operator, no apostrophes in SQL comments, CASE parenthesised in PL/pgSQL IF); policies for customer / elevated scopes as specs 013–016; `listing_transition` rows; the `saved.max_per_customer` setting row (200) + history row; `down()` guarded.
- [X] T008 [P] Enums `app/Enums/{CloseReason,ReportReason,ReportState,InboxLinkKind,PauseTrigger,LegalDocumentCode,AccountEvent}.php`; `CustomerStatus::CLOSED` derived first in `Customer::status`; `AuditEvent` + the ten events of R12; `SettingKey::SAVED_MAX_PER_CUSTOMER` (`saved.max_per_customer`, group operations, integer, min 1, max 1000, seed 200; the settings seeder/catalogue and `SettingsTest` expectations updated); `StaffPermission::LISTING_REPORT_HANDLE` (`listing_report.handle`) and its catalogue entry; `DashboardRolesAndPermissionsSeeder` grants it to every role holding `listing.takedown`.
- [X] T009 [P] Models + factories `CustomerNotification`, `SavedListing`, `ListingReport` (reference `RPT-n`); `Customer` casts/fillable for `closed_*`; `WithdrawalPause` `trigger_kind`.
- [X] T010 `DomainApiException` factories for every R11 code; `bootstrap/app.php` maps DH013 → `409 account_closed`, DH014 → 409 `notification_immutable`, DH015 → `409 report_not_open`; named limiters of R10 in `AppServiceProvider`.
- [X] T011 Extract `app/Support/Withdrawals/WithdrawalSafetyStop.php` (cancel unreleased withdrawals through `WithdrawalLedger::returnToAvailable` + open the pause with a trigger kind) from `WorksWithdrawals::makeInUse`; `makeInUse` calls it with `payout_account`; run the spec 013 withdrawal tests unchanged — green.
- [X] T012a Token/device fields (moved from US3 — US1 needs them): `IssueTokenFamilyAction` stores `device_fingerprint_hash`/`device_platform` (rotation copies them); `LoginCustomerAction` / `VerifyCustomerLoginOtpAction` store `platform` and `user_agent` (≤ 255) on the trusted device and send `AccountNotification(new_device)` after a successful new-device OTP. Tested in `SessionsTest` (T023).
- [X] T012 Inbox core: `app/Notifications/Contracts/InboxNotification.php`, `Messages/InboxMessage.php` (type, params, link kind + id, title/body EN+AR), `Concerns/RendersInbox.php` (builds EN/AR from `subject(bool)`/`body(bool)`), `Channels/InboxChannel.php` (registered as `inbox`; writes under `DatabaseActor::elevate('system')`, `dedupe_key` = notification id, `ON CONFLICT DO NOTHING`); `tests/Feature/Account/InboxChannelTest.php` (written first): a retried send writes one row, a staff-scope send lands on the right customer only.

---

## Phase 3: US1 — Change phone number (P1)

- [X] T013 [US1] `tests/Feature/Account/PhoneChangeTest.php` (first): request (format, `same_contact`, `contact_taken` with no SMS, limiter 3/h, a second request voids the first), confirm (wrong code `tries_left`, 5 tries → `change_code_locked`, expired, reused code), success effects (phone, old number + email told, inbox item, pause `phone_change` with `account_change_pause_hours`, unreleased withdrawals cancelled with money back, other sessions revoked, other trusted devices gone, current session kept, audit masked), allowed for pending/rejected/suspended, refused for closed; the confirm limiter (5 per 15 minutes) answers 429.
- [X] T014 [US1] `app/Services/ContactChangeChallengeStore.php` (encrypted cache, one live challenge per customer, lock) — R2.
- [X] T015 [US1] `AccountNotification` (`app/Notifications/AccountNotification.php`, events of `AccountEvent`: `phone_changed`, `email_changed`, `password_changed`, `new_device`, `account_closed`; SMS + mail + inbox, EN/AR in `lang/`) and `PhoneChangeCodeNotification` (SMS only, to the new number, on demand).
- [X] T016 [US1] Actions `app/Actions/Account/{RequestPhoneChangeAction,ConfirmPhoneChangeAction}.php`: confirm locks payout accounts → customer row, rechecks uniqueness, updates phone, `WithdrawalSafetyStop` (`phone_change`), revokes other families and deletes other trusted devices (device columns from T012a); captures the old phone before the update, audits, after commit tells the old number (on-demand route) and email.
- [X] T017 [US1] `app/Http/Requests/Customer/Account/{RequestPhoneChangeRequest,ConfirmPhoneChangeRequest}.php`, `Customer/AccountController@requestPhoneChange|confirmPhoneChange`, routes `POST /customer/me/phone-change`, `/{challenge}/confirm` (idempotent, limiters).
- [X] T018 [US1] Concurrency in `tests/Feature/Account/AccountConcurrencyTest.php`: two change requests at once → one live challenge; two confirmations of one challenge → one change; a confirmation racing a withdrawal submit → never a withdrawal after the change outside the pause; ledger sums to zero.

## Phase 4: US2 — Change email (P1)

- [X] T019 [US2] `tests/Feature/Account/EmailChangeTest.php` (first): request (`contact_taken`, `same_contact`, new request consumes the older link), public read / confirm (expired, used, unknown → `change_link_invalid` 410), effects when an email existed (open withdrawal confirmations `replaced_at`, unreleased withdrawals cancelled, pause `email_change`, old address told, inbox) and when adding a first one (none of these), audit.
- [X] T020 [US2] `one_time_token` purpose `email_change` use; `EmailChangeLinkNotification` (mail only, to the new address, link to the app's `#/email-confirm?token=`); Actions `RequestEmailChangeAction`, `ReadEmailChangeLinkAction`, `ConfirmEmailChangeAction` (atomic consume, locks as T016).
- [X] T021 [US2] Requests, `AccountController@requestEmailChange`, public `ContactChangeController@read|confirm`; routes `POST /customer/me/email-change`, `GET /contact-changes/email/read`, `POST /contact-changes/email/confirm` (idempotent, public limiter).
- [X] T022 [US2] Concurrency: one link confirmed twice at once → one change (in `AccountConcurrencyTest.php`).

## Phase 5: US3 — Password and devices (P1)

- [X] T023 [US3] `tests/Feature/Account/{PasswordChangeTest,SessionsTest}.php` (first): wrong current (`current_password_wrong`, counts on the sign-in limiter), rules of spec 001, same as current refused, other sessions revoked/devices kept, notice; sessions list (current marked, platform/user agent, legacy session with `null` platform), sign-out other (tokens refused at once, device forgotten → next sign-in from it needs OTP), `current_session`, someone else's session 404, the sign-out limiter (10/minute) and the password limiter answer 429, new-device sign-in alert by SMS + mail + inbox.
- [X] T025 [US3] Actions `ChangePasswordAction`, `ListOwnSessionsAction`, `SignOutSessionAction` (R4; device fields from T012a); Requests, Resource `CustomerSessionResource`, controller methods, routes `POST /customer/me/password`, `GET /customer/me/sessions`, `POST /customer/me/sessions/{session}/sign-out`.

## Phase 6: US4 — Inbox (P1)

- [X] T026 [US4] `tests/Feature/Account/{InboxTest,InboxCoverageTest}.php` (first): list newest first with keyset cursor, `unread` filter, unread count, read one / read all (idempotent), another customer's item 404; coverage — every customer notification class except the three OTP ones, `WithdrawalConfirmationNotification`, `ProxyNamedNotification`, `PhoneChangeCodeNotification`, `EmailChangeLinkNotification` implements `InboxNotification` and lists `inbox` in `via()` (reflection over `app/Notifications`), and each excluded one does not; a representative event per class writes one item with the right link kind/id.
- [X] T027 [US4] Implement `toInbox` (+ `inbox` in `via()`) on `BuyRequestNotification`, `ListingDecisionNotification`, `OrderNotification` (ref → id, elevated read), `PayoutNotification`, `WalletNotification`, `TopUpCreditedNotification`, `TopUpRejectedNotification`, `CustomerRegistrationSubmittedNotification`, `CustomerVerifiedNotification`, `CustomerVerificationRejectedNotification`, `CustomerVerificationResubmissionNotification` — texts unchanged, SMS/mail unchanged.
- [X] T028 [US4] Actions `ListInboxAction`, `CountUnreadAction`, `MarkNotificationReadAction`, `MarkAllNotificationsReadAction`; Resource `InboxItemResource` (title/body in the request language + both); `NotificationController`; routes of contracts (4).

## Phase 7: US5 — Saved pieces (P2)

- [X] T029 [US5] `tests/Feature/Account/SavedPiecesTest.php` (first): save live/reserved (idempotent), refuse other states `listing_not_saveable`, cap from the setting `saved.max_per_customer` (`saved_limit_reached`, `details.limit`; lowering the setting below a customer's count keeps their rows and only refuses new saves), list with market shape + price, `?listing_id=`, a piece taken down → `available: false`, `summary` only, no media/price, unsave idempotent, closed customer refused (DH013), isolation.
- [X] T030 [US5] Actions `ListSavedPiecesAction` (market scope read for available pieces), `SavePieceAction`, `UnsavePieceAction`; Request, Resource, `SavedPieceController`, routes (3).

## Phase 8: US6 — Help and legal (P2)

- [X] T031 [US6] `tests/Feature/Account/LegalAndSupportTest.php` (first): `/reference/legal-documents` lists `terms, privacy, selling_rules, id_handling` with `published` and version; `/reference/support-contacts` returns the config.
- [X] T032 [US6] `config/dahab-support.php` (prototype values, header "demo values — replace before production"); `ListLegalDocumentsAction`, `ReferenceController@legalDocuments|supportContacts`; routes.

## Phase 9: US7 — Close account (P2)

- [X] T033 [US7] `tests/Feature/Account/CloseAccountTest.php` (first): `close-check` and `close` refuse with each blocker code of R8 (one case each, incl. `piece_at_branch` as seller and as buyer; a payout account in use and a running pause do not block), reason codes, note only with `other` ≤ 500; success: closed fields, live/draft/in_review/changes_requested/suspended_hold listings withdrawn with history, pending challenges and email links voided, every token and device gone, SMS + mail, audit; sign-in / OTP → `403 account_closed`; phone cannot register again; works for a suspended customer.
- [X] T034 [US7] `app/Support/Account/CloseBlockers.php`; Actions `CloseAccountCheckAction`, `CloseAccountAction` (lock order R8); `AssertCustomerCanSignIn` refuses closed; Request, controller, routes `GET /customer/me/account/close-check`, `POST /customer/me/account/close`.
- [X] T035 [US7] Concurrency: close vs buy request as buyer, close vs a buy request on their live piece, close vs a top-up credit by staff — never both succeed (in `AccountConcurrencyTest.php`).

## Phase 10: US8 — Listing reports (P2)

- [X] T036 [US8] `tests/Feature/Account/{ListingReportsTest,StaffListingReportsTest}.php` (first): customer report (verified + not suspended, own piece / not live-reserved → `listing_not_reportable`, duplicate open → `report_already_open`, 10/day, note ≤ 1000), seller never sees the reporter (no endpoint/resource exposes it); staff list with counts/filters, detail, dismiss (note required, audit, reporter inbox note), take-down (needs both permissions, spec 010 take-down runs with the seller notice, every open report on the piece → `actioned`), `report_not_open`, permission refusals; sweep `listing-reports:close-gone` resolves reports of pieces no longer live/reserved and tells reporters; two reports at once by one reporter → one.
- [X] T037 [US8] `ListingReportNotification` (inbox only, generic text EN/AR); Actions `CreateListingReportAction`, `ListListingReportsAction`, `ShowListingReportAction`, `DismissListingReportAction`, `TakeDownReportedListingAction` (calls the spec 010 take-down in its transaction); command `CloseGoneListingReports` + schedule every five minutes.
- [X] T038 [US8] Requests, Resources (`Staff/ListingReportResource`), controllers `Customer/ListingReportController`, `Dashboard/ListingReportController`; routes (customer 1, dashboard 4) with `staff.permission:listing_report.handle` (+ `listing.takedown` on take-down).

## Phase 11: US9 — Customer file (P3)

- [X] T039 [US9] `tests/Feature/Account/CustomerFileAccountTest.php` (first): `GET /dashboard/customers/{id}/notifications` (`customer.view`, paged, read-only); History shows the new audit events; the file shows `status: closed` and the pause `trigger_kind`.
- [X] T040 [US9] `ListCustomerNotificationsAction`, `DashboardCustomerController@notifications`, route; `CustomerFileResource` + `trigger_kind`, closed fields.

## Phase 12: Backend inventory and contract

- [X] T041 Isolation + permissions: `tests/Feature/Account/{AccountIsolationTest,AccountPermissionsTest}.php` (every new customer route refuses other customers' rows; staff routes refuse without permission).
- [X] T042 Inventory tests: `PrincipalIsolationTest` (route count), `OpenApiGenerationTest` (paths/schemas), `AuditCatalogueTest`, `StaffDashboardAccessTest`, `StaffAuthorizationMigrationTest`, `LedgerSchemaTest` (rollback order); truncating suites (`OrderConcurrencyTest`, `OrdersPerformanceTest`, `WithdrawalConcurrencyTest`, `WithdrawalPerformanceTest`, `DisputeConcurrencyTest`, `DisputePerformanceTest`, `FinanceConcurrencyTest`, `InvoiceConcurrencyTest`) truncate the three new tables and keep `listing_transition`.
- [X] T043 `composer swagger:generate`; Postman folders "Account", "Inbox", "Saved pieces", "Listing reports" + reference requests inserted as text; `./vendor/bin/pint` on changed files; full suite once in the background to a log — green.
- [X] T044 Seed through the real Actions on `dahab_wt017_dev`: an inbox with items, a saved piece, an open report (local seeder only).

---

## Phase 13: Dashboard

- [X] T045 [P] [US8] `src/api/endpoints.ts`, `src/types/listingReport.ts`, `src/types/staff.ts` (+ `listing_report.handle`), `src/services/{listingReport.service.ts,errors.ts}` (R11 codes) — fields from the Resources, never guessed.
- [X] T046 [US8] `src/composables/useListingReports.ts`; `src/components/listingReports/{ReportsTable,ReportDetailPanel,DismissReportModal,TakeDownReportModal,reportErrors}` (DModal + `useIdempotencyKey`, gated by `usePermissions`); Reports section in `src/pages/disputes/index.vue` with counts; nav badge counts reports; remove the reports mock/placeholder.
- [X] T047a [US5] Settings page: label EN/AR for `saved.max_per_customer` if the page keeps a label map (else nothing); type-check.
- [X] T047 [US9] Customer file: `NotificationsPanel.vue` (customer.view), `closed` status chip and filter, pause trigger label, audit event labels for the R12 events; `src/types/customer.ts` (+ `closed`).
- [X] T048 `npm run type-check` while iterating; at the end `npm run lint` (only my files clean; 46 pre-existing errors) and `npm run build`.

## Phase 14: Customer App (EN/AR; check `assets/i18n/ar.json` first; number patterns in `lib/core/i18n/i18n.dart`)

- [X] T049 [P] Models `lib/models/{account.dart,inbox.dart,saved.dart,listing_report.dart}`; APIs `lib/services/api/{account_api.dart,inbox_api.dart,saved_api.dart,reports_api.dart}` + reference calls; repositories (replace the mock `AccountRepository.devices`, inbox, saved); `me` status `closed`.
- [X] T050 [US1][US2] *Your details*: change phone (new number → code screen with resend/errors/tries) and change email (send link → "check your inbox"); `#/email-confirm` page (read → confirm) like `#/withdraw-confirm`; remove the details MockMark.
- [X] T051 [US3] Security screen live: change password (errors), devices list with *This device* and *Sign out*; remove its MockMarks.
- [X] T052 [US4] Inbox screen live (paged, mark read on open, deep links to order / invoice / wallet / listing / withdrawal…, read all); header bell unread count (refresh on resume and after reads); Notification settings static: the always-on rows only, no switches; remove MockMarks / `mockScreens` entries.
- [X] T053 [US5] Saved screen live ("No longer available" rows, remove); piece page Save/Saved via `?listing_id=`; remove MockMarks.
- [X] T054 [US6] Legal screen live (list + document viewer, "not published yet"), Support screen from `/reference/support-contacts` (no chat row), sign-up *Read the full terms* live; Help keeps its FAQ MockMark.
- [X] T055 [US7] Close account live: close-check blockers ("See what's open" → the relevant tabs), six reasons, note for *Another reason*, the data copy adapted ("records are kept as the law requires"), close → signed out to splash; sign-in shows `account_closed`.
- [X] T056 [US8] Report screen live (six reasons, note, errors, success); piece page link live; remove MockMarks.
- [X] T057 Route prototype mock screens duplicating a live one to it; update `mockScreens`; tests in `test/{test_app,flows_test,mock_flags_test}.dart` + fake backend for every new endpoint; `flutter analyze`; targeted tests while iterating; full `flutter test` once (the two date-bound flows tests may fail on main — ignore); `flutter build web --release`.

---

## Phase 15: Polish

- [X] T058 `CLAUDE.md` current state (spec 017 backend, Dashboard, Flutter lines); memory not needed.
- [X] T059 `/speckit-analyze`-style re-check of spec ↔ code; Step 5 report with the DB note (`php artisan migrate` + `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder` on the main `dahab` DB) and what stays mock in each app.

## Dependencies

Phase 1 → Phase 2 (T006–T012, incl. T012a device fields) → US1–US9 → Phase 12 → Dashboard (needs backend Resources) → Customer App → Polish. US4 depends on T012; US1–US3, US7, US8 send `AccountNotification`/`ListingReportNotification` (T015, T037) into the inbox.

## Parallel opportunities

T005 ∥ T003–T004; T008 ∥ T009; within stories, tests of different files; US5 ∥ US6 ∥ US8 after Phase 2; Dashboard T045 ∥ Flutter T049 once the backend contract is generated.

## MVP

US1–US4 (contact changes, password/devices, inbox) — the security events and the third channel; then US7/US8, then US5/US6/US9.
