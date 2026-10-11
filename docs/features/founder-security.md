# Founder-account security

> File: `docs/features/founder-security.md` · Branch: `feature/founder-security` in Backend and Dashboard
> Status: done (built, not committed) · Date: 2026-10-11 · Spec: [`specs/021-founder-security/`](../../specs/021-founder-security/spec.md)

## Goal

Part 1 §8 calls a compromised founder account the largest single risk. Spec 021 builds its three protections:

1. a founder's sign-in from a new device is held until the **other** founder approves it;
2. either founder can **freeze** the other at once; unfreezing needs **two** confirmations, the founder who is not frozen last;
3. both founders are told in real time (email + Dashboard notice, SMS for a freeze) of sensitive actions on either founder account.

Decisions (owner, 2026-10-11): D21-1 recovery stays manual; D21-2 both founders confirm, the one not frozen last; D21-3 email +
Dashboard notice, SMS only for a freeze; D21-4 an approval lasts 15 minutes and is single use. C1: slot 1 is the frozen founder
(tokenless call) or a holder of `founder.unfreeze_second`; slot 2 is always the founder who is not frozen. C2: the server
command `founder:trust-device` trusts a founder's first device. Plan: Q1 yes (`founder.unfreeze_second` to the `ceo` role),
Q2 no (the spec list only), Q3 yes (*Freeze* offered beside a refused device).

## Impact Summary

```
Backend:      YES — gate after MFA, poll/collect, approve/refuse, freeze, two unfreeze paths, console command, notices, audit hook
Database:     YES — one migration: founder_device_approval + account_freeze columns/CHECKs/guards DH021–DH022, staff_notice, a permission
API:          YES — 12 new endpoints; a keyed variant on login / mfa/verify / mfa/enroll; 422 device_id_required for founders
Dashboard:    YES — X-Device-Id on every request, waiting screen, frozen screen, Security page, notices bell
Customer App: NO  — nothing under /customer/*, /market/*, /reference/* changes; no customer-visible text
Auth:         YES — a held step after MFA for founders; tokens revoked on freeze; a tokenless credential check for a frozen founder
Permissions:  YES — founder.unfreeze_second (CEO role); founder-only routes gate on staff.is_founder (a flag)
```

## Backend Impact

- `App\Actions\Auth\Staff\FounderDeviceGate` (called by `LoginStaffAction` without MFA, `VerifyStaffMfaAction`, `EnrollStaffMfaAction`
  just before `CompleteStaffSignInAction`), `CollectDeviceApprovalAction`, `ConfirmFrozenFounderUnfreezeAction`.
- `App\Actions\FounderSecurity\*`: `DecideDeviceApprovalAction`, `ListDeviceApprovalsAction`, `ListFounderDevicesAction`,
  `TrustFounderDeviceAction` (+ `founder:trust-device`), `FreezeFounderAction`, `ConfirmUnfreezeAction`, `ListFreezesAction`.
- `App\Actions\Notices\{ListStaffNoticesAction, MarkStaffNoticeReadAction}`.
- `App\Support\Auth\StaffTotpVerifier` (extracted from `VerifyStaffMfaAction`, shared with the narrow path), `DeviceFingerprint`.
- `App\Support\FounderSecurity\FounderAlerts` + `FounderSecurityNotification` + `StaffNoticeChannel` (`staff_notice`); one hook in
  `RecordAuditLogAction` (after commit) for `AuditEvent::founderAlert()`.
- Middleware `EnsureFounder` as `staff.founder` and `staff.founder_or:<permission>`; limiter `auth.staff.device_approval`;
  config `dahab-auth.founder_device_approval_enforced` (forced on in production), `founder_device_approval.ttl_minutes` (15),
  `poll_interval_seconds` (5), `dashboard_url`.
- `CompleteStaffSignInAction` keeps `first_seen_at` on a known device (it used to overwrite it) and accepts `via: device_approval`.

## Database Impact

`2026_10_14_000010_founder_security.php`, mirrored in `05_schema_security.sql` (and `00_schema_full.sql`):
`founder_device_approval` gains `expires_at`, `ref_hash`, `platform`, `user_agent`, `ip_address`, `refused_by/at`, `decision_note`,
`consumed_at`, five CHECKs and guard **DH021**; `account_freeze` gains `reason` (backfilled), the confirmation times and slot 1's
kind, nine CHECKs, a unique open-freeze index and guard **DH022** (the freezer must be a founder; the target is not checked in the
database so `StaffFactory::frozen()` stays valid); new table `staff_notice` (with a `dedupe_key`, like the customer inbox); the
permission `founder.unfreeze_second`, given to `ceo`. `down()` refuses once spec 021 data exists.

## API Changes

See `docs/platform/api-contract.md` ("Founder-account security and Dashboard notices") and
[`contracts/founder-security-api.md`](../../specs/021-founder-security/contracts/founder-security-api.md).

- New (non-breaking): `POST /dashboard/auth/device-approval/status`, `POST /dashboard/auth/frozen/confirm-unfreeze`,
  `GET /dashboard/founder/device-approvals`, `POST …/{id}/approve|refuse`, `GET /dashboard/founder/devices`,
  `POST /dashboard/founder/freeze`, `GET /dashboard/founder/freezes`, `POST /dashboard/founder/unfreeze`,
  `GET /dashboard/notices`, `POST /dashboard/notices/{id}/read`, `POST /dashboard/notices/read-all`.
- **Potentially breaking** (founders only, shipped with the Dashboard): the `device_approval_required` variant on `mfa/verify` and
  `mfa/enroll` (and `login` when MFA is off); `422 device_id_required` for a founder without `X-Device-Id`.
- New `via` value `device_approval` in the `auth.staff.sign_in` audit payload (non-breaking).

## Dashboard Impact

- `src/api/device.ts` (id in `localStorage` `dahab.device_id`), `axios.ts` sends `X-Device-Id` + `X-Device-Platform: web`; a live
  session that answers `account_frozen` signs out and routes to `/frozen` (`setAccountFrozenHandler`).
- Types `api.ts` (wire), `staff.ts` (`DeviceApprovalPending`, `StaffSignInResult`, `PERMISSIONS.founderUnfreezeSecond`),
  `security.ts`, `notices.ts`; services `staff-auth.service.ts` (held variant, `pollDeviceApproval`, `confirmUnfreezeAsFrozen`),
  `security.service.ts`, `notices.service.ts`; composables `useSecurity`, `useNotices`; store `auth.ts` (`pendingApproval`,
  polling, `approvalEnd`, `frozenEmail`).
- Pages: `/device-approval` (waiting, countdown, the device id with *Copy*, *Cancel*), `/frozen` (plain message, *Confirm my
  unfreeze*), `/dashboard/security` (pending approvals, freeze/unfreeze, known devices, history; Q3 freeze offer). Sidebar
  *Security* for founders and holders of `founder.unfreeze_second`; the bell `NoticesMenu` in `AppTopbar`; the Audit page opens
  `?entry=<id>` (the notices link there).

## Customer App Impact

Not affected — the Flutter app never calls `/dashboard/*`, and no `/customer/*`, `/market/*` or `/reference/*` route, field or text
changes.

## Authentication / Authorization

Founder-only routes: `auth:staff`, `abilities:staff:access`, `staff.standing`, then `staff.founder`; the freezes list and unfreeze use
`staff.founder_or:founder.unfreeze_second`. The two public routes carry `db.elevate:bootstrap` and their limiters. A frozen account
never receives a token (the narrow path only records slot 1).

## Permissions

`founder.unfreeze_second` — group *Access control*, held by the `ceo` role (migration + catalogue sync), no other seed role. Founder
status (`staff.is_founder`) is never a permission.

## Validation

FormRequests: `approval_ref` ≤ 128; `note` ≤ 500; freeze `staff_id` uuid and `reason` ≤ 1000 (the Action enforces
`reason_required`, ≥ 5 characters); confirm-unfreeze `email`, `password`, `code` (6 digits) xor `recovery_code`. The Dashboard
mirrors only the required/length checks.

## Error Handling

New codes: `device_id_required`, `device_approval_refused`, `device_approval_invalid`, `device_approval_not_pending`,
`device_not_known`, `founder_only`, `founder_self_action`, `freeze_target_invalid`, `already_frozen`, `not_frozen`,
`unfreeze_order`, `unfreeze_already_confirmed` (SQLSTATE DH021 / DH022 map to the same codes). The Dashboard maps them in
`services/errors.ts` and explains them in `components/security/securityErrors.ts`, the waiting and the frozen screens.

## UI States

Each Security panel and the bell have loading / empty / error states; the waiting screen shows pending, refused, ended and frozen;
the frozen screen shows success and each refusal. Pending approvals refetch every 15 s, freezes every 30 s, the bell every 30 s.

## Testing

Pest: `tests/Feature/FounderSecurity/{DeviceApprovalTest, TrustDeviceCommandTest, FreezeTest, UnfreezeTest, FounderAlertsTest,
StaffNoticesTest}.php`, `tests/Feature/Migrations/FounderSecurityMigrationTest.php`, catalogue tests, and the inventories
(`OpenApiGenerationTest`, `PrincipalIsolationTest` 246, `ElevationTest`). Dashboard: `type-check`, `lint` on touched files, `build`.
Manual steps: `specs/021-founder-security/quickstart.md`.

## Breaking Changes

Potentially breaking for founders only (above); no `/api/v2` needed because the only consumer, the Dashboard, ships in the same
release.

## Migration / Compatibility

Deploy the Backend and the Dashboard **together**; an older Dashboard sends no `X-Device-Id`, so every founder sign-in would answer
`422 device_id_required`. Right after the migration, each founder opens the Dashboard, reaches *Waiting for approval*, copies the
device id, and the server owner runs `php artisan founder:trust-device <email> <device-id>` for each. Check both founders have a
phone (SMS on a freeze). Locally, `DAHAB_AUTH_FOUNDER_DEVICE_APPROVAL=false` turns the hold off (never in production). Rollback:
`migrate:rollback` refuses once spec 021 data exists.
