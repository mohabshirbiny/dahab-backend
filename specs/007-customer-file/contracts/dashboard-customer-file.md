# Contract: Customer File (Dashboard + idempotency)

All endpoints are under `/api/v1`. Dashboard routes use `auth:staff`, `abilities:staff:access` and `staff.standing`. The envelope and errors follow `docs/platform/api-contract.md`: success is `{ data, meta? }`, errors are `{ message, code, errors? }`. Times are ISO-8601 with the Cairo offset. The code, through `#[OA]`, wins over this file.

## 1. `GET /dashboard/customers/{customer}` — the file (extended, non-breaking)

- **Permission**: `customer.view`.
- **Audited**: yes, one `auth.customer.verification_details_viewed` entry per call (unchanged).
- **200 `data`**: the existing `StaffCustomerVerification` fields, plus:

```json
{
  "preferred_lang": "ar",
  "joined_at": "2026-05-12T10:04:00+03:00",
  "documents": [
    { "document_id": "uuid", "doc_kind": "egyptian_id", "status": "verified", "has_back": true,
      "review_reasons": null, "review_note": null,
      "created_at": "…", "reviewed_at": "…", "reviewed_by": { "id": "uuid", "full_name": "Sara Adel" } }
  ],
  "suspension": null
}
```

When the customer is suspended:

```json
"suspension": {
  "reason": "off_platform_dealing",
  "note": "Asked a buyer to pay cash.",
  "status_before": "active",
  "suspended_at": "…",
  "suspended_by": { "id": "uuid", "full_name": "Ahmed Ezz El-Din" }
}
```

Notes:
- `latest_document` is kept, and equals `documents[0]`.
- `suspended_reason` is kept, and equals `suspension.reason`.
- `reviewed_by` is `null` when the reviewer is not known.
- **404** `not_found`: an unknown customer.

## 2. `GET /dashboard/customers` — adds `q` (non-breaking)

- **`q`** (optional string, 1–32 characters): an exact match on `display_ref` or on the normalised phone. When `q` is present, `status` is ignored and every state is searched.
- Everything else is unchanged.

## 3. `POST /dashboard/customers/{customer}/suspend`

- **Permission**: `customer.suspend`.
- **Headers**: `Idempotency-Key: <uuid>`, required.
- **Audited**: `auth.customer.suspended`.
- **Body**:

```json
{ "reason": "off_platform_dealing", "note": "Asked a buyer to pay cash." }
```

- **Validation**:
  - `reason`: required, one of `piece_misrepresented`, `off_platform_dealing`, `repeated_disputes`, `reported_by_users`, `identity_unconfirmed`, `customer_request`, `other`;
  - `note`: required string, 1–1000 characters, trimmed.
- **200**: `data` = the file (§1 shape) after the change.
- **Errors**:
  - 422 `validation_failed`;
  - 409 `customer_already_suspended`;
  - 403 `permission_denied`;
  - 404 `not_found`;
  - the idempotency errors in §6.

## 4. `POST /dashboard/customers/{customer}/reinstate`

- **Permission**: `customer.suspend`.
- **Headers**: `Idempotency-Key`, required.
- **Audited**: `auth.customer.unsuspended`.
- **Body**:

```json
{ "note": "Cleared after review." }
```

- **200**: `data` = the file, with `status` restored to `suspension.status_before` and `suspension: null`.
- **Errors**:
  - 422 `validation_failed`;
  - 409 `customer_not_suspended`;
  - 403;
  - 404;
  - the idempotency errors in §6.

## 5. Activity

### `GET /dashboard/customers/{customer}/activity?cursor=&per_page=` (default 20, max 50)

- **Permission**: `customer.view` **and** (`audit.view_all` or `audit.view_own`); otherwise 403 `permission_denied`.
- **Visibility**: `audit.view_own` holders get only entries they acted in.
- **Excluded**: `auth.token.rotated`.
- **200**:

```json
{ "data": [ /* AuditEntry list shape from spec 006: id, at, actor, action, label, category, subject, before_summary, after_summary, ip */ ],
  "meta": { "next_cursor": "opaque|null" } }
```

### `GET /dashboard/customers/{customer}/sessions?page=&per_page=` (default 20, max 50)

- **Permission**: `customer.view`.
- **200**:

```json
{ "data": {
    "devices": [ { "device_ref": "3fa9c2e1b07d", "first_seen_at": "…", "last_seen_at": "…" } ],
    "sessions": [ { "session_id": "uuid", "started_at": "…", "last_active_at": "…", "expires_at": "…" } ]
  },
  "meta": { "current_page": 1, "per_page": 20, "total": 3, "last_page": 1 } }
```

- `meta` applies to `sessions`. `devices` is the full list, newest `last_seen_at` first.
- `sessions` holds **open** sessions only (a token family with an unexpired token), newest activity first. Signing out deletes a session, so ended ones are read from History.
- Token values, abilities and full fingerprints are never returned.

## 6. Idempotency (shared convention — add to `docs/platform/api-contract.md`)

It applies to routes marked `idempotent: required`. In this feature those are §3 and §4.

| Situation | Result |
|---|---|
| Header missing or not a UUID | `400 idempotency_key_required` |
| First use | The request runs. A response < 500 is stored for 24 h. |
| Same key, same request, completed | The stored status and body are replayed, with header `Idempotent-Replayed: true`. Nothing runs. |
| Same key, different body or target | `422 idempotency_key_mismatch` |
| Same key, still running (< 60 s) | `409 idempotency_in_progress` |
| Earlier attempt failed with 5xx | The request runs again. |

Keys are scoped per actor and per endpoint. Using the same UUID on another endpoint, or as another actor, is a different key.

## Consumers

- **Dashboard**:
  - `src/api/endpoints.ts` (+ suspend, reinstate, activity, sessions);
  - `src/types/customer.ts` (file, suspension, reasons);
  - `src/services/customer.service.ts`;
  - `src/composables/useCustomers.ts` / the new `useCustomerFile.ts`;
  - `pages/customers/[id].vue`, `pages/customer/index.vue`;
  - `CustomerReviewPanel` (keeps using `latest_document`).
- **Customer App**: none of these endpoints. It reads `status` and `suspended_reason` from the existing `/customer/auth/me`. The reason codes change (§3) → `lib/models/customer.dart` and the new notice.
