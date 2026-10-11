# Quickstart: verifying spec 020

Run against `dahab_wt020` / `dahab_wt020_dev` only — pass `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` on the command line; never the primary `dahab` database. Servers: API `127.0.0.1:8000`, Dashboard `localhost:3000`, customer app `127.0.0.1:8765`. Set `DASHBOARD_URL=http://localhost:3000` in `.env`.

## 1. Customer forgot password
1. Seed data (`migrate:fresh --seed` on `dahab_wt020_dev`), a customer Hoda with trusted devices and a pending withdrawal.
2. App → Sign in → *Forgot your password?* → enter the phone → *Send code*. Dev SMS channel logs the code (`storage/logs`).
3. Enter code + new password twice → *Reset password* → "Signed in nowhere else; withdrawals paused until …".
4. Sign in with the new password: the new-device code is asked once. Old password fails. Her other session (Postman token) answers `401 unauthenticated`.
5. Customer file (Dashboard): *Withdrawals paused — a password reset*; the withdrawal is cancelled and her available balance is back.
6. Wrong code ×5 → `otp_invalid`, then the right code also fails (voided); request again → new code works. Unknown phone → same screen, no SMS, `otp_invalid` on confirm.

## 2. Staff recovery
1. Dashboard login → *Forgot password?* → email → the same message for any address.
2. Mail log → link → *Set a new password* → success → back to sign in → password + authenticator code.
3. Reuse the link → `reset_link_invalid`. Request twice → only the newest link works.

## 3. Staff lifecycle (as the CEO)
1. Users → Staff → *Add staff member* (name, email, roles, reason) → invited row (*Invitation pending*) and an invitation mail.
2. Open the link, set the password, sign in, enrol the authenticator.
3. *Disable* with a reason → the member's open session ends on its next call; sign-in answers `invalid_credentials`; the row moves to the *Disabled* tab.
4. *Enable* → the old password no longer works; a new link is mailed.
5. Refusals: disable yourself (403), a founder (`founder_protected`), the last role manager (409).

## 4. Commands
```bash
DB_DATABASE=dahab_wt020 … ./vendor/bin/pest tests/Feature/Auth/CustomerPasswordResetTest.php   # targeted files while building
php artisan route:list --path=password            # the 4 public routes
composer swagger:generate
./vendor/bin/pint --test
# Dashboard
npm run type-check && npm run build
# Flutter
python3 tool/gen_text_catalogue.py && flutter analyze && flutter test && flutter build web --release
php artisan app-texts:sync                         # Backend, after the manifest is copied
```
One full Pest run at the end (≈30 min, sequential); then only failing files.
