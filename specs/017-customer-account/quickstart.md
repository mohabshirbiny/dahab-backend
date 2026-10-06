# Quickstart — spec 017

DB: `dahab_wt017` (tests) and `dahab_wt017_dev` (seeding), owned by `dahab`. Always pass `DB_DATABASE=… DB_USERNAME=dahab DB_PASSWORD=secret`; never the main `dahab` DB.

```bash
composer install --ignore-platform-req=ext-pcntl
DB_DATABASE=dahab_wt017_dev DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed
DB_DATABASE=dahab_wt017 DB_USERNAME=dahab DB_PASSWORD=secret ./vendor/bin/pest tests/Feature/Account
```

## Scenarios (each is a Pest feature test)

1. Phone change: request → code (log SMS) → confirm ⇒ phone changed, old number told, pause `phone_change`, unreleased withdrawals cancelled, other sessions and devices gone; replaying the code fails.
2. Email change: request → link (log mail) → `read` → `confirm` ⇒ email changed, old address told, confirmation links replaced, pause `email_change`; second confirm `change_link_invalid`.
3. Password: wrong current ⇒ `current_password_wrong`; right ⇒ other sessions revoked, devices kept.
4. Sessions: two sign-ins ⇒ list marks current; sign out the other ⇒ its token is refused; its device needs an OTP next time.
5. Inbox: trigger an order event ⇒ one item with link `order`; an OTP ⇒ none; read / read-all ⇒ unread count drops.
6. Saved: save live twice ⇒ one row; take the piece down ⇒ `available: false`, no price.
7. Close: with money ⇒ `account_has_open_items` (`wallet_balance`); empty ⇒ closed, sign-in `account_closed`, live listing withdrawn.
8. Report: report another seller's live piece ⇒ `RPT-n`; again ⇒ `report_already_open`; staff take-down ⇒ listing withdrawn, report `actioned`, reporter inbox note.
9. Concurrency (R14) on two connections.

## Then

`./vendor/bin/pint --test` on changed files · `composer swagger:generate` · full suite once in the background to a log · Dashboard `npm run type-check` → lint/build at the end · Flutter `flutter analyze`, targeted tests, full run once, `flutter build web --release`.
