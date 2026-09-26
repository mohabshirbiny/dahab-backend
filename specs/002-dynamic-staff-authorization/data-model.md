# Data Model: Dynamic Staff Authorization, Customer Verified Gate, System Actor

**Feature**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

PostgreSQL 16. Spatie `laravel-permission` tables use guard `staff` only. "Existing" means already migrated by spec 001.

---

## 1. `roles` (existing Spatie table — altered)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGSERIAL PK | no | | existing |
| `name` | VARCHAR(255) | no | | existing. **Machine name**, immutable after create. CHECK `name ~ '^[a-z][a-z0-9_]{2,49}$'` |
| `guard_name` | VARCHAR(255) | no | | existing. Always `staff` (CHECK `guard_name = 'staff'`) |
| `display_name` | VARCHAR(100) | no | | **new**. Backfill = `initcap(replace(name,'_',' '))` |
| `description` | TEXT | yes | NULL | **new**, ≤ 500 chars (app validation) |
| `requires_mfa` | BOOLEAN | no | false | **new**. Backfill `true` for `ceo`, `coo`, `finance` |
| `created_at`, `updated_at` | TIMESTAMP | yes | | existing |

Unique `(name, guard_name)` stays.

**Model**: `App\Models\StaffRoleModel extends Spatie\Permission\Models\Role`. Registered as `config('permission.models.role')`. Fillable: `name` (create only), `display_name`, `description`, `requires_mfa`. Cast `requires_mfa` to boolean. Scope `withHolderCount()` counts non-system staff holding the role.

**Rules**:
- `name` cannot change after creation (the update FormRequest does not accept it; the model `updating` event throws if dirty).
- Delete is allowed only when the non-system holder count is 0 → otherwise `409 role_in_use`.

## 2. `permissions` (existing Spatie table — unchanged schema)

Rows mirror `App\Enums\StaffPermission` cases, kept in sync by `SyncPermissionCatalogueAction` (research R2). Never edited through the API.

### Permission catalogue (code-defined)

| Code | Label | Group | Branch-scoped | Seed roles (besides `ceo`) |
|---|---|---|---|---|
| `customer.view` | View customers and verification details | Customers | no | `verification` |
| `customer.suspend` | Suspend or reinstate a customer | Customers | no | `coo` |
| `identity.view` | Open identity documents | Identity | no | `verification` |
| `identity.review` | Approve or reject identity documents | Identity | no | `verification` |
| `staff.view` | View staff members and their roles | Access control | no | `coo` |
| `roles.manage` | Manage roles, their permissions, and staff role assignment | Access control | no | `coo` |

`ceo` receives every code (FR-070). Later modules append rows here from the Part 1 §4 matrix.

## 3. `role_has_permissions`, `model_has_roles` (existing Spatie pivots — unchanged)

- `model_has_roles.model_type = 'App\Models\Staff'`, `model_id = staff.staff_id` (UUID, existing customisation).
- `model_has_permissions` is **not used**. Staff get permissions only through roles (research R6). A seeder or migration never writes it.

## 4. `staff` (existing — altered)

| Column | Change | Type | Null | Default | Notes |
|---|---|---|---|---|---|
| `role` | **dropped** (with type `staff_role` and CHECK `igi_has_branch`) | | | | Backfilled into `model_has_roles` first |
| `branch_id` | unchanged | SMALLINT | yes | NULL | Now optional for everyone. FK to `branch` comes with reference data |
| `is_founder` | **new** | BOOLEAN | no | false | Backfill `true` where old `role IN ('ceo','coo')`. **Not fillable**, no endpoint writes it (FR-043) |
| `is_system` | **new** | BOOLEAN | no | false | `true` only for the system actor |

New constraints:
- `CHECK (NOT (is_system AND is_founder))` — `staff_system_not_founder`
- `CREATE UNIQUE INDEX one_system_staff ON staff ((true)) WHERE is_system` — at most one system actor

**Model** `App\Models\Staff`:
- `role` removed from `$fillable` and casts. `is_founder` and `is_system` cast to boolean and **not** fillable.
- Scope `manageable()`: `where('is_system', false)`.
- `requiresMfa(): bool` = `is_founder || roles()->where('requires_mfa', true)->exists()`.

**Factory** `StaffFactory`:
- `role` state is replaced by `withRole(string ...$names)` (findOrCreate role, assign).
- New states: `founder()`, `system()`.
- `definition()` has no role. Tests that need permissions call `withRole(...)` after seeding the catalogue.

**Seed data**:
- The system actor row is inserted by migration (research R9): `full_name 'System'`, `email 'system@dahab.internal'`, `is_system true`, `is_active true`, no password, no roles.
- `LocalStaffSeeder` (local/testing only): one account per seed role, `<role>@dahab.test`. `ceo@` and `coo@` get `is_founder = true` via a direct query (not mass assignment). `igi_branch@` keeps `branch_id = 1`.

## 5. `audit_log` (existing — unchanged schema)

New `action` values (`App\Enums\AuditEvent`):

| Action | entity_type / entity_id | before_json / after_json | reason |
|---|---|---|---|
| `authz.role.created` | `role` / role id | – / `{name, display_name, description, requires_mfa, permissions[]}` | optional |
| `authz.role.updated` | `role` / role id | changed fields only | optional |
| `authz.role.permissions_changed` | `role` / role id | `{permissions[]}` / `{permissions[], added[], removed[]}` | **required** |
| `authz.role.mfa_changed` | `role` / role id | `{requires_mfa}` / `{requires_mfa}` | **required** |
| `authz.role.deleted` | `role` / role id | full role snapshot / – | **required** |
| `authz.staff.roles_changed` | `staff` / staff id | `{roles[]}` / `{roles[], added[], removed[]}` | **required** |
| `authz.escalation_denied` | `role` or `staff` / id | – / `{attempt, offending_permissions[]}` | – |

One PATCH that changes several aspects writes one row per aspect, so each row keeps a single meaning.

## 6. `customer` (existing — read only)

The gate reads `customer.status` (`App\Enums\CustomerStatus`): `pending_verification`, `active`, `rejected`, `suspended`.

| Gate level | `active` | `suspended` | `pending_verification` / `rejected` |
|---|---|---|---|
| open (allow-list) | ✓ | ✓ | ✓ |
| `verified` | ✓ | ✓ | 403 `verification_required` |
| `trade` | ✓ | 403 `account_suspended` | 403 `verification_required` |

## 7. State and invariants summary

| Invariant | Enforced by |
|---|---|
| ≥ 1 active non-system staff holds `roles.manage` after any authz change | Action, after-apply count under advisory lock → `409 last_role_manager` |
| Role in use cannot be deleted | Action → `409 role_in_use` |
| No self-escalation | `RoleEscalationGuard` → `403 escalation_denied` |
| Founder status never changes via API | not fillable, no endpoint, test asserts |
| Exactly one system actor | migration insert + partial unique index |
| System actor never signs in / never managed | no password row + explicit check; `manageable()` scope |
| Role machine name immutable | FormRequest excludes it + model `updating` guard |
| Seed never overwrites Dashboard edits | `SyncPermissionCatalogueAction` additive-only on existing roles |
