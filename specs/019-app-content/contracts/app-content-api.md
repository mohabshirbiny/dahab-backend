# API contract — spec 019 (planning artefact; the code and generated OpenAPI win on disagreement)

Envelope, errors, `Idempotency-Key` and pagination as in `docs/platform/api-contract.md`. Staff routes: `auth:staff`, `staff.standing`, permission middleware, `idempotent` on writes, audited. Each block names the phase that ships it; error codes and permissions appear in `api-contract.md` only when their phase ships.

## Public

| Phase | Method · Path | Notes |
|---|---|---|
| 1 | `GET /reference/app-texts` | `{ version, published_at, texts: { key: { en, ar } }, faq: [ { key, position, question: {en, ar}, answer: {en, ar} } ], settings: { name: value } }`. Published overrides of kinds `app` and `faq` only; default markers and `notification` keys never served. `ETag: "<bundle_version>-<sha1(setting values)>"` — changes on a publish **or** a setting change; `If-None-Match` → `304`. `throttle:public.market`. |
| 2 | `GET /reference/legal-documents` | `data[]` **unchanged** (`terms, privacy, selling_rules, id_handling`); adds `declarations[]` `{ code, published, version?, published_at? }` for `ownership_declaration, deposit_agreement, collection_proxy_authorisation, payout_account_declaration`. Non-breaking. |
| — | `GET /reference/legal-documents/{code}` | unchanged. |
| 6 | `GET /reference/controls` | `{ stop_everything: { active, message: {en, ar} }, categories: [ { category, level, message: {en, ar} } ], visibility: { rapaport_reference, payout_averages, listing_stats, sharing } }`. |

## Customer

| Phase | Method · Path | Notes |
|---|---|---|
| 2 | `GET /customer/me` + sign-in / OTP / refresh success payloads | adds `legal_acceptance_required: [ { code, version, legal_doc_id, reason: first|material } ]` (empty when none). Optional field. |
| 2 | `POST /customer/me/legal-acceptances` | `{ legal_doc_id }` → `201`. `422 legal_document_not_current`, `409 legal_acceptance_not_required`. Idempotent. Allowed for suspended customers. |
| 2 | every state-changing customer route | `409 legal_acceptance_required` while a requirement exists; passes: the acceptance POST, `logout`, `logout-all`, every GET. |
| 2 | registration final step | optional `terms_legal_doc_id`, `privacy_legal_doc_id`; recorded as `signup`; `422 legal_document_not_current` if not live. Non-breaking. |
| 6 | `POST /customer/me/listings`, `…/{id}/submit` | `409 category_stopped` / `platform_stopped` with `message {en, ar}`. |
| 6 | `POST /customer/me/buy-requests` | `409 platform_stopped`; a hidden (paused, `live`) piece → `404` as for any piece not on the market. Joining the line of a requested piece in a paused category is allowed. |
| 6 | `POST /customer/me/orders/{order}/extension-requests` | `409 category_paused`. |
| 6 | `POST /customer/me/withdrawals` | `409 platform_stopped`. Cancelling a pending withdrawal stays allowed. |
| 6 | `GET /market/listings` (public), piece, saved pieces, quote | pieces in state `live` of a paused category are absent. |
| **6B [SIGN-OFF]** | `GET /customer/me/market/listings` | market-maker customers with an active code: items with `mm_approved`, filter `mm_approved=1`. **Not built before Finance signs off D3.** |
| **6B [SIGN-OFF]** | `POST /customer/me/buy-requests` | optional `promo_code`; `422 promo_code_invalid { reason: inactive|not_your_code|not_approved|too_new|cap_reached }`. **Not built before the sign-off.** |

## Dashboard — content (`content.edit`, Phase 1; templates Phase 3; FAQ Phase 4)

| Method · Path | Notes |
|---|---|
| `GET /dashboard/app-texts?kind=&area=&q=&changed=&state=` | registry rows with default, published, draft, version, published_at, publisher, `retired`, `read_only`, `default_changed_since_override`. Cursor pagination. |
| `PUT /dashboard/app-texts/{key}/draft` | `{ text_en, text_ar, draft_version?, is_hidden? }` → `200` draft. `409 content_draft_changed`, `422 content_invalid_placeholder` · `content_missing_required_placeholder` · `content_language_missing` · `content_too_long`, `409 content_key_read_only` (D7). |
| `DELETE /dashboard/app-texts/{key}/draft` | discards the draft. |
| `POST /dashboard/app-texts/{key}/revert` | `{ to: "default" }` → a default-marker draft (ends the override when published); `{ to: <version> }` → a draft copying that version. |
| `POST /dashboard/app-texts/publish` | publishes every pending draft (Q2) → `{ bundle_version, published_at, keys[] }`. `422 content_nothing_to_publish`; `422 content_invalid` with per-key errors (incl. `content_unchanged`). |
| `GET /dashboard/app-texts/{key}/history` | versions with editor/publisher/dates. |
| `GET /dashboard/app-texts/export` | CSV: key, kind, area, en, ar, version, published_at. |
| `POST /dashboard/faq` · `PATCH /dashboard/faq/{slug}/position` | create an FAQ pair (drafts) · reorder (draft). |
| `GET /dashboard/content/placeholders` | setting placeholders with current values. |

## Dashboard — legal (Phase 2; `legal.publish`; reads also `content.edit`)

| Method · Path | Notes |
|---|---|
| `GET /dashboard/legal-documents` | per code: `kind`, live `version`, `published_at`, `published_by`, `is_material`, `accepted_live_count`. |
| `GET /dashboard/legal-documents/{code}/versions` | history; `?with_body=1` for bodies. |
| `POST /dashboard/legal-documents/{code}/versions` | `{ body_en, body_ar, is_material }` → `201 { legal_doc_id, version, published_at }`. `422 legal_body_required`, `404 legal_code_unknown`, `409 legal_version_conflict` on a lost race. `legal.publish` only (CEO, D1). |

## Dashboard — controls (Phase 6)

| Method · Path | Permission | Notes |
|---|---|---|
| `GET /dashboard/category-controls` | any of the three control codes | per category: state, live / no-request / price-locked counts. |
| `POST /dashboard/category-controls` | `category.stop_new` for `stop_new_listings`; `category.pause` for `pause_category`; `platform.stop_everything` for `stop_everything` (writes one row per category) | `{ category?, level, reason, message_en, message_ar }` (`category` required except for `stop_everything`). `409 control_already_active`. |
| `POST /dashboard/category-controls/clear` | same code as the level | `{ category?, level }`. |
| `GET /dashboard/visibility` · `PATCH /dashboard/visibility/{key}` | `visibility.manage` | `{ shown: bool }`. |

## Dashboard — market makers, staff side (Phase 7 / 6A)

| Method · Path | Permission | Notes |
|---|---|---|
| `GET/POST /dashboard/promo-codes`, `PATCH /dashboard/promo-codes/{code}` | `promo.manage` | create `{ code, tied_customer_id, monthly_cap_egp }` (kind `market_maker`, `commission_waived = gives_spread = true`); patch `{ monthly_cap_egp?, active? }`. `422 promo_kind_not_available`, `422 promo_customer_not_market_maker`. |
| `GET /dashboard/promo-codes/{code}/uses` | `promo.manage` | every use (empty until 6B). |
| `GET /dashboard/market-maker/queue` | `market_maker.approve` | aged, unapproved live pieces with the numbers; views *not measured*. |
| `POST /dashboard/market-maker/approvals` | `market_maker.approve` | `{ listing_id, asking_price_seen, gold_value_seen?, rapaport_guide_seen? }`. `409 already_approved`, `422 listing_too_new`, `409 listing_not_live`. |
| `GET /dashboard/market-maker/cost?month=YYYY-MM` | `market_maker.approve` or `promo.manage` | pieces bought, commission and spread given up (zero until 6B). |

## Dashboard — people to watch (Phase 8)

`GET /dashboard/people-to-watch?from=&to=` and `/export` — `people_to_watch.view` (CEO, COO). Figures per signal, no thresholds (D6). Audited read.

## Classification summary

Non-breaking: every new endpoint and optional field. Potentially breaking: new refusal codes on existing POSTs (`legal_acceptance_required`; `category_stopped`, `category_paused`, `platform_stopped`) and the new permission strings — each arrives with the phase whose Dashboard and app updates handle it. Nothing renamed or removed. 6B adds only optional fields and a new route when it ships.
