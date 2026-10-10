# Postman Collection

`Dahab-Backend.postman_collection.json` mirrors every route in `routes/api.php`, grouped
into folders by API surface, then by domain: `Dashboard` (Auth, Identity, ...), `Customer`
(Auth, Identity, ...) and `Health`, the same way the routes are grouped.
`Dahab-Backend.local.postman_environment.json` provides `base_url`, `device_id`,
`access_token`, `refresh_token`, `staff_access_token`, `staff_refresh_token`,
`staff_mfa_session_ref`, `upload_token`, `document_id`, `role_name`, `staff_id`, `karat_code`,
`branch_id`, `closure_id`, `manual_price_id`, `audit_entry_id`, `audit_cursor` and the listing variables
(`listing_id`, `media_id`, `market_listing_id`, `market_media_id`, `piece_type_id`, `ownership_legal_doc_id`,
`listing_photo_token`, `listing_photo_token_2`, `listing_video_token`, `listing_invoice_token`,
`stone_certificate_token`) and the buy-request variables (`buy_request_id`, `deposit_legal_doc_id`,
`confirm_locked_price`, `order_id`; spec 011)
for local use against `APP_URL` (default `http://localhost`). The last five are filled in by
test scripts: `staff_mfa_session_ref` by **Staff Login** (MFA roles), `upload_token` by
**Upload ID Image**, `document_id` by **Submit Identity Document** / **List Identity
Documents**, `role_name` by **Create Role**, `staff_id` by **List Staff**.

There are two API surfaces with **separate tokens**:

| Surface | Prefix | Bearer variable | Refresh variable |
|---|---|---|---|
| Customer API | `/api/v1/customer/*` | `access_token` (collection default) | `refresh_token` |
| Dashboard API | `/api/v1/dashboard/*` | `staff_access_token` | `staff_refresh_token` |

A token of the other principal is rejected with `401`. An access token on a refresh
endpoint, or a refresh token on an access endpoint, is rejected with `403 forbidden`.
Dashboard requests set their own Bearer variable. Get a staff session with **Dashboard →
Auth → Staff Login** (local accounts `<role>@dahab.test`, password `seeded-password-1`, after
`php artisan db:seed`). Founders (`ceo@`, `coo@`) and anyone holding a role flagged
`requires_mfa` (`finance` in the seed) need MFA: Staff Login answers `mfa_required` or
`mfa_enrollment_required` and saves a `session_ref`; finish with **Staff MFA Verify** or
**Staff MFA Enroll** (a 6-digit TOTP from an authenticator app).

The identity flow spans both surfaces: **Customer → Identity** (upload → submit) as a customer,
then **Dashboard → Identity** (list → view image → approve/reject) as `verification` or `ceo`.

**Dashboard → Access Control** (spec 002) manages roles and who holds them, as `ceo` or `coo`:
List Permissions → Create Role (saves `role_name`) → List Staff (saves `staff_id`) → Set Staff
Roles. Permission and role-assignment changes need a `reason`, and nobody can change their own
access. **Set Staff Branch** assigns a staff member to a branch (uses `branch_id`).

**Dashboard → Reference Data** (spec 004) manages karats, branches with their weekly hours, and
closures/public holidays. View as `ceo`, `coo`, `finance` or `operations`; toggle karats as
`finance`; add karats as `coo`; manage branches and closures as `coo` or `operations`.
Add Karat saves `karat_code`, Add Branch / List Branches save `branch_id`, Add Closure saves
`closure_id`.

**Dashboard → Pricing** (spec 005) shows and changes gold prices, per-karat adjustments and settings. View as `ceo`,
`coo`, `finance` or `operations`; rates, adjustments and manual prices need `finance` or `ceo` (MFA). Enter Manual
Price and Current Gold Prices save `manual_price_id`. A manual price is refused while the price feed is healthy; the
feed's credentials live only in the Backend's `.env` and never in Postman.

**Dashboard → Audit Log** (spec 006) lists, opens and exports the audit log: everything as `ceo`, own actions only as
`coo`, `finance`, `operations` or `verification`. List Audit Log saves `audit_entry_id` and `audit_cursor` (the next page).

**Wallets** (spec 008) are read-only. **Customer → Wallet** shows a verified customer their own available, held and
history. **Dashboard → Wallets** shows the overview (customer wallets, the bank's cash, the safety figure and a system
total that must be 0), one customer's wallet (uses `customer_id`), and the Wallet statement in three views (one
customer, all customers, the Dahab wallet) with CSV export. It needs `wallet.view`: `ceo` or `finance`, not `coo`.
Nothing in this feature moves money, so wallets read 0 until the first money-moving feature (Wallet Top-up) lands.

**Wallet Top-up** (spec 009) is a manual transfer. As a verified customer: **Customer → Wallet → Top-up Methods**
(saves `receiving_account_id`) → optionally **Upload Top-up Receipt** (saves `receipt_upload_token`) → **Submit
Top-up Notice** (saves `topup_id`; drop the `receipt_upload_token` line if you have none). Then, as `finance` or `ceo`,
**Dashboard → Incoming Transfers**: list (saves `topup_id`), view the receipt, then **Match Transfer** (credits what
arrived), **Hold** / **Unhold**, **Reject**, or **Credit Transfer By Hand** (uses `customer_id`). A suspended customer
needs `arrival_reference`. Every top-up POST sends a fresh `Idempotency-Key`. **Receiving accounts** are managed in
the same folder (`topup.accounts.manage`). The COO gets 403 everywhere here.

**Listings** (spec 010). The **Market** folder is public (no token): **Reference** (karats, piece types — saves
`piece_type_id`, branches — saves `branch_id`, the ownership declaration — saves `ownership_legal_doc_id`), **Browse
Listings** (saves `market_listing_id` / `market_media_id`), **Show Listing**, **View Listing Media**. Send a customer
token on the market requests only to get `is_mine`. As a verified customer, **Customer → Listings**: the five uploads
(each saves its token; choose a real file in the body), **Create Listing** (saves `listing_id`), **Update Listing**,
**Submit Listing**, **Withdraw Listing** (live only, final), list / show / media. As `operations`, `coo` or `ceo`,
**Dashboard → Listings**: the queue (saves `listing_id` / `media_id`), **Approve**, **Request Listing Changes**,
**Reject** (both need a note) and **Take Down Listing** (live only, needs a reason). Every listing POST and PATCH
sends a fresh `Idempotency-Key`. Upload tokens are single use: upload again before creating another listing.

**Orders** (spec 012). After a seller accepts (Customer › Listings › Accept), **Customer → Orders → List My Orders**
saves `order_id`. As `operations` (or an `igi_branch` user assigned to the order's branch), **Dashboard → Orders →
Receive Piece**, then as `igi_branch` **Record Inspection Result** (saves `inspection_id`; send `supersedes_id` only to
correct the latest result). On a pass the buyer runs **Pay Balance** (saves `collection_code`). On a stone regrade,
**Propose Regrade Price** (`operations`/`coo`). The seller may **Cancel Sale** while the piece has not reached the
branch. After a no-pay (the `orders:sweep` command) or an inspection cancel, the seller's **Show My Order** saves
`return_code`; staff run **Hand Back Returned Piece**, or the seller **Relist Returned Piece**. **Branch Work List**
shows what to do at your branch. After an adjustment the buyer runs **Decide Adjusted Price** (uses `inspection_id`). After
payment, **Hand Over To Buyer** uses `collection_code`. **Change Order Branch** (uses `branch_id`) and **Extend Order
Deadline** need `order.change_branch` / `order.extend_deadline`. Five wrong codes lock a handover for 15 minutes. Every POST sends a fresh
`Idempotency-Key`. Variables: `order_id`, `inspection_id`, `collection_code`, `return_code` (the last two secret).

**After collection** (spec 018). When staff **Hand Over To Buyer**, the order stores a free-relist window
(`deadline.free_relist_working_hours`, working hours on the order's branch): **Customer → Orders → Show My Order** as the
buyer returns `free_relist` (`status`, `ends_at`). Inside the window the buyer runs **Free Relist (Buyer)** (needs
`ownership_legal_doc_id` and the price the category needs; saves `listing_id`): a new live listing at 0% commission with
no review. Selling that listing settles with no commission, VAT or minimum and issues only the buyer's invoice.
**Rate An Order** works for either party (the seller from ready to collect, the buyer once completed, for 30 days). As
`ceo` or `coo`, **Dashboard → Orders → Show Order** also returns `ratings` (`rating.view`); **List Orders** takes
`free_relist=open|used|expired`.

## Import

1. Postman → Import → select both `.json` files in this folder.
2. Select the "Dahab Backend - Local" environment.
3. Run **Customer → Auth → Customer Register** (or **Customer Login**) once — its test script
   writes `access_token`/`refresh_token` into the collection variables automatically, so
   every other customer request (Bearer auth inherited from the collection) picks it up.
   Login sends `X-Device-Id: {{device_id}}`. From a device that was never trusted, Login
   answers `otp_required` and saves `challenge_id`; finish with **Customer Login — Verify
   OTP** (SMS code; `123456` in the local environment) from the same device, which trusts
   the device and stores the tokens. **Customer Login — Resend OTP** sends a new code.
   Registration does not trust a device and issues no session. A customer can log in before
   staff approve them (Dashboard → Identity → Review — Verify), but until then only sign-in,
   their profile and identity-document requests work; everything else answers
   `403 verification_required` (spec 002).
4. **Customer Refresh** / **Staff Refresh** send the refresh token and store the new pair.

## Keeping it updated

Whenever a new endpoint is added, changed, or removed in `routes/api.php`:

1. Add/update the matching request in `Dahab-Backend.postman_collection.json`, inside the
   folder for its route group (create a new folder if the route introduces a new domain,
   e.g. `Route::prefix('wallet')`).
2. Set `auth: { "type": "noauth" }` on the request if the route has no auth middleware
   (register/login are the only public auth routes). Customer routes are behind
   `auth:customer` — leave them to inherit the collection's Bearer auth (`access_token`).
   Dashboard routes are behind `auth:staff` — set a request-level Bearer using
   `{{staff_access_token}}` (or `{{staff_refresh_token}}` for the refresh endpoint). The
   refresh endpoints need the refresh-token variable, not the access token.
3. For endpoints that mint tokens (register/login/refresh), add a `test` script that
   stores the token(s) into `pm.collectionVariables` the same way the existing Auth
   requests do (register/login: `body.data.session.*`; refresh: `body.data.*`), so the
   collection stays usable end-to-end without manual copy-pasting.
4. Keep request bodies in sync with the corresponding `FormRequest` rules.

This is tracked in `CLAUDE.md` as a required step for any task that adds or changes an
API endpoint.

**Buy requests** (spec 011). Run **Market → Show Listing** (saves `confirm_locked_price` from `current_price`) and
**Market → Reference → Deposit Agreement** (saves `deposit_legal_doc_id`). As a second verified customer with money in
the wallet, **Customer → Buy Requests → Send Buy Request** (saves `buy_request_id`; a short wallet answers 409
`insufficient_funds` with `details`, a stale price 409 `price_moved`), **List / Show My Buy Requests**, **Leave the
Queue**. As the seller, **Customer → Listings → Buy Requests on My Listing** (saves the head's `buy_request_id`),
**Accept First Buy Request** (needs `branch_id` = one of the listing's branches) or **Decline First Buy Request**. As
`operations`, `coo` or `ceo`, **Dashboard → Listings → Show Listing** on the accepted piece saves `order_id`; then
**Dashboard → Orders → Cancel Acceptance** (`order.cancel`). Every POST sends a new `Idempotency-Key`.

**Withdrawals** (spec 013). As a verified customer with money: **Customer → Withdrawals → Payout Account Declaration**
(saves `payout_legal_doc_id`), **Add Payout Account** (saves `payout_account_id`; an Egyptian IBAN with a valid
checksum or 8–20 digits). As `verification`, `finance` or `ceo`, **Dashboard → Withdrawals → List Payout Accounts To
Check** (saves `payout_account_id`) and **Verify** or **Refuse**. Back as the customer, **Request Withdrawal
Confirmation** (saves `confirmation_id`). Locally the email goes to `storage/logs/laravel.log`: copy the `token=` value
of the link into `confirmation_token`, then **Withdrawal Confirmations → Confirm Withdrawal** (no token needed) and
**Customer → Withdrawals → Submit Withdrawal** (saves `withdrawal_id`). As `finance` or `ceo` (never `coo`),
**Dashboard → Withdrawals → List Withdrawals**, **Take For Review**, **Hold** / **Unhold**, **Release** (send the
transfer at the bank first, then record it) or **Reject**. **Use Payout Account** on a second verified account cancels
open withdrawals and pauses new ones for `withdrawal.account_change_pause_hours`. Every POST except the public pair
sends a new `Idempotency-Key`.
