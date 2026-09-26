# Quickstart — Dynamic Staff Authorization, Customer Verified Gate, System Actor

A validation guide: how to prove the feature works end to end. Contracts: [contracts/openapi.yaml](./contracts/openapi.yaml), [contracts/error-codes.md](./contracts/error-codes.md). Data: [data-model.md](./data-model.md).

## Prerequisites

Same as [spec 001 quickstart](../001-auth-customer-staff/quickstart.md). Run DB commands as the `dahab` user, never the `.env` `postgres` user:

```bash
DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed
```

Local accounts: `<role>@dahab.test` / `seeded-password-1` for `ceo`, `coo`, `finance`, `operations`, `verification`, `igi_branch`. `ceo` and `coo` are founders (MFA required).

## 1. Migration preserves today's behaviour (SC-003)

```bash
composer test -- --filter=StaffAuthorizationMigrationTest
```

Expected: every seeded staff member has the same `permissions` in `/dashboard/auth/me` as before. `ceo`/`coo`/`finance` still get `mfa_required` or `mfa_enrollment_required` at login. `staff.role` column and `staff_role` type are gone. Exactly one `is_system` staff row exists.

Manual check (psql):

```sql
SELECT count(*) FROM staff WHERE is_system;              -- 1
SELECT email, is_founder FROM staff WHERE is_founder;    -- ceo@, coo@
SELECT name, requires_mfa FROM roles ORDER BY name;      -- ceo/coo/finance true
```

## 2. Manage roles (User Story 1)

Signed in as `ceo@dahab.test` (Postman folder **Dashboard → Access Control**):

1. `GET /api/v1/dashboard/permissions` → catalogue grouped (includes `roles.manage`, `staff.view`).
2. `POST /api/v1/dashboard/roles` `{ "name": "customer_support", "display_name": "Customer support", "permissions": ["customer.view"] }` → `201`.
3. `PUT /api/v1/dashboard/staff/{operations id}/roles` `{ "roles": ["operations", "customer_support"], "reason": "Covers support queue" }` → `200`.
4. As `operations@`: `GET /api/v1/dashboard/customers` → `200` (was `403`).
5. As `ceo@`: `PATCH /api/v1/dashboard/roles/customer_support` `{ "permissions": [], "reason": "Queue closed" }` → `200`.
6. As `operations@`, **same token**: `GET /api/v1/dashboard/customers` → `403 permission_denied` (SC-002).
7. `GET /api/v1/dashboard/roles` and check `audit_log` rows `authz.role.created`, `authz.staff.roles_changed`, `authz.role.permissions_changed` with reasons (SC-006).

## 3. Safeguards

| Try (as) | Expect |
|---|---|
| `PATCH /roles/customer_support` changing `permissions` without `reason` (ceo) | `422 reason_required` |
| `DELETE /roles/customer_support` while `operations@` holds it (ceo) | `409 role_in_use` |
| `PUT /staff/{ceo id}/roles` `{roles: []}` (ceo, on self) | `403 escalation_denied` |
| `PATCH /roles/ceo` anything (ceo holds it) | `403 escalation_denied` |
| `PATCH /roles/operations` adding a permission `coo` lacks (coo) | `403 escalation_denied` |
| Zero-managers backstop | Not reachable through the API (the actor always keeps `roles.manage`, see research R7); covered by `LastRoleManagerInvariantTest` at Action level → `409 last_role_manager` |
| Any request body containing `is_founder` | ignored; founder status unchanged (SC-008) |
| `GET /staff/{system actor id}` | `404 not_found` |
| `POST /dashboard/auth/login` as `system@dahab.internal` | `401 invalid_credentials` |

## 4. Customer verified gate (User Story 3)

With an unverified customer (register via spec 001 flow, do not approve):

- `POST /customer/auth/login` → `200` (sign-in works).
- `GET /customer/auth/me`, `POST /customer/me/uploads`, `POST /customer/me/identity-documents` → `2xx`.
- The architecture test proves every other `auth:customer` route is gated:

```bash
composer test -- --filter=CustomerRouteGateTest
```

Adding a customer route without `customer.gate:*` and without listing it in `App\Http\CustomerRouteAccess::OPEN_ROUTES` must make this test fail.

## 5. MFA follows founder flag and role flag (User Story 4)

```bash
composer test -- --filter=StaffMfaRequirementTest
```

Covers: a new role with `requires_mfa` forces enrollment. Turning it off stops forcing it for non-founders. Founders are forced regardless of role flags.

## 6. Full gate before PR

```bash
./vendor/bin/pint
composer test
composer swagger:generate
DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed
DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:rollback --step=3
DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate
php artisan route:list --path=api/v1/dashboard
```
