# Quickstart: Founder-account security (spec 021)

## 0. Databases (owner first)

The `dahab` user has no CREATEDB. The owner creates `dahab_wt021` (tests) and `dahab_wt021_dev` (dev data); then:

```bash
psql -l | grep dahab_wt021        # both listed, or STOP
DB_DATABASE=dahab_wt021_dev DB_USERNAME=dahab DB_PASSWORD=*** php artisan migrate:fresh --seed
```

Never run any of this against `dahab`.

## 1. Tests (targeted, sequential)

```bash
DB_DATABASE=dahab_wt021 DB_USERNAME=dahab DB_PASSWORD=*** ./vendor/bin/pest tests/Feature/FounderSecurity
DB_DATABASE=dahab_wt021 DB_USERNAME=dahab DB_PASSWORD=*** ./vendor/bin/pest tests/Feature/Auth/Staff tests/Feature/Authorization/PermissionCatalogueTest.php
```

There is one full run at the end, in the background (about 70 minutes).

## 2. By hand (API on 127.0.0.1:8000, Dashboard on localhost:3000; the owner starts both)

Seeded `ceo@dahab.test` and `coo@dahab.test` are founders.

1. **Trust the CEO's first device:**

   ```bash
   DB_DATABASE=dahab_wt021_dev … php artisan founder:trust-device ceo@dahab.test dev-ceo-1
   ```

2. **Held sign-in.** Postman *Dashboard → Auth → Login* as `coo@` with header `X-Device-Id: dev-coo-new`, then *MFA Verify*. The answer is `device_approval_required` and Postman saves `approval_ref`. *Device Approval Status* answers `pending`.
3. **Approve.** Sign in as `ceo@` with `X-Device-Id: dev-ceo-1` (known, so the session is issued). *Founder → Device Approvals* lists the request. *Approve* it.
4. **Collect.** *Device Approval Status* again (same `X-Device-Id`) returns the session. The next poll answers `401 device_approval_invalid`. A new sign-in as `coo@` from `dev-coo-new` now goes straight through.
5. **Freeze.** As `ceo@`: *Founder → Freeze* `{staff_id: <coo>, reason}`. Any `coo@` token now answers `403 account_frozen`, and `coo@` login answers `403 account_frozen`.
6. **Unfreeze slot 1.** *Auth → Frozen: Confirm Unfreeze* as `coo@` (password + TOTP, `X-Device-Id: dev-coo-new`) answers `slot: 1`.
7. **Unfreeze slot 2.** As `ceo@`: *Founder → Unfreeze* `{freeze_id}` returns `stage: unfrozen`. `coo@` signs in again.
8. **Notices.** *Notices → List* as either founder shows the requests, approval, freeze and unfreeze. Mail goes to the log mailer (`storage/logs`), and the freeze SMS goes to the SMS log driver.

## 3. Deploying to an environment with real founders

1. Check both founders have a phone:

   ```sql
   SELECT email, phone IS NOT NULL FROM staff WHERE is_founder;
   ```

2. Check any already-known founder devices:

   ```sql
   SELECT s.email, f.first_seen_at, f.last_seen_at
   FROM staff_device_fingerprint f JOIN staff s USING (staff_id)
   WHERE s.is_founder;
   ```

3. Deploy the Backend and the Dashboard **together**.
4. Each founder opens the Dashboard, signs in, and reaches the *Waiting for approval* screen. They copy the device id shown there and send it to whoever runs the server.
5. On the server: `php artisan founder:trust-device <email> <device-id>` for each founder. The held request on that device ends; signing in again goes straight through.
