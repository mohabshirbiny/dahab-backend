# Quickstart: Wallet Top-up (spec 009)

How to prove the feature works end to end. Shapes are in [contracts/topup-api.md](./contracts/topup-api.md); tables and rules in [data-model.md](./data-model.md).

> **Release note (analysis C1)**: the seeded receiving accounts are fake and local-only. Production top-ups need US4 (the Receiving accounts page) so staff can enter Dahab's real accounts before customers are shown anything.

## Prerequisites

- PostgreSQL 16 and Redis running (Laragon). Run artisan as the `dahab` DB user (the tests' owner), never `postgres`.
- `php artisan migrate:fresh --seed` — seeds the roles (Finance gets `topup.match` and `topup.accounts.manage`; the COO does not), the system actor and one fake receiving account per method.
- A verified customer and a suspended customer (factories/seeders), a Finance staff member, and a COO staff member (MFA done).

## Automated checks

```bash
composer test -- --filter=TopUp
composer test -- --filter=ReceivingAccount
composer test
./vendor/bin/pint --test
composer swagger:generate
```

Expected: all green; OpenAPI lists the 4 customer and 12 dashboard operations; `php artisan route:list --path=topup` shows `idempotent` on every POST except the receiving-account routes.

## Manual walk-through (Postman: folder "Wallet top-up")

1. **Methods** — as the verified customer, `GET /customer/me/wallet/topup-methods` → reference `DAHAB-<display_ref>` and three methods. As the suspended customer → 403 `account_suspended`; as a pending customer → 403 `verification_required`.
2. **Receipt** — `POST /customer/me/uploads` with `purpose=topup_receipt` and a PDF → `upload_token`.
3. **Notice** — `POST /customer/me/wallet/topups` with a new `Idempotency-Key`, amount `20000.00`, the InstaPay account and the token → 201, `pending`. Send it again with the same key → same body, `Idempotent-Replayed: true`; `GET /customer/me/wallet` unchanged.
4. **Staff list** — as Finance, `GET /dashboard/topups?q=DAHAB-<ref>` → the notice with `has_receipt: true`; `GET …/receipt` streams the PDF. As the COO → 403 `permission_denied`.
5. **Match with a difference** — `POST /dashboard/topups/{id}/match` amount `19900.00` without a note → 422; with a note → 200 `credited`. Then:
   - `GET /customer/me/wallet` → available +19,900.00;
   - `GET /dashboard/wallets/overview` → bank cash +19,900.00, system total 0;
   - `GET /customer/me/wallet/transactions` → a `topup` row with `reference` `TOP-<n>`;
   - the Audit log (Dashboard) shows `topup.matched` with the note;
   - the log mail/SMS driver shows the "credited" message.
5a. **Suspended customer's notice** — suspend a customer who has a pending notice; as that customer, `GET /customer/me/wallet/topups` still lists it and `POST …/topups/{id}/cancel` works on a second pending notice, while `topup-methods` and `POST …/topups` return 403 `account_suspended`. As Finance, match the first notice without `arrival_reference` → 422; with it → 200, audit `customer_status: suspended`.
6. **Double match** — repeat step 5 with a new key → 409 `illegal_topup_transition`; the balance is unchanged.
7. **Hold, reject, cancel** — submit two more notices; hold one (note), un-hold, reject it `money_not_received` → customer sees `rejected` with the reason and gets a message; cancel the other as the customer → `cancelled`; matching it → 409.
8. **By hand** — `POST /dashboard/topups` for an active customer, 15,000.00 by Vodafone Cash with a note → 201 `credited`, `origin: by_hand`. For the suspended customer (verified before suspension): without `arrival_reference` → 422; with it → 201, and the audit row shows `customer_status: suspended`. Meanwhile, as that suspended customer, `GET /customer/me/wallet/topup-methods` and `POST /customer/me/wallet/topups` still return 403 `account_suspended`. For a pending customer → 403 `verification_required`.
9. **Receiving accounts** — as Finance, create a second InstaPay account, deactivate it; the customer's methods list shows then hides it; the audit log has both changes with before/after. As the COO → 403.

## Consumers

- **Dashboard** (`../dahab-dashboard`): `npm run type-check && npm run lint && npm run build`. Sign in as Finance → *Money → Incoming transfers*: filter, search by reference, open a notice, view the receipt, match / hold / reject, *Credit by hand*. *Controls → Receiving accounts*: add, edit, deactivate. The COO sees neither menu item.
- **Customer App** (`../dahab-flutter`): `flutter analyze && flutter test`, then run with the local API: *Wallet → Add funds* shows the live accounts and reference, attaches a receipt, *I've sent the transfer* → the notice appears as pending in *Top-ups*; after the Finance match the wallet balance and history update.
