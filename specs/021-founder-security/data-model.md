# Data model: Founder-account security (spec 021)

## 1. Schema changes: one migration, `database/migrations/2026_10_14_000010_founder_security.php`

The two founder tables exist from `2026_09_19_000010` (mirrored in `02_schema_identity.sql`) without a reason, refusals, an expiry or confirmation times. The changes are mirrored as a new "spec 021" section at the end of `docs/Database schema/05_schema_security.sql`, following the spec 020 convention, plus a pointer comment beside the two tables in `02_schema_identity.sql` and the copy in `00_schema_full.sql`.

### 1.1 `founder_device_approval`: new columns

| Column | Type | Rule |
|---|---|---|
| `expires_at` | `TIMESTAMPTZ NOT NULL` | backfill `requested_at + interval '15 minutes'`; set by the action from `dahab-auth.founder_device_approval.ttl_minutes` (15, D21-4) |
| `ref_hash` | `TEXT UNIQUE` | HMAC-SHA256 of the poll ref (`app.key`-derived, R4); `NULL` only for rows from before spec 021 |
| `platform` | `TEXT` | `X-Device-Platform` as sent (`web`), for the approver |
| `user_agent` | `TEXT` | truncated to 255, for the approver |
| `ip_address` | `INET` | the request IP |
| `refused_by` | `UUID REFERENCES staff(staff_id)` | |
| `refused_at` | `TIMESTAMPTZ` | |
| `decision_note` | `TEXT` | optional note on approve or refuse (≤ 500) |
| `consumed_at` | `TIMESTAMPTZ` | the moment the held device collected its session (single use) |

New CHECKs (the existing one, `approver_is_not_self`, stays):

```sql
CONSTRAINT refuser_is_not_self   CHECK (refused_by IS NULL OR refused_by <> staff_id),
CONSTRAINT approved_pair         CHECK ((approved_by IS NULL) = (approved_at IS NULL)),
CONSTRAINT refused_pair          CHECK ((refused_by IS NULL) = (refused_at IS NULL)),
CONSTRAINT one_decision          CHECK (approved_at IS NULL OR refused_at IS NULL),
CONSTRAINT consumed_after_approval CHECK (consumed_at IS NULL OR approved_at IS NOT NULL)
```

Indexes:

- `idx_fda_staff_pending ON founder_device_approval(staff_id, device_fingerprint) WHERE approved_at IS NULL AND refused_at IS NULL`
- `idx_fda_requested ON founder_device_approval(requested_at DESC)` (history list)

Trigger **DH021** `founder_device_approval_guard` (BEFORE UPDATE OR DELETE), per research R15: no delete; identity columns are frozen; a decision is set once, on an undecided, unexpired row; `expires_at` may only move earlier; `consumed_at` is set once, on an approved row.

**Status is derived, never stored**:

| Status | Condition |
|---|---|
| `approved` | `approved_at` set (and `consumed` when `consumed_at` is set too) |
| `refused` | `refused_at` set |
| `expired` | undecided and `now() ≥ expires_at` |
| `pending` | otherwise |

### 1.2 `account_freeze`: new columns

| Column | Type | Rule |
|---|---|---|
| `reason` | `TEXT NOT NULL` | backfill `'(recorded before spec 021)'` for existing rows, then `NOT NULL` |
| `unfreeze_confirm_1_at` | `TIMESTAMPTZ` | |
| `unfreeze_confirm_1_kind` | `TEXT CHECK (unfreeze_confirm_1_kind IN ('frozen_founder','second_approver'))` | |
| `unfreeze_confirm_2_at` | `TIMESTAMPTZ` | |

New CHECKs (`no_self_freeze` stays):

```sql
CONSTRAINT confirm_1_complete   CHECK ((unfreeze_confirm_1 IS NULL) = (unfreeze_confirm_1_at IS NULL)
                                   AND (unfreeze_confirm_1 IS NULL) = (unfreeze_confirm_1_kind IS NULL)),
CONSTRAINT confirm_2_complete   CHECK ((unfreeze_confirm_2 IS NULL) = (unfreeze_confirm_2_at IS NULL)),
CONSTRAINT confirm_2_after_1    CHECK (unfreeze_confirm_2 IS NULL OR unfreeze_confirm_1 IS NOT NULL),
CONSTRAINT confirmers_differ    CHECK (unfreeze_confirm_2 IS NULL OR unfreeze_confirm_2 <> unfreeze_confirm_1),
CONSTRAINT confirm_2_not_frozen CHECK (unfreeze_confirm_2 IS NULL OR unfreeze_confirm_2 <> frozen_staff_id),
CONSTRAINT confirm_1_kind_match CHECK (unfreeze_confirm_1_kind IS DISTINCT FROM 'frozen_founder'
                                       OR unfreeze_confirm_1 = frozen_staff_id),
CONSTRAINT second_approver_not_frozen CHECK (unfreeze_confirm_1_kind IS DISTINCT FROM 'second_approver'
                                       OR unfreeze_confirm_1 <> frozen_staff_id),
CONSTRAINT unfrozen_iff_confirm_2 CHECK ((unfrozen_at IS NULL) = (unfreeze_confirm_2 IS NULL)),
CONSTRAINT reason_present        CHECK (length(btrim(reason)) > 0)
```

Indexes:

- `uq_account_freeze_open ON account_freeze(frozen_staff_id) WHERE unfrozen_at IS NULL` (UNIQUE: one open freeze per staff member)
- `idx_account_freeze_frozen_at ON account_freeze(frozen_at DESC)`

Trigger **DH022** `account_freeze_guard` (BEFORE INSERT OR UPDATE OR DELETE), per R15:

- no delete;
- on INSERT, `frozen_by` is a founder (`staff.is_founder`);
- `frozen_staff_id`, `frozen_by`, `frozen_at`, `reason` never change;
- each confirmation trio is set once;
- `unfreeze_confirm_2` must be a founder;
- a row with `unfrozen_at` never changes.

The target is **not** required to be a founder at the database level (keeps `StaffFactory::frozen()` valid); the freeze action enforces "the other founder".

### 1.3 `staff_notice`: new table (Dashboard notices, reusable by spec 024)

```sql
CREATE TABLE staff_notice (
  notice_id       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  staff_id        UUID NOT NULL REFERENCES staff(staff_id),
  kind            TEXT NOT NULL,                 -- App\Enums\StaffNoticeKind, e.g. 'founder_alert'
  severity        TEXT NOT NULL DEFAULT 'info' CHECK (severity IN ('info','warning','critical')),
  title           TEXT NOT NULL,                 -- English, staff-facing
  body            TEXT NOT NULL,
  params          JSONB NOT NULL DEFAULT '{}'::jsonb,   -- event code, entity, actor name; never a secret
  link            TEXT,                          -- Dashboard path, e.g. /security or /audit?entry=123
  audit_id        BIGINT REFERENCES audit_log(audit_id),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  read_at         TIMESTAMPTZ
);
CREATE INDEX idx_staff_notice_inbox ON staff_notice(staff_id, created_at DESC);
CREATE INDEX idx_staff_notice_unread ON staff_notice(staff_id) WHERE read_at IS NULL;
```

The table has no RLS: staff tables never have it (spec 003 covers customer tables only), and customer requests never touch it. Its only update is `read_at`, so there's no guard trigger and no rows are deleted.

### 1.4 Permission `founder.unfreeze_second`

The migration inserts the code (guard `staff`) and, per research R14 option 1, gives it to the `ceo` role if that role exists (plan Q1, confirmed). `down()` detaches the code and deletes it.

### 1.5 `down()`

`down()` refuses with `RuntimeException('Spec 021 data exists: this migration cannot be rolled back.')` when any `founder_device_approval` has `ref_hash`, any `account_freeze` has a confirmation, or `staff_notice` has rows. Otherwise it drops the triggers, indexes, CHECKs, columns, the table and the permission. This follows the spec 017/020 pattern.

## 2. Models

| Model | Change |
|---|---|
| `FounderDeviceApproval` | new fillable columns and casts; `status()` (derived, §1.1); relations `staff`, `approver`, `refuser`; scopes `pending()`, `forApprover(Staff)` (other founders' requests) |
| `AccountFreeze` | `reason`, the confirmation columns and casts; relations `frozenStaff`, `frozenBy`, `confirmer1`, `confirmer2`; `stage()` = `awaiting_first`, `awaiting_founder` or `unfrozen` |
| `StaffNotice` (new) | `HasUuids`, `$timestamps = false`, casts `params` array, `read_at` datetime; scope `unread()` |
| `Staff` | `notices()` hasMany; `deviceFingerprints()` hasMany `StaffDeviceFingerprint`; `isKnownDevice(?string $hash): bool` |
| `StaffDeviceFingerprint` | unchanged |

**Factories:**

- `FounderDeviceApprovalFactory` with states `pending`, `approved(by)`, `refused(by)`, `expired`, `consumed`;
- `AccountFreezeFactory` with states `firstConfirmed(kind)`, `unfrozen`;
- `StaffFactory::withKnownDevice(string $deviceId, string $platform = 'web')`;
- `StaffNoticeFactory`.

`StaffFactory::frozen()` gains a `reason` (required by the new `NOT NULL`).

## 3. Enums and constants

| Where | Change |
|---|---|
| `App\Enums\StaffPermission` | `FOUNDER_UNFREEZE_SECOND = 'founder.unfreeze_second'` (label, group *Access control*, seed roles `[ceo]` (Q1)) |
| `App\Enums\AuditEvent` | `founder.device_approval_requested`, `founder.device_approved`, `founder.device_refused`, `founder.device_approval_collected`, `founder.device_trusted_by_console`, `founder.frozen`, `founder.unfreeze_confirmed`, `founder.unfrozen`, `founder.unfreeze_confirm_failed` (narrow path failures); category **Accounts** (the existing `AuditCategory::ACCOUNTS`, where `authz.staff.*` live); `founderAlert(): list<self>` (research R11) |
| `App\Enums\AuthErrorCode` | `device_id_required`, `device_approval_refused`, `device_approval_invalid` |
| `App\Exceptions\DomainApiException` | `deviceApprovalNotPending`, `founderOnly`, `founderSelfAction`, `freezeTargetInvalid`, `alreadyFrozen`, `notFrozen`, `unfreezeOrder`, `unfreezeAlreadyConfirmed`, `deviceNotKnown` |
| `App\Enums\StaffNoticeKind` (new) | `FOUNDER_ALERT = 'founder_alert'` (spec 024 adds its own) |
| `config/dahab-auth.php` | `founder_device_approval_enforced` (forced on in production), `founder_device_approval.ttl_minutes` (15), `founder_device_approval.poll_interval_seconds` (5) |
| limiters (`AppServiceProvider`) | `auth.staff.device_approval` (30 / min per `approval_ref`, 60 / min per IP); `frozen/confirm-unfreeze` reuses `auth.staff.login` + `auth.staff.mfa` buckets |

The `sign_in` audit payload's `via` gains the value `device_approval`.

## 4. State

```
founder_device_approval:   pending ──approve──▶ approved ──collect──▶ consumed
                              │  └──refuse──▶ refused
                              └──(15 min, superseded, freeze)──▶ expired

account_freeze:   open/awaiting_first ──confirm 1 (frozen founder | second approver)──▶ awaiting_founder
                                                    ──confirm 2 (a founder not frozen)──▶ unfrozen (final)
```
