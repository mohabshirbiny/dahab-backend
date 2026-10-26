# Settings page, new-device SMS code and relist

> File: `docs/features/settings-page-and-relist.md` · Branch: `claude/commission-store-settings*` in each repo
> Status: done (uncommitted) · Date: 2026-10-27

## Goal

Owner's notes of 2026-10-27:

1. The Dashboard's *Commission rates* page holds every setting: renamed *Platform settings*, each section an
   accordion that opens and closes.
2. A setting that turns on or off the new-device check: a staff member signing in on a device not yet known
   gets a code by SMS (the default, for all staff); a founder's first device is confirmed the same way instead of
   `php artisan founder:trust-device`.
3. The seller is asked to confirm before *Take it down*.
4. *Change fee* is called *Change making charge*.
5. Sell step 1: the weight and the making charge (and the asking price) are typed by hand, like the Change price
   screen.
6. Listings has a *Taken down* tab; staff put a piece back live, and so does the seller from the app.

Decisions (owner, 2026-10-27): the SMS code applies to all staff; a founder only for a first device (later new
devices still wait for the other founder). The seller brings back only a piece they took down themself; staff
bring back any taken-down piece. Back to live directly, no new review.

## Impact Summary

```
Backend:      YES
Database:     YES (one setting row, one listing_transition row)
API:          YES (4 new endpoints, new optional fields, one new held sign-in shape)
Dashboard:    YES
Customer App: YES
Auth:         YES (staff sign-in may answer device_code_required)
Permissions:  NO new permission (relist uses listing.takedown; the setting uses settings.manage)
```

## Backend

- Setting `auth.staff_new_device_sms` (bool, operations group, default **on**). `FounderDeviceGate` now also
  issues the SMS code (`StaffDeviceCodeAction`, cache challenge `StaffDeviceCodeStore`, the customer new-device
  code as model: 5 tries, `otp.*` expiry, resend cooldown and hourly cap, bound to `X-Device-Id`). Staff with no
  phone on file are not asked. Config `dahab-auth.staff_device_sms_enforced` (`DAHAB_AUTH_STAFF_DEVICE_SMS`,
  forced on in production) only lets the test suite leave it out (`phpunit.xml` sets false;
  `StaffDeviceCodeTest` turns it on).
- Audit `auth.staff.device_code_sent|device_code_failed`, `founder.device_trusted_by_sms` (a founder alert),
  `listing.relisted`.
- `withdrawn → live` added to `listing_transition`. `RelistListingAction`: only a withdrawal from live or reserved
  (the latest move into `withdrawn`); the seller only their own take-down; refused like an approval (switches,
  suspended seller, karat off) and for a closed account. Staff relist tells the seller
  (`ListingDecision::RELISTED`, templates `listing.relisted.sms|mail_subject`). Promo credits voided by the
  take-down stay voided.

## API changes (all non-breaking except the sign-in note)

| Endpoint | Notes |
|---|---|
| `POST /dashboard/auth/device-code/verify` `{challenge_id, code}` | Public. Session; `otp_invalid` / `otp_expired` 401 |
| `POST /dashboard/auth/device-code/resend` `{challenge_id}` | Public. `too_many_requests` 429 before the cooldown |
| `POST /dashboard/auth/login`, `/mfa/verify`, `/mfa/enroll` | May answer `{device_code_required, challenge_id, phone_hint, expires_at, resend_available_at}` — **potentially breaking** for a client that does not know the shape (the Dashboard does) |
| `POST /customer/me/listings/{id}/relist` | Trade gate, idempotent. `listing_not_relistable` 409 (`details.reason`: `taken_down_by_staff`, `not_taken_down`, `account_closed`) |
| `POST /dashboard/listings/{id}/relist` `{reason}` | `listing.takedown`, idempotent |
| Listing resources (customer + staff) | New `can_relist`, `taken_down_by` (`seller`/`staff`/null) |
| `GET /dashboard/listings` | `meta.counts.withdrawn` |

## Dashboard

*Platform settings* (route `/dashboard/rates` kept): every panel collapsible (`Panel` `collapsible`/`open`),
the first open; a *Sign-in security* panel. New `/device-code` screen (code, countdown, *Send a new code*);
login / MFA route there on `device_code_required`. Listings: *Taken down* chip, a note on who took it down, and
*Put it back on the market* with a reason.

## Customer App

*Change making charge* on the piece page and on the My listings card (with *Take it down*); the take-down
question now says the piece can be put back; *Put it back on the market* (with a question) on My listings and the
piece page; what a taken-down piece says (by you / by Dahab). Sell step 1: weight, making charge and the asking
prices are number fields (`DNumberField`) with an error and a held *Continue* when a figure cannot be used. Texts
`listing.relist.*`, `sell.figures.*` (Arabic AR-DRAFT); manifest regenerated and copied to the Backend.

## Not done / follow-ups

- Staff phone numbers cannot be edited from the Dashboard; staff without one are not asked for a code.
- `LocalFinanceSeeder` fails on main (`PayDirectCompensationAction` now needs an order), so `migrate:fresh --seed`
  stops at that seeder.
