# Staff edit and delete, required phone, founder new-device code

> File: `docs/features/staff-edit-delete.md` · Branch: `claude/founder-login-staff-management-*` (Backend), Dashboard main checkout
> Status: done (uncommitted) · Date: 2026-10-27

## Goal

Owner's notes of 2026-10-27:

1. A founder signing in still saw the *Waiting for approval* screen (the device id) after the new-device setting
   was changed. Cause: `FounderDeviceGate` sent the SMS code to a founder only for their **first** device, and never
   when the founder had no phone on file; every other new founder device waited for the other founder.
   Decision: while `auth.staff_new_device_sms` is on and the founder has a phone, **any** new founder device is
   confirmed by the SMS code, like other staff. With the setting off (or no phone) a founder still waits for the
   other founder.
2. Staff can be edited (name, work email, phone) and deleted.
3. The staff phone is required (the new-device code and the founder alerts go to it).

Decisions (owner, 2026-10-27): founders get the SMS code like all staff; delete is a soft delete.

## Impact Summary

```
Backend:          YES
Database:         YES (staff.deleted_at, CHECK deleted ⇒ inactive, email unique among staff not deleted)
API:              YES (2 new endpoints; phone now required on create — potentially breaking, the Dashboard sends it)
Dashboard:        YES (Edit and Delete on Staff, phone required on Add)
Customer App:     NO  (no customer endpoint changes)
Auth:             YES (a founder's later new device answers device_code_required instead of device_approval_required)
Permissions:      NO  (staff.manage, as create/disable/enable)
API models/types: YES (Dashboard UpdateStaffInput)
```

## Backend

| Endpoint | Rule |
|---|---|
| `PATCH /dashboard/staff/{staff}` `{full_name?, email?, phone?, reason}` | `staff.manage`, `idempotent`. Only the fields sent change; the phone cannot be emptied (E.164). A founder's details only by a founder (`403 founder_protected`). A new email voids open set-password links; a member with no password yet gets a fresh invitation at the new address. Audit `authz.staff.updated` (before/after), founder alert |
| `DELETE /dashboard/staff/{staff}` `{reason}` | `staff.manage`, `idempotent`, 204. Soft and final: `deleted_at` + `is_active = false`, tokens deleted, links voided; not yourself (`escalation_denied`), never a founder (`founder_protected`), the last role manager stays (`409 last_role_manager`). The member leaves every list and lookup (`Staff::manageable()`), cannot sign in, history keeps the name; the email is free for a new account. Audit `authz.staff.deleted`, founder alert |
| `POST /dashboard/staff` | `phone` now required |

Sign-in, password reset and the frozen-founder confirm look a staff member up by email among the not deleted only.
Existing members with no phone keep working; the Dashboard's Edit asks for one.

## Dashboard

Staff → Accounts: *Edit* (name, work email, phone, reason; a founder row only for a founder) and *Delete* (confirm
with a reason; not yourself, not a founder). *Add staff member* requires the phone.

## Tests

`tests/Feature/Authorization/StaffEditDeleteTest.php`; `StaffDeviceCodeTest` (a later founder device by SMS; with the
setting off, the other founder); `StaffLifecycleTest`, `AuditCatalogueTest`, `OpenApiGenerationTest` updated.
