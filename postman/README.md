# Postman Collection

`Dahab-Backend.postman_collection.json` mirrors every route in `routes/api.php`, grouped
into folders by API surface, then by domain: `Dashboard` (Auth, Identity, ...), `Customer`
(Auth, Identity, ...) and `Health`, the same way the routes are grouped.
`Dahab-Backend.local.postman_environment.json` provides `base_url`, `device_id`,
`access_token`, `refresh_token`, `staff_access_token`, `staff_refresh_token`,
`staff_mfa_session_ref`, `upload_token` and `document_id` variables for local use against
`APP_URL` (default `http://localhost`). The last three are filled in by test scripts:
`staff_mfa_session_ref` by **Staff Login** (MFA roles), `upload_token` by **Upload ID Image**,
`document_id` by **Submit Identity Document** / **List Identity Documents**.

There are two API surfaces with **separate tokens**:

| Surface | Prefix | Bearer variable | Refresh variable |
|---|---|---|---|
| Customer API | `/api/v1/customer/*` | `access_token` (collection default) | `refresh_token` |
| Dashboard API | `/api/v1/dashboard/*` | `staff_access_token` | `staff_refresh_token` |

A token of the other principal is rejected with `401`. An access token on a refresh
endpoint, or a refresh token on an access endpoint, is rejected with `403 forbidden`.
Dashboard requests set their own Bearer variable. Get a staff session with **Dashboard →
Auth → Staff Login** (local accounts `<role>@dahab.test`, password `seeded-password-1`, after
`php artisan db:seed`). `ceo`/`coo`/`finance` need MFA: Staff Login answers `mfa_required`
or `mfa_enrollment_required` and saves a `session_ref`; finish with **Staff MFA Verify** or
**Staff MFA Enroll** (a 6-digit TOTP from an authenticator app).

The identity flow spans both surfaces: **Customer → Identity** (upload → submit) as a customer,
then **Dashboard → Identity** (list → view image → approve/reject) as `verification` or `ceo`.

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
   Registration does not trust a device and issues no session — a new customer must be
   approved by staff (Dashboard → Identity → Review — Verify) before they can log in.
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
