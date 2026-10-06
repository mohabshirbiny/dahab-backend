# Customer account

> File: `docs/features/customer-account.md` · Branch: `feature/customer-account` in each affected repo
> Status: in progress · Date: 2026-10-06 · Spec Kit: [`specs/017-customer-account/`](../../specs/017-customer-account/)

## Goal

Give the customer control of their account in the app: change phone, email and password; see and sign out
their devices; read every message Dahab sends in an in-app inbox; save pieces; read the terms and contact
details; close the account; report a listing. Give staff the listing-reports queue and, in the Customer file,
the notifications a customer was sent. Product decisions: the 28 clarifications in
[`spec.md`](../../specs/017-customer-account/spec.md#clarifications) plus three approvals (saved-pieces cap as a
setting, legal codes, the `piece_at_branch` blocker).

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES
Dashboard:    YES
Customer App: YES
Auth:         YES — sign-in refuses closed accounts; tokens record their device; password change
Permissions:  YES — listing_report.handle
```

## Backend Impact

Actions under `app/Actions/{Account,Notifications,Saved,ListingReports}`; `ContactChangeChallengeStore`
(phone code, encrypted cache); `WithdrawalSafetyStop` (extracted from spec 013, shared by the payout-account change
and the contact changes); the `inbox` notification channel (`InboxChannel`, `InboxNotification`), implemented by
every customer notification except codes and confirmation links; `AccountNotification`,
`ListingReportNotification`; the sweep `listing-reports:close-gone` (every five minutes);
`config/dahab-support.php`. Details: [plan](../../specs/017-customer-account/plan.md),
[research](../../specs/017-customer-account/research.md).

## Database Impact

One migration — [data model](../../specs/017-customer-account/data-model.md): `customer.closed_*`; device columns on
`personal_access_tokens` and `customer_trusted_device`; `one_time_token` purpose `email_change`;
`withdrawal_pause.trigger_kind`; four listing transitions to `withdrawn` ("account closed"); new tables
`customer_notification`, `saved_listing`, `listing_report` (forced RLS); setting `saved.max_per_customer` (200);
SQLSTATEs DH013 (closed customer), DH014 (inbox immutable), DH015 (report transitions). Nothing is deleted or
anonymised — retention waits for the legal clinic.

## API Changes

Endpoint list: [contracts/account-api.md](../../specs/017-customer-account/contracts/account-api.md) — 20
customer/public and 5 dashboard endpoints. Classification: **non-breaking** additions, except **potentially
breaking**: customer `status` gains `closed`, sign-in and new-device OTP answer `403 account_closed`, the Customer
file pause gains `trigger_kind`, a new permission string — all consumers updated in the same change.

## Dashboard Impact

*Disputes and reports*: the reports table, detail, Dismiss and Take down (DModal + `useIdempotencyKey`, gated on
`listing_report.handle` / `listing.takedown`). Customer file: Notifications panel (`customer.view`), `closed` status,
labels for the new audit events. Settings: `saved.max_per_customer`.

## Customer App Impact

Your details (change phone with a code, change email with a link, `#/email-confirm`), Security (password, devices),
Inbox and the bell count, Notification settings (always-on rows only), Saved pieces and the Save button, Report this
listing, Terms/Privacy list, Contact us, Close my account; `closed` status. EN/AR. The FAQ stays mock (spec 019).

## Authentication / Authorization

Customer surface (`auth:customer`, `customer:access`); every state may change contacts/password except closed;
reporting needs the verified gate and not suspended. Public: the email-change link and the reference reads.

## Permissions

`listing_report.handle` — seeded to every role holding `listing.takedown`; take-down also needs `listing.takedown`.
Existing DBs: `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder`.

## Validation

Phone in the registration format, email RFC, password per spec 001; reasons from fixed lists; notes ≤ 500 (close)
and ≤ 1000 (report); rate limits in research R10.

## Error Handling

New codes in research R11 (`contact_taken`, `change_code_invalid`, `change_link_invalid`, `current_password_wrong`,
`current_session`, `account_closed`, `account_has_open_items` with `details.blockers`, `saved_limit_reached`,
`listing_not_saveable`, `listing_not_reportable`, `report_already_open`, `report_not_open`, …).

## UI States

Loading / empty / error on every new screen; the bell shows the unread count; close-account lists its blockers.

## Testing

Pest under `tests/Feature/Account/` (HTTP, schema, RLS, permissions, concurrency on two connections); Dashboard
type-check/lint/build; Flutter analyze/tests with the fake backend.

## Breaking Changes

None breaking; potentially breaking items above.

## Migration / Compatibility

`php artisan migrate` then the roles seeder on existing DBs. Sessions issued before the migration show no device
platform. Follow-ups: switchable notification settings and the two deferred events (price moves, pieces you might
like), FAQ (spec 019 App text), publishing legal documents, data retention / anonymisation after the legal clinic,
reopening a closed account, push and WhatsApp.
