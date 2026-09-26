# Dynamic Staff Authorization — frontends

> File: `docs/features/dynamic-staff-authorization.md` · Branch: `feature/dynamic-staff-authorization` (backend, dashboard; Flutter has no repo yet)
> Status: in progress · Date: 2026-09-26

## Goal

Bring the Dashboard and the Customer App in line with Backend spec 002 ([`specs/002-dynamic-staff-authorization/`](../../specs/002-dynamic-staff-authorization/spec.md)), which is already built and merged:

- **Staff (role managers: `roles.manage`, `staff.view`)**: see staff and their roles, change a person's roles, and create or edit roles and their permissions from the Dashboard.
- **All staff**: the sidebar and header show their roles from the Backend, not from a fixed list of six.
- **Customers**: an unverified (pending or rejected) customer can now sign in. The app must stop telling them they can't, and must explain `verification_required` when the Backend refuses an action.

## Impact Summary

```
Backend:      NO  (spec 002 done; this doc only)
Database:     NO
API:          NO  (consumes existing endpoints)
Dashboard:    YES
Customer App: YES
Auth:         YES (customer sign-in behaviour changed in spec 002)
Permissions:  YES (Dashboard gates on roles.manage / staff.view)
```

## Backend Impact

None. The Backend is the contract. Endpoints and shapes: spec 002 `contracts/`, the `#[OA]` attributes on `PermissionController`, `RoleController` and `StaffController`, and Postman **Dashboard → Access Control**.

## Database Impact

None.

## API Changes

None. Consumed (all `/dashboard`, `dashboardBearer`):

| Endpoint | Permission | Used for |
|---|---|---|
| `GET /dashboard/permissions` | `roles.manage` | permission catalogue (code, label, group, branch_scoped) in the role editor |
| `GET /dashboard/roles` · `GET /dashboard/roles/{role}` | `roles.manage` | Roles tab and the role picker |
| `POST /dashboard/roles` · `PATCH /dashboard/roles/{role}` · `DELETE /dashboard/roles/{role}` | `roles.manage` | create / edit / delete a role; `reason` required when permissions or MFA change, and on delete |
| `GET /dashboard/staff` · `GET /dashboard/staff/{staff}` | `staff.view` | Staff table |
| `PUT /dashboard/staff/{staff}/roles` | `roles.manage` | Permissions dialog (`roles[]`, `reason` required) |
| `GET /dashboard/auth/me` | — | `roles_detail`, `is_founder` (the `role` field is deprecated) |

## Dashboard Impact

- **Types** (`src/types/api.ts`, `src/types/staff.ts`, new `src/types/access.ts`):
  - `ApiStaffProfile.role` becomes `string | null` (deprecated), and `roles_detail` and `is_founder` are added.
  - The wire and UI shapes for permission, role and staff member.
  - `PERMISSIONS` gains `rolesManage` and `staffView`.
  - `StaffRole` / `STAFF_ROLE_LABELS` are removed: labels come from the Backend's `display_name`.
- **Endpoints / services**: `src/api/endpoints.ts` plus the new `src/services/access.service.ts` (roles, permissions, staff). `toStaffUser` maps `rolesDetail`.
- **Errors** (`src/services/errors.ts`): `escalation_denied` → `forbidden`, `reason_required` → `validation`, `role_in_use` / `last_role_manager` → `conflict`, `verification_required` → `forbidden`. `account_frozen` keeps its existing mapping.
- **Composables**: the new `src/composables/useAccessControl.ts` (queries + mutations, invalidating the staff/roles caches).
- **Pages / components**:
  - A `Staff and permissions` page (`/dashboard/staff`) with tabs **Staff** and **Roles**:
    - The **Staff** tab follows the design reference table (Person · Role · Can do · Last active). "Last active" is not provided by the Backend, so the column is omitted, not faked.
    - Each row has a **Permissions** action that opens a dialog to set roles, with the reason required.
  - **Roles tab: a UI extension beyond the design reference** `docs/dahab-admin-dashboard.html`, which has no role-management screen. It is approved by the product owner (2026-09-26) and built only from existing primitives. It holds a role list and a role editor dialog (name, display name, description, requires-MFA, permissions grouped by catalogue group, reason when required).
- **Navigation**:
  - The "Staff and permissions" item is unhidden, gated on `staff.view`, and the route meta requires `staff.view`.
  - The Roles tab and the Permissions action show only with `roles.manage`.
  - The sidebar and layout show the role display names from `rolesDetail`.
- **Hidden until the Backend provides them** (design shows them): "Create an account", "Freeze", "Suspend".

## Customer App Impact

- `lib/services/auth/auth_messages.dart`:
  - add `verification_required`;
  - reword `account_pending_verification`, since it is no longer returned at sign-in and remains only for older servers.
- `lib/features/auth/signup_screens.dart` (`SignupDoneScreen`): the copy said "You can sign in as soon as it is approved". It now says they can sign in and look around, but buying and selling wait for approval.
- `lib/features/account/account_screen.dart`: the identity pill distinguishes *rejected* from *waiting*, from `status`.
- Tests: in the fake backend (`test/test_app.dart`) a pending account can sign in; `test/flows_test.dart` and `test/live/live_api_test.dart` expect sign-in to succeed for a pending customer.
- No live screen calls a gated endpoint yet (catalog, wallet and orders are still mock), so `verification_required` only needs a message today.

## Authentication / Authorization

- Dashboard: staff guard; UI gating on permission strings only (never role names). The Backend re-checks everything, including no self-escalation (`escalation_denied`).
- Customer: pending and rejected customers are authenticated but unverified. Protected actions return `403 verification_required`.

## Permissions

Reused from spec 002: `staff.view` (Staff tab, nav) and `roles.manage` (Roles tab, Permissions dialog, role editor). Seeded to `ceo` and `coo`.

## Validation

Backend-authoritative. The UI mirrors, for UX only:
- role name `^[a-z][a-z0-9_]{2,49}$`;
- display name ≤ 100 characters, description ≤ 500;
- reason 5–500 characters, required when the permissions or MFA flag change, on delete, and on role assignment.

## Error Handling

| Code | Dashboard message |
|---|---|
| `escalation_denied` | "You can't grant or remove access you don't hold, or change your own access." |
| `reason_required` | shown on the reason field |
| `role_in_use` | "Reassign the staff who hold this role first." |
| `last_role_manager` | "Someone must keep the permission to manage roles." |
| `validation_failed` | per-field messages |

Flutter: `verification_required` → "Verify your identity before doing this."

## UI States

Staff and Roles tabs: loading / empty / error (including permission denied) / success toasts after a change. The dialogs keep their input on a server error and show it inline.

## Testing

- Dashboard: `npm run type-check`, `npm run lint`, `npm run build`. The Dashboard has no unit-test runner.
- Flutter: `flutter analyze`, `flutter test` (the fake-backend flows).
- Backend: unchanged (401 tests).

## Breaking Changes

None for the API. For the Dashboard, the type of `role` changes from a closed union to `string | null`. It is handled in this feature.

## Migration / Compatibility

The Backend is already deployed with spec 002. The Dashboard ignores the deprecated `role`. Flutter keeps the old codes' messages for older servers.
