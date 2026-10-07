# Research — spec 017 Customer account

Engineering choices; the 28 product answers are in [spec.md § Clarifications](./spec.md). Shared rules (idempotency, audit, RLS, after-commit notifications, money service lock order) are those of specs 013–016 and are not restated.

## R1 — Paths

Customer: `/customer/me/{phone-change,email-change,password,sessions,notifications,saved-pieces,account/close,listing-reports}`. Public: `/contact-changes/email/{read,confirm}` (the link target, like spec 013's `/withdrawal-confirmations/*`), `/reference/{legal-documents,support-contacts}`. Staff: `/dashboard/listing-reports*`, `/dashboard/customers/{id}/notifications`. Full list in [contracts/account-api.md](./contracts/account-api.md).

## R2 — Phone code in the cache, email link in `one_time_token`

Part 1 §2: an OTP is "not stored; short-lived hash in a rate-limited cache"; an email link is a "one-time signed token". So:
- Phone: `ContactChangeChallengeStore` (encrypted cache, same shape as `CustomerLoginChallengeStore`): `{customer_id, new_phone, otp_hash, tries, expires_at}`, keyed by a random `challenge_id`; one live challenge per customer (a pointer key `customer:{id}` → the newest id; the older one is forgotten). Confirm runs under the store's lock **and** a `customer` row lock.
- Email: `one_time_token` gains purpose `email_change` (payload `{new_email}`), 30 minutes; a new request consumes older open ones. Confirm = `UPDATE … SET consumed_at = now() WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > now() RETURNING` — a link used twice commits once.
- The key entity "contact change challenge" of the spec is therefore these two stores, not a new table.

## R3 — Pause and cancel shared with spec 013

Extract the "cancel unreleased withdrawals + open the pause" block of `WorksWithdrawals::makeInUse` into `Support/Withdrawals/WithdrawalSafetyStop::apply(customerId, trigger, ...)` (returns pause + cancelled numbers) and call it from `makeInUse` (unchanged behaviour) and the phone/email confirmations. Contact confirmations take the payout-account locks first (`lockAccounts`, as `SubmitWithdrawalAction` does), so a racing withdrawal either commits before (and is cancelled) or waits and meets the pause. `withdrawal_pause` gains `trigger_kind TEXT NOT NULL DEFAULT 'payout_account' CHECK IN ('payout_account','phone_change','email_change')` with `triggered_by_account` required only for `payout_account`. Open withdrawal confirmations: `replaced_at = now()` on every row not used/replaced (email change only).

## R4 — Devices and sessions

Tokens carry no device today. `personal_access_tokens` gains nullable `device_fingerprint_hash`, `device_platform` (set by `IssueTokenFamilyAction` from the request's `X-Device-Id` / `X-Device-Platform`; rotation copies them). `customer_trusted_device` gains nullable `platform`, `user_agent` (≤ 255). `GET /customer/me/sessions` lists open families (as spec 007's query) with platform, user agent, first/last active and `is_current` (the family of the calling token); sessions issued before this spec show platform `null`. `POST /customer/me/sessions/{family}/sign-out` revokes that family and, when it has a fingerprint, every family with the same fingerprint and deletes the trusted-device row ("forget"); the current family → `422 current_session` (use logout). Phone change: revoke every family except the current one and delete every trusted device except the current fingerprint. Password change: revoke other families, keep devices. Close: revoke all, delete all devices.

## R5 — The inbox as a third channel

- `App\Notifications\Channels\InboxChannel` registered as `inbox`; a notification opts in by implementing `Contracts\InboxNotification::toInbox(Customer): ?InboxMessage` (type, params, link kind + id, title/body EN + AR from its existing `subject()/body(bool)`).
- `via()` of each opted-in notification adds `inbox` (first) when the notifiable is a `Customer`. Opted in: Buy request, Listing decision, Order, Payout, Wallet, Top-up credited/rejected, Registration submitted, Verified, Verification rejected / resubmission, and the new Account and Listing-report notifications. Never: the three OTP notifications, the withdrawal confirmation, the proxy notice (goes to a third person), the phone code and the email-change link.
- The channel writes under `DatabaseActor::elevate('system')` (a notification may be sent from another customer's or a staff request). Idempotent on retries: `UNIQUE (customer_id, dedupe_key)` with `dedupe_key` = the notification's id, `ON CONFLICT DO NOTHING`.
- Links: `order` notifications carry the order reference; `toInbox` resolves it to the id (elevated read). Kinds: `order, listing, buy_request, wallet, withdrawal, payout_account, topup, invoice, credit_note, dispute, account, none`.
- No settings table: nothing is switchable (Q14), so the settings screen is static in the app; Q12 applies when a later spec adds a switch.

## R6 — Saved pieces

`saved_listing (customer_id, listing_id, saved_at, PK both)`, forced RLS (own rows). Save allowed only while the listing is `live`/`reserved` (`422 listing_not_saveable`); idempotent upsert. The list reads the listing through the `market` scope; a row whose listing is no longer on the market returns `available: false` with the snapshot taken at save time (`summary` JSONB: piece type, karat, stated weight — no media) and no price. At most `saved.max_per_customer` saved per customer — a new integer setting (group operations, min 1, max 1000, default 200), edited in the Dashboard Settings page with history like every setting (spec 005); `saved_limit_reached` 422 with `details.limit`. The cap keeps the list unpaged for the piece page's "is this saved" check (`GET … ?listing_id=`).

## R7 — Help and legal

`GET /reference/legal-documents` lists the four codes `terms, privacy, selling_rules, id_handling` with the latest published version or `null`; `/{code}` unchanged (404 when unpublished). `config/dahab-support.php` (phone, hours EN/AR, WhatsApp, email, Facebook/Instagram/TikTok handles; demo values from the prototype, header comment "replace before production") served by `GET /reference/support-contacts`. Chat has no backend: the row is not shown. FAQ: not built.

## R8 — Closing

- `customer` gains `closed_at`, `closed_reason` (CHECK six codes), `closed_note` (≤ 500, only with `other`); `CustomerStatus::CLOSED` derived first. `AssertCustomerCanSignIn` refuses `account_closed` (403) at sign-in and new-device OTP; refresh fails because the families are revoked.
- Blockers (each a code in `details.blockers`): `open_order` (order not terminal, either side), `active_buy_request`, `listing_in_sale` (accepted/at_inspection/settling), `piece_at_branch` (listing `awaiting_seller_return`/`seller_unclaimed` as seller, or `sold`/`uncollected_expired` not collected as buyer — derived from "finish what is in progress"), `wallet_balance` (available or held ≠ 0), `pending_withdrawal`, `open_dispute`, `pending_extension_request`, `pending_topup`. `reserved` listings are covered by `active_buy_request` on the buyers' side and refused as `listing_in_sale`. Payout accounts and a running pause do not block (spec clarification). `piece_at_branch` approved by the user 2026-10-06.
- Lock order: payout accounts → customer row → ledger accounts → the customer's listings (`FOR UPDATE`), then the checks, then the writes.
- Race guard in the database: `refuse_closed_customer()` BEFORE INSERT on `listing`, `buy_request`, `withdrawal`, `withdrawal_confirmation`, `topup`, `dispute`, `payout_account`, `saved_listing`, `listing_report` and on `ledger_posting` for a customer account → SQLSTATE **DH013** → `409 account_closed`.
- New listing transitions `draft|in_review|changes_requested|suspended_hold → withdrawn` (note "account closed"); `live → withdrawn` exists. Listing history actor = the customer.

## R9 — Listing reports

`listing_report` + sequence → `RPT-n`; partial unique index `(listing_id, reporter_id) WHERE state = 'open'`; forced RLS (reporter reads/inserts own; staff elevated). A sweep `listing-reports:close-gone` (every five minutes, system actor) sets open reports whose listing is no longer `live`/`reserved` to `listing_gone` and tells each reporter — no hook in the spec 010–014 listing actions; a take-down from a report marks its reports `actioned` in its own transaction. Reporter notices go after commit. Staff: `listing_report.handle` (+ `listing.takedown` for take-down, which calls the spec 010 take-down Action).

## R10 — Rate limits

Named limiters: `contact-change-request` 3/h per customer; `contact-change-confirm` and `password-change` 5/15 min per customer; `session-sign-out` 10/min; `listing-report` 10/day; `email-change-link` (public) 10/min per IP.

## R11 — Errors (new codes)

`contact_taken` 409 · `same_contact` 422 · `change_code_invalid` 422 (wrong / expired / no challenge; `details.tries_left`) · `change_code_locked` 429 · `change_link_invalid` 410 · `current_password_wrong` 422 · `current_session` 422 · `account_closed` 403 / 409 · `account_has_open_items` 409 · `listing_not_saveable` 422 · `saved_limit_reached` 422 · `listing_not_reportable` 422 (own piece, or not live/reserved) · `report_already_open` 409 · `report_not_open` 409.

## R12 — Audit events

`customer.phone_change_requested`, `customer.phone_changed`, `customer.email_change_requested`, `customer.email_changed`, `customer.password_changed`, `customer.session_signed_out`, `customer.account_closed`, `listing_report.created`, `listing_report.dismissed`, `listing_report.actioned`. Contacts masked as in spec 007. They target the customer, so the Customer file History shows them unchanged.

## R13 — Status enum value `closed`

Adding `closed` to the customer `status` is potentially breaking: Dashboard status chips/filters and Flutter's `me` status map are updated in the same change; the Dashboard customers list filter gains `closed`.

## R14 — Tests

Concurrency on two connections: two phone confirmations; phone confirmation vs withdrawal submit; email link used twice; close vs buy request (as buyer and on their live piece); close vs top-up credit; two reports on one piece. New tables are truncated by the truncating suites (no keep-list entries — none is a transition table); the listing transition rows added by the migration are kept by the suites that keep `listing_transition`.
