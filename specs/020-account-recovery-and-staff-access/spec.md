# Spec 020 — Account recovery and staff access

**Status**: PLANNED — D20-1…5 confirmed by the owner 2026-10-11; clarifications C1–C6 below proposed, awaiting confirmation · **Repos**: Backend, Dashboard, Customer app · **Priority**: first (nothing else depends on it being late; launch depends on it)

## Why

Nobody who forgot a password can get back in without a developer, and staff accounts are created from a seeder. Both are described in the Technical Spec and were deferred:
- Part 2 §1: `POST /customer/auth/password/reset-request` and `reset-confirm`.
- `dahab-dashboard-authorization.md` §43: staff password reset "Not yet built".
- Part 2 §10 / spec 002 FR-080: create, disable and enable staff accounts "is a later feature".

## What exists today (verified in code)

- Customers sign in with phone + password + a one-time code on a new device; a signed-in customer may change the password (`POST /customer/me/password`, spec 017).
- The table `one_time_token` already allows the purposes `password_reset_customer` and `password_reset_staff`.
- Staff sign in with email + password + MFA; roles and permissions are managed from the Dashboard; staff list and role assignment exist. There is no endpoint that creates, disables or enables a staff member.

## Scope

### Story 1 — A customer who forgot their password gets back in

1. **Given** a phone number, **When** a reset is requested, **Then** a single-use code goes to that phone; the answer is the same whether or not the phone is registered; the request is rate limited per phone and per device.
2. **Given** the code and a new password that meets the password rule, **Then** the password changes, every other session and trusted device is signed out, the customer is told by SMS (and by email when there is one), and the change is audited.
3. A wrong, expired or reused code is refused (`otp_invalid`, `otp_expired`), counted against the same attempt cap as the sign-in code.
4. A suspended customer may reset (they may sign in); a closed account may not (`account_closed`).

### Story 2 — Staff recover a password

A staff member asks for a link to the email on file; the link is single-use and short-lived; after setting the password MFA is still required to sign in; every request and use is audited; no endpoint ever returns or sets a known password.

### Story 3 — Create, disable and enable staff

1. **Given** the permission `staff.manage`, **When** a full name, email and roles are entered, **Then** an account is created without a password, a set-password link is sent (Story 2's mechanism), the roles are assigned through the existing role rules, and the creation is audited.
2. **When** a staff member is disabled, **Then** every token is revoked at once, they can no longer sign in, their history keeps their name, and they appear under a *Disabled* filter; enabling restores sign-in after a new password link.
3. A person cannot create, disable or change the roles of themselves; a founder cannot be disabled through the API (founder status is never changeable through the API, Part 2 §10); the last active role manager cannot be disabled (spec 002 FR-023 still holds).

## Surfaces

| Repo | Change |
|---|---|
| Backend | the two reset endpoints for customers (`POST /customer/auth/password/reset-request|reset-confirm`), the staff reset pair (`POST /dashboard/auth/password/reset-request|reset-confirm`), `POST /dashboard/staff`, `POST /dashboard/staff/{id}/disable|enable`, permission `staff.manage`, audit events, error codes, notifications (SMS + email templates, editable in App text), Postman, OpenAPI |
| Dashboard | *Forgot password?* on the sign-in page and a set-password page; Users → Staff: *Add staff member*, *Disable / Enable*, a Disabled tab |
| Customer app | *Forgot password?* on sign-in, a two-step flow (phone, then code and new password), English and Arabic, texts in the App text catalogue |

## Depends on / used by

- Uses the existing one-time-token store, the SMS and email channels and the notification templates.
- **021** (founder security) builds on staff accounts that can be created and disabled.
- Independent of 022–025.

## Decisions (owner, 2026-10-11)

| ID | Question | Decision |
|---|---|---|
| D20-1 | Customer reset channel | SMS code to the phone on file; email only as a notice |
| D20-2 | Does a password reset open the withdrawal pause that a phone or email change opens (spec 017)? | Yes, same setting (`withdrawal.account_change_pause_hours`, 24 h) |
| D20-3 | Staff recovery | Emailed single-use link; a founder never sees or sets the password |
| D20-4 | Who holds `staff.manage` | CEO only (the CEO holds every code); no role seeded |
| D20-5 | Password rule for reset | The same rule as sign-up and change |

## Clarifications proposed (planned on the recommended answer; confirm or override)

| ID | Where the spec is silent or in tension | Recommended (as planned) |
|---|---|---|
| C1 | Story 1.4 says a closed account gets `account_closed`, but Story 1.1 says the answer is the same whether or not the phone is registered. A distinct answer would reveal that a closed account exists. | Both reset endpoints answer uniformly: a closed or unknown phone gets no code, `reset-request` answers as usual and `reset-confirm` answers `otp_invalid`. `account_closed` stays where it is today (password sign-in). Story 1.4 is met by "a closed account cannot reset". |
| C2 | D20-2 names only the pause. Spec 017's phone change also cancels withdrawals not yet released (one shared function). | A reset runs the same safety stop: unreleased withdrawals are cancelled (money back to available) and the pause opens. Reason: a reset proves only the phone. |
| C3 | "Enabling restores sign-in after a new password link." | Enabling also deletes the old password, so the link is the only way back in (the account may have been disabled because of a compromise). The authenticator enrolment is kept. |
| C4 | Reason on the new staff actions. Role changes already require one (spec 002 FR-055). | `reason` required on create, disable and enable (`422 reason_required`), audited. |
| C5 | "Short-lived" link. | Recovery link 30 minutes (as the email-change and withdrawal links); invitation and enable link 48 hours. A new request voids older open links. |
| C6 | The Dashboard needs to tell an invited member from an active one. | Optional field `password_set` (boolean) on the staff member; and optional `status=active|disabled` filter on the staff list (default unchanged: all). |

## Out of scope

Email-address recovery for customers (spec 017 covers changing it signed in); founder-account recovery after a compromise (spec 021).

## Mock removed

None (this spec adds real flows; no mock exists for them).
