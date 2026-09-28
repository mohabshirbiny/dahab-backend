# Quickstart: Customer File v1

## Prerequisites

- PostgreSQL with the `dahab` role. Run artisan as the `dahab` DB user, as the tests do.
- `php artisan migrate:fresh --seed`. `LocalCustomerSeeder` gives customers in every state, with the new reason codes.
- Staff: the CEO (holds everything), the COO (`customer.suspend`, `audit.view_own`), Verification (`customer.view`, `audit.view_own`) and IGI (none).

## Backend checks

```bash
composer test -- --filter=CustomerFile
composer test -- --filter=Idempotency
composer test
./vendor/bin/pint --test
composer swagger:generate
php artisan migrate:rollback --step=3 && php artisan migrate
```

## Scenarios (see [contracts](./contracts/dashboard-customer-file.md))

1. **Open the file** (US1): as Verification, `GET /dashboard/customers/{id}` → `documents[]` newest first and `suspension: null`. The audit log gains exactly one `verification_details_viewed` entry. As IGI → 403.
2. **Suspend** (US2): as the COO, POST `/suspend` with `Idempotency-Key: K1` and `{reason: off_platform_dealing, note}` → 200, `status: suspended`, `suspension.status_before: active`.
   - The same call again with K1 → the same body, plus `Idempotent-Replayed: true`, and still one audit entry.
   - K1 with a different note → 422 `idempotency_key_mismatch`.
   - A new key K2 → 409 `customer_already_suspended`.
3. **Suspended customer**: sign in as that customer → 200, and `/customer/auth/me` shows `status: suspended` and the reason. A trade-gated route → 403 `account_suspended`.
4. **Reinstate**: POST `/reinstate` (K3) → 200, `status: active`, `suspension: null`. Reinstating again (K4) → 409 `customer_not_suspended`.
5. **Pending customer**: suspend a `pending_verification` customer, approve their document → still suspended, `status_before: active`. Reinstate → `active`.
6. **History** (US3): the CEO sees registration, submission, review, sign-ins, suspension and file views; `auth.token.rotated` is absent. Verification (view_own) sees only their own file views and reviews.
7. **Sessions**: after two sign-ins from two fingerprints → 2 devices and 2 open sessions. After one signs out, 1 session is listed, and History shows the sign-out.

## Dashboard

```bash
npm run type-check
npm run lint
npm run build
```

Manual pass:
- Users → a row → the file.
- People → Customer file → search by reference.
- The Suspend dialog lists the seven reasons.
- History and Sessions page correctly.
- The controls are hidden without their permissions.

## Customer App

```bash
flutter analyze
flutter test
```

Manual pass: sign in as the suspended customer → the suspended notice with the plain reason (en/ar). Reinstate and restore the session → no notice.
