# Implementation Plan: Customer account (spec 017)

**Branch**: `feature/customer-account` in all three repositories (backend worktree `spec-017-customer-account-662fe8` from `main` 3df0a58; dashboard from `main` 82535f7; Customer App from `main` 4de46fe) | **Date**: 2026-10-06 | **Spec**: [spec.md](./spec.md)

## Summary

- **Contact changes**: phone by SMS code to the new number (cache challenge), email by single-use link (`one_time_token` `email_change`); on confirm the spec 013 safety stop (cancel unreleased withdrawals + `withdrawal_pause` with a trigger kind) under the payout-account locks; old contact told; sessions/devices per Q3 (R2–R4).
- **Password** change with other sessions signed out; **sessions** list and sign-out-and-forget of a device (tokens learn their device) (R4).
- **Inbox**: an `inbox` notification channel opted into by every customer notification except codes and confirmation links; stored with EN/AR text, type, params and link; list, unread count, read (R5). Settings screen static (nothing switchable).
- **Saved pieces** (R6), **legal list and support contacts** (R7), **close account** with blockers, DB guard DH013 and new listing transitions (R8), **listing reports** with the staff queue and take-down (R9).
- **Dashboard**: listing reports in *Disputes and reports*; Customer file notifications panel; `closed` status. **Customer App**: every account screen live in EN/AR; FAQ stays mock.

## Impact analysis

```
Backend:          YES — 1 migration; Actions Account/{RequestPhoneChange,ConfirmPhoneChange,RequestEmailChange,ReadEmailChangeLink,ConfirmEmailChange,ChangePassword,ListOwnSessions,SignOutSession,CloseAccountCheck,CloseAccount}, Notifications/{ListInbox,MarkRead,MarkAllRead,CountUnread}, Saved/{List,Save,Unsave}, Reports/{CreateReport,ListReports,ShowReport,DismissReport,TakeDownReported,CloseGoneReports}, Customers/ListCustomerNotifications; Support/Withdrawals/WithdrawalSafetyStop (extracted); InboxChannel + InboxMessage + contract; Account/ListingReport notifications; config/dahab-support.php
Database:         YES — customer (closed_*), personal_access_tokens / customer_trusted_device (device), one_time_token purpose, withdrawal_pause.trigger_kind, 4 listing transitions, customer_notification, saved_listing, listing_report (+seq), DH013/DH014/DH015
API:              YES — 20 customer/public endpoints, 5 dashboard endpoints, status + closed, sign-in account_closed, setting saved.max_per_customer
Dashboard:        YES — Disputes and reports: Reports table/detail, Dismiss and Take down DModals (useIdempotencyKey); Customer file: Notifications panel, closed status, audit labels for new events; types/services/endpoints/permissions/errors
Customer App:     YES — Your details (change phone / email screens + #/email-confirm page), Security (password, devices), Inbox + bell count, Notification settings (static), Saved + Save button, Report, Legal/Support/terms link, Close account; closed status; EN/AR; MOCK flags removed (FAQ stays)
Auth:             YES — sign-in refuses closed accounts; tokens carry device; password change
Permissions:      YES — listing_report.handle (roles holding listing.takedown)
API models/types: YES — Dashboard src/types/{listingReport,customer,notification}.ts; Flutter lib/models/{account,notification,saved,report}.dart
```

**Classification**: non-breaking (new endpoints, optional fields) except **potentially breaking**: `closed` in customer `status`, `403 account_closed` at sign-in, the new permission string, the pause `trigger_kind` field — every consumer updated in this change.

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary dependencies**: `CustomerLoginChallengeStore` pattern, `IssueTokenFamilyAction`/`RevokeTokenFamilyAction`, `WorksWithdrawals` (spec 013), `DecideListingAction` take-down (spec 010), `DatabaseActor` scopes, market listing read (spec 010) + calculator (spec 005), `RecordAuditLogAction`, `idempotent` middleware, `SmsChannel`/mail; Dashboard `DModal`, `useIdempotencyKey`, `usePermissions`, disputes page patterns; Flutter `ApiClient`, provider, i18n.
- **Storage**: PostgreSQL 16 (forced RLS on the three new tables), Redis (challenges, limiters, idempotency).
- **Testing**: Pest through HTTP on `dahab_wt017` as `dahab`, sequential; schema tests for guards/RLS; concurrency (R14).
- **Constraints**: Backend LF, Dashboard/Flutter CRLF; Pint and `dart format --line-length 180` on changed files only; file tools or saved Python scripts, never heredocs; Postman folders inserted as text.
- **Scale/scope**: 25 endpoints, 2 Dashboard areas, ~10 Customer App screens.

No open NEEDS CLARIFICATION: 28 answers in the spec; engineering choices R1–R14 in [research.md](./research.md).

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Every change has the customer or staff actor; sweep runs as system; audit events R12. | ✅ |
| II. Isolation by the engine; staff authz by data | Forced RLS on `customer_notification`, `saved_listing`, `listing_report`; inbox written elevated by the channel; permission by catalogue code. | ✅ |
| III. Docs first | Same change: schema docs, Technical Spec Part 1 §2 (contact changes, devices, closed), Part 2 (new endpoints, "Changed by spec 017"), api-contract, feature doc, CLAUDE.md current state. | ✅ |
| IV. Foundation before modules | Migration mirrors the schema update; each endpoint: Request, Resource, Action, `#[OA]`, Pest, Postman. | ✅ |
| V. Test the boundary and the ledger | Withdrawal cancellations go through `WithdrawalLedger` (balances and ledger zero asserted); close asserts zero balances; concurrency suites. | ✅ |
| Reversible migrations | `down()` refuses once data of this spec exists. | ✅ |

**Recorded deviations** (land in `docs/` in the same change): new tables `customer_notification`, `saved_listing`, `listing_report`; new columns on `customer`, `withdrawal_pause`, tokens, devices; four listing transitions; SQLSTATEs DH013–DH015; legal codes `selling_rules`, `id_handling`, the blocker `piece_at_branch` and the setting `saved.max_per_customer` (all approved by the user 2026-10-06); support contacts as demo config.

## Project Structure

```text
backend (LF)
  docs/{Database schema/{00,02,04}_*.sql, Technical Spec/part{1,2}.md, platform/api-contract.md, features/{customer-account.md,README.md}} · CLAUDE.md
  database/migrations/2026_10_10_000010_customer_account.php · seeders/DashboardRolesAndPermissionsSeeder (+1) · factories
  config/dahab-support.php
  app/Enums/{StaffPermission,AuditEvent,CustomerStatus,LegalDocumentCode(new),CloseReason(new),ReportReason(new),ReportState(new),InboxLinkKind(new),AccountEvent(new),PauseTrigger(new)}
  app/Models/{CustomerNotification,SavedListing,ListingReport} · Customer (closed_*, status) · WithdrawalPause
  app/Services/ContactChangeChallengeStore.php
  app/Support/Withdrawals/WithdrawalSafetyStop.php (+ WorksWithdrawals::makeInUse uses it) · app/Support/Account/CloseBlockers.php
  app/Notifications/{Channels/InboxChannel, Messages/InboxMessage, Contracts/InboxNotification, Concerns/RendersInbox, AccountNotification, ListingReportNotification} + toInbox on the 10 existing customer notifications
  app/Actions/Account/* · Notifications/* · Saved/* · ListingReports/* · Customers/ListCustomerNotificationsAction · Auth (AssertCustomerCanSignIn, IssueTokenFamilyAction, LoginCustomerAction: device fields + new-device alert)
  app/Console/Commands/CloseGoneListingReports.php · routes/console.php
  app/Exceptions/DomainApiException (+codes) · bootstrap/app.php (DH013–15, limiters in AppServiceProvider)
  app/Http/{Requests,Resources,Controllers}/… · routes/api.php · postman (folders "Account", "Inbox", "Saved pieces", "Listing reports"; reference)
  tests/Feature/Account/{PhoneChange,EmailChange,Password,Sessions,Inbox,InboxCoverage,SavedPieces,Legal,CloseAccount,ListingReports,StaffListingReports,AccountSchema,AccountIsolation,AccountConcurrency,AccountPermissions}Test.php
  inventory tests: PrincipalIsolationTest, OpenApiGenerationTest, AuditCatalogueTest, StaffDashboardAccessTest, StaffAuthorizationMigrationTest, LedgerSchemaTest (rollback order); truncating suites truncate the 3 new tables
dashboard (CRLF)
  src/api/endpoints.ts · src/types/{listingReport.ts, customer.ts (+closed), staff.ts (permission)} · src/services/{listingReport.service.ts, customer.service.ts, errors.ts}
  src/composables/useListingReports.ts · src/pages/disputes/index.vue (Reports section) · src/components/listingReports/{ReportsTable,ReportDetailPanel,DismissReportModal,TakeDownReportModal,reportErrors}
  Customer file: NotificationsPanel.vue, status chip, audit event labels
flutter (public repo)
  lib/models/{account.dart, inbox.dart, saved.dart, listing_report.dart} · lib/services/api/{account_api.dart, inbox_api.dart, saved_api.dart, reports_api.dart, reference_api}
  lib/features/account/{account_screen.dart (Your details live, close link), contact_change_screens.dart (new), settings_screens.dart (Security, Inbox, Notif static, Legal, Support, Delete live; Help FAQ mock)} · catalog/{buy_screens.dart (Saved, Report), detail_screen.dart (Save, Report link)} · widgets/app_shell.dart (bell count) · auth/signup_screens.dart (terms link) · routing (+ #/email-confirm)
  lib/widgets/mock_flag.dart · lib/core/i18n + assets/i18n/ar.json · test/{test_app,flows_test,mock_flags_test}.dart
```

## Phase 0 / Phase 1 outputs

[research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/account-api.md](./contracts/account-api.md) · [quickstart.md](./quickstart.md). Post-design re-check: no Constitution violation; deviations above land in `docs/`.
