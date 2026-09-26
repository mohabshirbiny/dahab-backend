# Error Codes added by spec 002

Extends [`specs/001-auth-customer-staff/contracts/error-codes.md`](../../001-auth-customer-staff/contracts/error-codes.md). Envelope unchanged: `{ "message", "code", "errors"? }`.

| Code | HTTP | Surface | When |
|---|---|---|---|
| `verification_required` | 403 | Customer | A customer whose status is `pending_verification` or `rejected` calls a route gated `customer.gate:verified` or `customer.gate:trade` |
| `account_suspended` | 403 | Customer | *(existing code, new trigger)* A `suspended` customer calls a route gated `customer.gate:trade` |
| `escalation_denied` | 403 | Dashboard | A role manager adds/removes a permission they do not hold, edits or deletes a role they hold, changes their own roles, or assigns/removes a role containing a permission they do not hold |
| `wrong_branch` | 403 | Dashboard | A branch-scoped permission is used on a record of another branch, or the staff member has no branch (Part 2 §6 code) |
| `last_role_manager` | 409 | Dashboard | The change would leave no active, non-system staff member holding `roles.manage` |
| `role_in_use` | 409 | Dashboard | Deleting a role that one or more staff members still hold. `message` states the count |
| `reason_required` | 422 | Dashboard | `reason` missing on a change that requires it (Part 1 §6 code) |

Unchanged but relevant:
- `permission_denied` (403): the actor lacks the endpoint's permission (e.g. no `roles.manage`). Still audited.
- `not_found` (404): unknown role name, unknown staff id, or the system actor's id.
- `validation_failed` (422): unknown permission code, duplicate or badly formed role name, unknown role name in an assignment.
