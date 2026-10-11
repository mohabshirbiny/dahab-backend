# Data model: Account recovery and staff access (spec 020)

## 1. Schema changes — one migration, `database/migrations/2026_10_13_000010_account_recovery.php`

No new table and no new column. What already fits:

| Need | Existing structure | Use |
|---|---|---|
| Staff link token | `one_time_token(token_hash, purpose, actor_staff_id, payload, expires_at, consumed_at)`; purpose `password_reset_staff` already in the CHECK; CHECK `(actor_customer_id IS NOT NULL) <> (actor_staff_id IS NOT NULL)` | one row per open link; `payload.origin` = `recovery \| invitation \| enabled` |
| Staff with no password yet | `staff` row without a `staff_password` row (`staff_password.password_hash` stays `NOT NULL`) | invited / re-enabled member |
| Disabled staff | `staff.is_active BOOLEAN NOT NULL DEFAULT TRUE` (read by `EnsureStaffStanding`, `LoginStaffAction`, the role-manager check) | disable = false |
| Created by | `staff.created_by UUID REFERENCES staff` | the acting staff member |
| Customer code | cache (not the database) | R1 |
| Customer password | `customer_password(password_hash, password_changed_at)` | update |

### Changes

1. `withdrawal_pause.trigger_kind`: drop and re-add the CHECK as
   `CHECK (trigger_kind IN ('payout_account','phone_change','email_change','password_reset'))`.
   The companion CHECK `trigger_kind = 'payout_account' OR triggered_by_account IS NULL` is unchanged (a reset names no account).
2. Permission `staff.manage`: insert into `permissions` (`guard_name = 'staff'`) if missing; give it to the role `ceo` if that role exists. (No other role.)
3. `down()`: refuses with `RuntimeException('Spec 020 data exists: this migration cannot be rolled back.')` when any `withdrawal_pause.trigger_kind = 'password_reset'` exists; otherwise restores the three-value CHECK and detaches then deletes the permission.

Mirrors in `docs/Database schema/05_schema_security.sql` ("Changed by spec 020"): the widened CHECK. The permission is data, not schema.

## 2. Enums and constants

| Where | Change |
|---|---|
| `App\Enums\PauseTrigger` | `case PASSWORD_RESET = 'password_reset'` |
| `App\Enums\StaffPermission` | `STAFF_MANAGE = 'staff.manage'` — label *Create, disable or enable staff accounts*, group *Access control*, `seedRoles()` empty |
| `App\Enums\AccountEvent` | `case PASSWORD_RESET = 'password_reset'` |
| `App\Enums\AuditEvent` | `auth.staff.password_reset_requested` (SESSIONS); `authz.staff.created`, `authz.staff.disabled`, `authz.staff.enabled` (ACCOUNTS). Already present and now used: `auth.customer.password_reset_requested`, `auth.customer.password_reset_completed`, `auth.staff.password_reset_completed` |
| `App\Exceptions\DomainApiException` | factories for `reset_link_invalid` (422), `founder_protected` (403), `staff_already_disabled` (409), `staff_not_disabled` (409) |

## 3. Cache challenge (customer reset code)

Key `password_reset:<hmac(phone)>`, encrypted, TTL 1 h. Fields:

| Field | Meaning |
|---|---|
| `customer_id` | the account the code was sent for |
| `code_hash` | `Hash::make(code)` |
| `code_expires_at` | now + `otp.ttl_seconds` |
| `verify_attempts` | wrong tries; the challenge is forgotten at `otp.max_verify_attempts` |
| `sends`, `last_sent_at` | resend accounting (`otp.send_cooldown_seconds`, `otp.max_sends_per_hour`) |
| `challenge_expires_at` | hard end (1 h) |

A new request for the same phone replaces the code and resets `verify_attempts`; a spent code deletes the entry first (as `VerifyCustomerLoginOtpAction`).

## 4. Config (`config/dahab-auth.php`, `.env.example`)

```php
'password_reset' => [
    'staff_link' => [
        'recovery_minutes'  => (int) env('DAHAB_STAFF_RECOVERY_LINK_MINUTES', 30),
        'invitation_hours'  => (int) env('DAHAB_STAFF_INVITATION_LINK_HOURS', 48),
        'url' => rtrim((string) env('DASHBOARD_URL', 'http://localhost:3000'), '/').'/set-password',
    ],
],
'rate_limits' => [
    'password_reset_request' => [... existing ..., 'per_device_max' => 10, 'per_device_window' => 3600],
    'password_reset_confirm' => ['per_identity_max' => 10, 'per_identity_window' => 3600, 'per_ip_max' => 30, 'per_ip_window' => 3600],
    'staff_password_reset_confirm' => ['per_ip_max' => 20, 'per_ip_window' => 3600],
],
```

New env key: `DASHBOARD_URL` (documented in `.env.example`).

## 5. Entities and state

```
Customer reset (no new state)
  request ─► [cache: code, 5 min, 5 tries] ─► confirm ─► customer_password updated
                                                          ├─ tokens + trusted devices removed
                                                          ├─ withdrawals unreleased → cancelled, withdrawal_pause(password_reset)
                                                          └─ audit + AccountNotification(PASSWORD_RESET)

Staff link
  issue (create | recovery | enable) ─► one_time_token(open) ─► confirm ─► consumed; staff_password upserted; tokens deleted
                                         └─ older open rows for the member consumed on every issue

Staff account
  active ──disable──► disabled (is_active=false, tokens deleted, links consumed)
  disabled ──enable──► active, password row deleted, 'enabled' link issued (no password until used)
  invited = active + no staff_password row
```

Rules: founders (`is_founder`) and the system actor are never disabled; a member never disables, enables or creates themselves; the last active `roles.manage` holder cannot be disabled (409 `last_role_manager`, from the existing lock post-check).

## 6. Response shapes touched

- `DashboardStaffMember` gains optional `password_set: boolean` (C6).
- `GET /dashboard/staff` gains optional `status=active|disabled`.
- Customer file `withdrawal_pause.trigger_kind` gains `password_reset`.
