# Research: Account recovery and staff access (spec 020)

Each entry: decision · why · alternative rejected · evidence in code.

## R1 — Where the customer's code lives
**Decision**: an encrypted cache challenge, `App\Services\PasswordResetChallengeStore`, keyed by an HMAC of the normalised phone (not the phone itself). Payload: `customer_id`, `code_hash` (`Hash::make`), `code_expires_at`, `verify_attempts`, `sends`, `last_sent_at`, `challenge_expires_at` (1 h, as the sign-in challenge).
**Why**: the code is 6 digits. The existing pattern for such codes is the cache challenge with a hash and an attempt counter (`CustomerLoginChallengeStore`, `ContactChangeChallengeStore`); spec 020 asks for the *same attempt cap as the sign-in code* (`otp.max_verify_attempts` = 5, `otp.ttl_seconds` = 300). `one_time_token` stores an HMAC of a 256-bit secret and has no attempt counter — a 6-digit code there would be brute-forceable from a database read.
**Rejected**: `one_time_token` purpose `password_reset_customer` with a 6-digit code (weak at rest); a `reset_ref` returned by the request (would need a fake ref for unknown phones to stay uniform — keying by phone avoids it).
**Left alone**: `password_reset_customer` stays an allowed purpose in the CHECK (no migration to remove it); it is simply unused.
**Evidence**: `app/Services/CustomerLoginChallengeStore.php`, `app/Actions/Auth/Customer/VerifyCustomerLoginOtpAction.php` (attempt cap, void on the fifth), `config/dahab-auth.php` `otp`.

## R2 — Uniform answers and the `account_closed` tension (clarification C1)
**Decision**: `reset-request` always answers `202` with the same body (`expires_in_seconds`, `resend_available_at`), computed from config, never from the account. A closed, unknown, or (nothing-to-send) cooling-down phone gets no code. `reset-confirm` answers `401 otp_invalid` for every case with no usable code (unknown phone, closed, no code on file, voided, reused, wrong). `otp_expired` only when a real code exists and its time ran out — this reveals only that a code was requested for that phone, which the requester already knows.
**Why**: Story 1.1 forbids revealing registration; Story 1.4 asked for `account_closed` on reset, which would reveal that a closed account exists. The closed account is still refused (no code is ever sent, and `ConfirmPasswordResetAction` re-checks status under the row lock). `account_closed` remains the answer of password sign-in, unchanged.
**Equal work**: the unknown/closed branch performs one dummy `Hash::make` so timing does not separate the cases; the SMS is queued, so its latency is not on the request path.
**Evidence**: `AssertCustomerCanSignIn` (closed is the only refusal; suspended, pending verification and rejected may sign in, so they may reset).

## R3 — Staff link token
**Decision**: `one_time_token` row, purpose `password_reset_staff`, `actor_staff_id` = the member, secret from `ConfirmationTokens::generate()` (32 random bytes, base64url), only `ConfirmationTokens::hash()` stored, `payload = {"origin":"recovery"|"invitation"|"enabled"}`. Lifetimes (C5): recovery 30 min, invitation/enable 48 h (`dahab-auth.staff_link.recovery_minutes|invitation_hours`). Issuing a link consumes the member's older open `password_reset_staff` rows (the `RequestEmailChangeAction` pattern). Confirm consumes the row with one atomic `UPDATE … WHERE consumed_at IS NULL AND expires_at > now() RETURNING` — two simultaneous uses of one link cannot both win.
**Why**: exactly the single-use link already used for email change (spec 017) and withdrawals (013). The table and CHECK already allow the purpose and require exactly one actor column.
**Link target**: new config `dahab-auth.staff_link.url` = `DASHBOARD_URL` (new `.env.example` key, default `http://localhost:3000`) + `/set-password`, token in the query string. `DASHBOARD_URL` does not exist yet (grep: only `CUSTOMER_APP_URL`).
**Evidence**: `app/Actions/Account/RequestEmailChangeAction.php`, `app/Support/Withdrawals/ConfirmationTokens.php`, `database/migrations/2026_09_19_000050_create_auth_devices_and_tokens.php`.

## R4 — Recovery never touches MFA
**Decision**: `reset-confirm` writes `staff_password.password_hash` and `password_changed_at` only; it does **not** set `force_reenroll_mfa_at` and does not delete `staff_mfa`.
**Why**: the mailbox proves the email, not the person. If recovery forced a re-enrolment, anyone with the mailbox could enrol their own authenticator and walk in — the opposite of "MFA is still required to sign in" (Story 2). `LoginStaffAction::mfaStep` already challenges an enrolled member, or demands enrolment from a founder or a `requires_mfa` role. `force_reenroll_mfa_at` stays reserved for the founder-recovery path (spec 021 / OI-1.2).
**Evidence**: `app/Actions/Auth/Staff/LoginStaffAction.php` (`mfaStep`).

## R5 — A reset runs the whole safety stop (D20-2, clarification C2)
**Decision**: `ConfirmPasswordResetAction` calls `WithdrawalSafetyStop::apply(customerId, PauseTrigger::PASSWORD_RESET, 'password reset', actorCustomerId:)` inside the same transaction, after `lockPayoutAccounts` and the customer row lock — the order spec 017 uses. That cancels withdrawals not yet released (money back to available, ledger entry) and opens the pause for `withdrawal.account_change_pause_hours`.
**Why**: D20-2 says "the same setting, because a reset proves only the phone". The pause function is shared and also cancels; skipping the cancellation would leave a queued payout an attacker with the SIM could still get released.
**Migration**: `withdrawal_pause.trigger_kind` CHECK widened to add `password_reset`; the spec 017 migration's rollback guard (`trigger_kind <> 'payout_account'`) already covers the new value; this migration's `down()` refuses while a `password_reset` pause exists.
**Consumers**: `CustomerFileResource` (enum in `#[OA]` and value), Dashboard `types/customer.ts:187-192` and `types/api.ts:202`. Flutter does not read it.
**Evidence**: `app/Support/Withdrawals/WithdrawalSafetyStop.php`, `app/Actions/Account/ConfirmPhoneChangeAction.php`.

## R6 — Sessions and devices
**Decision**: `CustomerSessions::endOthers($customer, null)` and `forgetOthers($customer, null)` — with no "keep" argument they remove every token family and every trusted device. No session is issued by `reset-confirm`; the customer signs in normally and, because devices were forgotten, answers the new-device code once.
**Why**: Story 1.2. There is no current session to keep, so "every other" is "all".
**Evidence**: `app/Support/Account/CustomerSessions.php`.

## R7 — Clearing the sign-in lockout
**Decision**: on success, clear the customer's `auth.customer.login` limiter bucket (the key `ChangePasswordAction::countFailedSignIn` builds: `md5('auth.customer.login'.'cust-login-id:'.lower(phone))`) and the staff equivalent for staff, so someone locked out after forgetting the password can use the new one at once.
**Why**: the usual reason to reset is a run of wrong guesses; leaving the lock would make the reset look broken. The reset itself already proved control of the phone / mailbox, and has its own limits.
**Risk accepted**: an attacker who also holds the phone/mailbox gets the lockout cleared — they already own the account at that point.

## R8 — Staff creation without a password
**Decision**: `CreateStaffAction` inserts the `staff` row (`is_active` true, `is_founder` false — not fillable, `created_by` = actor, `branch_id` optional and validated as `SetStaffBranchAction` does) and **no** `staff_password` row. `LoginStaffAction` already treats `password === null` as an unknown account (`invalid_credentials`), so an invited member cannot sign in until the link is used. Roles are assigned inside `withAuthzLock` with `RoleEscalationGuard::assertCanAssign` (actor must hold every permission of every role granted) and `ReasonRule::assertPresent`.
**Why**: `staff_password.password_hash` is `NOT NULL`; a placeholder hash would be a known-unknown credential. No row is the honest "no password yet" state. "No endpoint ever returns or sets a known password" (Story 2) holds: the member chooses it through the link.
**Evidence**: `app/Models/Staff.php` (`$fillable`), `LoginStaffAction` line ~45, `SetStaffRolesAction`.

## R9 — Disable and enable
**Decision**:
- Disable (`staff.manage`, reason required — C4): inside `withAuthzLock`; refuse self (`RoleEscalationGuard::assertNotSelf(..., 'disable_self')`, the existing 403 `escalation_denied`), founder (`403 founder_protected`), already disabled (`409 staff_already_disabled`); set `is_active = false`; delete every Sanctum token of the member; consume their open links; the lock's post-check `assertRoleManagerRemains()` (counts **active** holders of `roles.manage`) throws `409 last_role_manager` and rolls back. History keeps the name — nothing is deleted, rows reference `staff_id`.
- Enable: refuse unless disabled (`409 staff_not_disabled`); set `is_active = true`; **delete the `staff_password` row** (C3) so the 48 h link is the only way in; issue the `enabled` link. `staff_mfa` is kept.
- Both audited with reason and before/after.
- `EnsureStaffStanding` already re-reads `is_active` on every request and revokes the token, so a disabled member is out on their next call even before the token deletion.
**Evidence**: `LocksStaffAuthorization.php` (`is_active` filter), `EnsureStaffStanding.php`, `LoginStaffAction` (`inactive` fails as `invalid_credentials`).

## R10 — Permission `staff.manage`
**Decision**: add `StaffPermission::STAFF_MANAGE = 'staff.manage'` (label *Create, disable or enable staff accounts*, group *Access control*, `seedRoles() = []`). A migration inserts the permission row and gives it to the `ceo` role (the spec 019 pattern: seed inside the migration so a deploy needs no manual sync, the catalogue sync remains additive and idempotent). Not given to any other seed role (D20-4).
**Create + roles**: creation requires `staff.manage` only; the roles it grants go through the escalation guard, which already bounds an actor to permissions they hold. `roles.manage` is not additionally required: the CEO holds both, and a future non-CEO holder of `staff.manage` could grant only roles within their own permissions.
**Evidence**: `SyncPermissionCatalogueAction`, `StaffPermission`, `database/migrations/2026_10_12_*` (019 pattern).

## R11 — Rate limits
**Decision** (config `dahab-auth.rate_limits`, limiter names in `AppServiceProvider`):
- `auth.password_reset.request` — exists: 3 / hour per identity (`phone`, else `email`), 20 / hour per IP. Add a third bucket **per device** (`X-Device-Id`, falling back to IP): 10 / hour (`per_device_max`, `per_device_window`). Used by both customer and staff request routes.
- `auth.password_reset.confirm` — new: customer, per phone 10 / hour + per IP 30 / hour; plus the per-code cap of 5 wrong tries.
- `auth.staff.password_reset.confirm` — new: per IP 20 / hour (the token is 256-bit; this only limits noise).
Throttled → the standard `429 too_many_requests`.
**Why**: Story 1.1 asks for per phone and per device.
**Evidence**: `AppServiceProvider.php:220-228`, `config/dahab-auth.php` `password_reset_request`.

## R12 — Messages and the App text catalogue
**Decision**:
- `auth.password_reset_code.sms` (code → read-only, D7): the SMS code, via `PasswordResetCodeNotification` on an on-demand `sms` route to the phone, never the inbox (copy of `PhoneChangeCodeNotification`).
- `AccountEvent::PASSWORD_RESET` with `account.password_reset.sms|mail_subject|mail_body…|inbox` (editable): told on SMS + email (when there is one) + inbox, saying the password was reset, that other devices were signed out, and — when a pause opened — until when withdrawals are paused and how many were cancelled; "if this was not you, contact support". Reuses `AccountNotification` with `pauseUntil` and `cancelledWithdrawals`.
- Staff mails (invitation, recovery, enabled) are staff-facing English in `StaffPasswordLinkNotification`, like `FounderAlertNotification` (spec 019 D5). They carry a link, so they would be read-only in App text anyway (D7). This narrows the spec's Surfaces sentence "(SMS + email templates, editable in App text)": the customer notice is editable, the code SMS and the staff link mail are not.
- Every new entry is added to `CatalogueData`, `tests/Support/NotificationCases.php` (golden/inventory test) and the manifest; Arabic marked AR-DRAFT.

## R13 — Password rule
**Decision**: `Password::defaults()` (min 10, `uncompromised()` outside tests) + `confirmed`, for customer and staff — D20-5. No "must differ from the old one" rule: the old one is not recoverable from the hash by design and sign-up/change have none.

## R14 — What is deliberately not built
- No `reset-check` / link-read endpoint: the set-password page validates on submit (`422 reset_link_invalid`).
- No "resend invitation" endpoint: self-service *Forgot password?* covers a lost invitation.
- No "staff told their password changed" mail; no staff phone/SMS path.
- No customer-side use of `one_time_token`.
- Founder-account recovery, founder device approval, freeze (spec 021).
