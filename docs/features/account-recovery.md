# Account recovery and staff access

> File: `docs/features/account-recovery.md` · Branch: `feature/account-recovery` in each affected repo
> Status: in progress · Date: 2026-10-11
> Spec: `specs/020-account-recovery-and-staff-access/` (spec, plan, research R1–R14, data-model, contract, tasks)

## Goal

A customer who forgot their password signs back in by an SMS code (no developer needed); a staff member
recovers a password through an emailed single-use link; a CEO creates, disables and enables staff accounts
from the Dashboard instead of a seeder.

## Impact Summary

```
Backend:      YES
Database:     YES   (one CHECK widened; one permission row)
API:          YES   (7 new endpoints, 2 optional additions)
Dashboard:    YES
Customer App: YES
Auth:         YES
Permissions:  YES   (staff.manage, CEO only)
```

## Backend Impact

Actions `RequestPasswordResetAction`, `ConfirmPasswordResetAction`, `IssueStaffPasswordLinkAction`,
`RequestStaffPasswordResetAction`, `ConfirmStaffPasswordResetAction`, `CreateStaffAction`,
`DisableStaffAction`, `EnableStaffAction`; `PasswordResetChallengeStore` (encrypted cache challenge);
notifications `PasswordResetCodeNotification`, `StaffPasswordLinkNotification`, `AccountEvent::PASSWORD_RESET`;
enums `PauseTrigger::PASSWORD_RESET`, `StaffPermission::STAFF_MANAGE`, four audit events; limiters.

## Database Impact

Migration `2026_10_13_000010_account_recovery.php`: `withdrawal_pause.trigger_kind` accepts `password_reset`;
permission `staff.manage` inserted and given to `ceo` only. No new table or column.

## API Changes

| Method + path | Surface | Notes |
|---|---|---|
| `POST /customer/auth/password/reset-request` | public | uniform `202` |
| `POST /customer/auth/password/reset-confirm` | public | `otp_invalid`, `otp_expired`, no token issued |
| `POST /dashboard/auth/password/reset-request` | public | uniform `202` |
| `POST /dashboard/auth/password/reset-confirm` | public | `reset_link_invalid` |
| `POST /dashboard/staff` | staff, `staff.manage` | `Idempotency-Key`, `reason` required |
| `POST /dashboard/staff/{staff}/disable` · `…/enable` | staff, `staff.manage` | `Idempotency-Key`, `reason` required |

Additions: `GET /dashboard/staff?status=active|disabled`, `password_set` on `DashboardStaffMember`,
`password_reset` in the customer file's `withdrawal_pause.trigger_kind`.
Classification: non-breaking, except the new enum value (potentially breaking for exhaustive maps — the
Dashboard is updated in the same phase). Exact shapes: `specs/020-…/contracts/account-recovery-api.md`.

## Dashboard Impact

Types (`WithdrawalPauseTrigger`, staff), services (+ mock), pages `forgot-password` and `set-password`,
*Forgot password?* on sign-in, Staff page: *Add staff member*, *Disable / Enable*, *Invitation pending*,
*Disabled* tab, `PERMISSIONS.staffManage`.

## Customer App Impact

`auth_api.dart` (two calls), `forgot_password_screen.dart`, `reset_password_screen.dart`, routes, text keys
`auth.reset.*` (English + Arabic, AR-DRAFT), fake backend and tests.

## Authentication / Authorization

Reset endpoints are public (`db.elevate:bootstrap`, throttled). Staff lifecycle: `auth:staff`,
`staff.standing`, `staff.manage`, `idempotent`. Recovery never touches MFA (R4). A member never creates,
disables or enables themselves; founders are never disabled; the last active `roles.manage` holder stays.

## Permissions

`staff.manage` — *Create, disable or enable staff accounts* — group Access control — CEO only (D20-4).

## Validation

`Password::defaults()` + `confirmed` (D20-5); phone E.164; `code` 6 digits; staff `reason` required (C4).

## Error Handling

New: `reset_link_invalid` 422, `founder_protected` 403, `staff_already_disabled` 409, `staff_not_disabled` 409.
Reused: `otp_invalid`, `otp_expired`, `validation_failed`, `reason_required`, `escalation_denied`,
`last_role_manager`, `too_many_requests`.

## UI States

Forgot-password (phone → code + new password), set-password (token page with an invalid-link banner),
staff actions with a reason modal; loading, error and success banners on each.

## Testing

Pest on `dahab_wt020` (HTTP boundary); Dashboard type-check/build; Flutter analyze/test/build web.

## Breaking Changes

None (additions only; one enum value, handled in the Dashboard).

## Migration / Compatibility

Deploy the Backend first. `DASHBOARD_URL` must be set in production for the staff link. Older clients ignore
the new field and value.

## Decisions

D20-1…5 and C1–C6 are confirmed (owner, 2026-10-11): uniform answers (C1); a reset runs the whole safety stop
(C2); enabling deletes the old password (C3); `reason` on create/disable/enable (C4); links 30 min / 48 h
(C5); `password_set` and `status` filter (C6).
