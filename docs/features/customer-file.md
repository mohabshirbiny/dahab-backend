# Customer File v1

> File: `docs/features/customer-file.md` · Branch: `feature/customer-file` (backend, dashboard; Flutter has no repo)
> Status: done (pending merge) · Date: 2026-09-28 · Spec Kit: [`specs/007-customer-file/`](../../specs/007-customer-file/spec.md)

## Goal

Give staff one page with everything about a customer:
- the profile, current state and every identity document;
- History (the audit log about them);
- sign-ins, sessions and devices.

Also let the founders suspend and reinstate an account with a reason and a note. A suspended customer sees a plain notice in the Customer App.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES
Dashboard:    YES
Customer App: YES
Auth:         NO  (sign-in and the trade gate are unchanged; verified by tests)
Permissions:  NO new codes (customer.view, customer.suspend, audit.view_all / audit.view_own)
```

## Backend Impact

- **Actions** (in `app/Actions/Customers`): `SuspendCustomerAction`, `ReinstateCustomerAction`, `ListCustomerActivityAction` and `ListCustomerSessionsAction`.
- **Extended**:
  - `ShowCustomerVerificationDetailsAction`: eager-loads the reviewers and the suspender;
  - `ListCustomersForVerificationAction`: adds `q`;
  - `ReviewIdentityDocumentAction`: a suspended customer stays suspended.
- **Shared idempotency layer**: middleware `idempotent` (`EnforceIdempotency`), model `IdempotencyKey`, and `idempotency:prune` running hourly.
- **Model and enums**:
  - `Customer::suspend()` / `reinstate()`; `transitionTo(SUSPENDED)` is refused;
  - `SuspendedReason` now has the design's seven codes plus labels;
  - `DomainApiException` has 5 new codes.

## Database Impact

- `customer`: gains `suspended_note` and `status_before_suspension`, plus CHECKs `customer_suspension_state` and `customer_suspended_reason_check`. Old reason codes are remapped.
- New table `idempotency_key`, with forced RLS.
- New index `idx_audit_actor_customer`.
- Schema docs: `02_schema_identity.sql`, `05_schema_security.sql` and `00_schema_full.sql`.

## API Changes

See [contracts/dashboard-customer-file.md](../../specs/007-customer-file/contracts/dashboard-customer-file.md).

| Endpoint | Change | Class |
|---|---|---|
| `GET /dashboard/customers/{id}` | New fields: `preferred_lang`, `joined_at`, `documents[]`, `suspension` | Non-breaking |
| `GET /dashboard/customers` | New optional `q` | Non-breaking |
| `POST /dashboard/customers/{id}/suspend` | New (`customer.suspend`, `Idempotency-Key`) | Non-breaking |
| `POST /dashboard/customers/{id}/reinstate` | New (`customer.suspend`, `Idempotency-Key`) | Non-breaking |
| `GET /dashboard/customers/{id}/activity` | New (`customer.view` + an audit permission) | Non-breaking |
| `GET /dashboard/customers/{id}/sessions` | New (`customer.view`) | Non-breaking |
| `suspended_reason` values (the dashboard file/list and `/customer/auth/me`) | Five codes → seven | Potentially breaking. No consumer maps the old values, and none could have been set via the API. |

The idempotency convention is new and is added to `docs/platform/api-contract.md`.

## Dashboard Impact

- Types, services and the `useCustomerFile` composable.
- Pages:
  - `pages/customers/[id].vue` (the file);
  - `pages/customer/index.vue` (search), which replaces the placeholder.
- Components in `components/customer-file/*`.
- Rows in the Users table link to the file.
- The suspend/reinstate dialogs send `Idempotency-Key`.

## Customer App Impact

- `Customer.suspendedReason`.
- A `SuspendedNotice` on home, with wording for the seven codes in en and ar.
- A fake-backend test case.

## Authentication / Authorization

- Staff guard only for the new endpoints.
- Suspend and reinstate need `customer.suspend` (CEO, COO).
- History needs `customer.view` plus `audit.view_all` or `audit.view_own`. A `view_own` holder sees only their own actions.

## Permissions

Existing codes only. The UI hides the Suspend and Reinstate controls, and the History panel, when the matching permission is missing.

## Validation

- **Suspend**: `reason` is one of the seven codes; `note` is required, 1–1000 characters.
- **Reinstate**: `note` is required, 1–1000 characters.
- The dialogs mirror these rules for UX only.

## Error Handling

`customer_already_suspended` (409), `customer_not_suspended` (409), `idempotency_key_required` (400), `idempotency_key_mismatch` (422), `idempotency_in_progress` (409), plus the existing `validation_failed`, `permission_denied`, `not_found` and `account_suspended`.

## UI States

- **Dashboard file**: loading, 404, error, and the suspended banner in the profile card. History and Sessions have empty states.
- **Flutter**: the notice is shown only when the customer is suspended.

## Testing

Pest tests in `tests/Feature/Idempotency` and `tests/Feature/CustomerFile`. Dashboard: type-check, lint, build. Flutter: analyze, test, build. Manual steps: [quickstart.md](../../specs/007-customer-file/quickstart.md).

## Breaking Changes

None, apart from the reason-code values (see API Changes).

## Migration / Compatibility

- Deploy the Backend first. The migrations remap old reason codes and backfill `status_before_suspension='active'` for rows that are already suspended.
- Older Dashboard builds keep working, since only fields were added.
- Rollback: every migration has a `down()`. `other` stays `other`.
