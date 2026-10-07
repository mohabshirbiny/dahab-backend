# Contract — spec 017 (planning artefact; the code and `#[OA]` win)

Envelope, errors, keyset paging and `Idempotency-Key` (every POST marked **IK**) as `docs/platform/api-contract.md`. Customer routes: `auth:customer` + `customer:access`; no verified gate unless stated. Error codes: research R11.

## Customer — contact changes and password

| Method & path | Body | 200/201 | Notes |
|---|---|---|---|
| `POST /customer/me/phone-change` **IK** | `{ phone }` | `{ challenge_id, expires_at, phone_masked }` | Code to the new number. `contact_taken`, `same_contact`; limiter 3/h |
| `POST /customer/me/phone-change/{challenge}/confirm` **IK** | `{ code }` | `{ customer (me), pause_until, cancelled_withdrawals[] }` | R2/R3/R4; `change_code_invalid` (+`tries_left`), `change_code_locked`, `contact_taken` |
| `POST /customer/me/email-change` **IK** | `{ email }` | `{ expires_at, email_masked }` | Link to the new address; `contact_taken`, `same_contact` |
| `GET /contact-changes/email/read?token=` | — | `{ email_masked, expires_at }` | Public; `change_link_invalid` 410 |
| `POST /contact-changes/email/confirm` **IK** | `{ token }` | `{ email_masked, pause_until }` | Public, single use (R2/R3) |
| `POST /customer/me/password` **IK** | `{ current_password, password, password_confirmation }` | `{ signed_out_sessions }` | spec 001 rules; `current_password_wrong` |

## Customer — sessions

| Method & path | Body | 200/201 | Notes |
|---|---|---|---|
| `GET /customer/me/sessions` | — | `{ data: [{ session_id, platform, user_agent, started_at, last_active_at, is_current, device_known }] }` | R4 |
| `POST /customer/me/sessions/{session}/sign-out` **IK** | — | `{ signed_out_sessions, device_forgotten }` | `current_session` 422; 404 if not own |

## Customer — inbox

| Method & path | Body | 200/201 | Notes |
|---|---|---|---|
| `GET /customer/me/notifications?cursor=&unread=` | — | `{ data: [{ id, type, params, link: { kind, id }, title, body, title_en, title_ar, body_en, body_ar, created_at, read_at }], meta: { next_cursor, unread_count } }` | `title`/`body` in the request language |
| `GET /customer/me/notifications/unread-count` | — | `{ unread_count }` | For the bell |
| `POST /customer/me/notifications/{id}/read` **IK** | — | item | Idempotent |
| `POST /customer/me/notifications/read-all` **IK** | — | `{ marked }` | |

## Customer — saved pieces

| Method & path | Body | 200/201 | Notes |
|---|---|---|---|
| `GET /customer/me/saved-pieces?listing_id=` | — | `{ data: [{ listing_id, saved_at, available, listing (market shape) | null, summary }] }` | ≤ `saved.max_per_customer` rows; `listing` only when live/reserved |
| `POST /customer/me/saved-pieces` **IK** | `{ listing_id }` | item | `listing_not_saveable`, `saved_limit_reached` (setting `saved.max_per_customer`); idempotent |
| `DELETE /customer/me/saved-pieces/{listing}` | — | 204 | Idempotent |

## Customer — close and report

| Method & path | Body | 200/201 | Notes |
|---|---|---|---|
| `GET /customer/me/account/close-check` | — | `{ can_close, blockers: [{ code, count }] }` | Drives *You have things in progress* |
| `POST /customer/me/account/close` **IK** | `{ reason, note? }` | `{ closed_at }` | `account_has_open_items` (details.blockers) |
| `POST /customer/me/listing-reports` **IK** | `{ listing_id, reason, note? }` | `{ reference: "RPT-n", created_at }` | Verified gate + not suspended; `listing_not_reportable`, `report_already_open`; 10/day |

## Public reference

| Method & path | Body | 200/201 | Notes |
|---|---|---|---|
| `GET /reference/legal-documents` | — | `{ data: [{ code, published: bool, version, published_at }] }` | Four codes (R7) |
| `GET /reference/support-contacts` | — | `{ phone, hours_en, hours_ar, whatsapp, email, social: { facebook, instagram, tiktok } }` | From config |

## Dashboard

| Method & path | Permission | Notes |
|---|---|---|
| `GET /dashboard/listing-reports?state=&reason=&cursor=` | `listing_report.handle` | Rows: reference, reason, listing (ref, title, state), reporter `display_ref`, age, state; `meta.counts` by state |
| `GET /dashboard/listing-reports/{report}` | `listing_report.handle` | + note, other open reports on the piece, history |
| `POST /dashboard/listing-reports/{report}/dismiss` **IK** | `listing_report.handle` | `{ note }`; `report_not_open` |
| `POST /dashboard/listing-reports/{report}/take-down` **IK** | `listing_report.handle` + `listing.takedown` | `{ reason }`; spec 010 take-down; all open reports on the piece → `actioned` |
| `GET /dashboard/customers/{customer}/notifications?cursor=` | `customer.view` | Read-only inbox items |

## Changed responses

- Customer `status`: + `closed` (potentially breaking — consumers updated, R13).
- Sign-in / new-device OTP: `403 account_closed`.
- `withdrawal_pause` exposures (`pause_until`) unchanged; the Customer file resource gains `pause.trigger_kind`.
