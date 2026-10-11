# Spec 021 — Founder-account security

**Status**: Decisions D21-1…4 answered by the owner (2026-10-11); clarifications C1–C2 answered (2026-10-11); planned (`plan.md`, `tasks.md`; plan questions Q1–Q3 answered with the defaults), not built · **Repos**: Backend, Dashboard · **Priority**: after 020 · **Depends on**: 020 (staff accounts)

## Why

Part 1 §8 calls a compromised founder account "the largest single risk" and names three protections, all modelled in the schema and none built:
1. a new-device sign-in of a founder is held until the **other** founder approves it;
2. either founder can **freeze** the other instantly; unfreezing needs **both** confirmations;
3. both founders are told in real time of any sensitive action on either account.

## What exists today

- Tables `founder_device_approval` (with the CHECK `approver_is_not_self`) and `account_freeze` (with `no_self_freeze` and two unfreeze confirmation columns).
- `staff.is_founder` (never changeable through the API) and the authorisation step that already refuses a frozen account (`account_frozen`).
- No endpoint creates an approval, freezes, unfreezes or confirms anything.

## Scope

### Story 1 — A new device is held until the other founder approves

1. **Given** a founder signing in from an unrecognised device, **Then** after the password and MFA the session is withheld, a `founder_device_approval` row is created, and the other founder is told.
2. **When** the other founder approves it from their own session, **Then** the held sign-in completes; nobody can approve their own (`approver_is_not_self`); an approval expires after 15 minutes and is single use (D21-4); a refusal is possible and audited.
3. Known devices sign in as today. The first founder device of an account is approved by the other founder.

### Story 2 — Freeze and unfreeze

1. **Given** a founder, **When** they freeze the other founder, **Then** every token of the frozen account stops working immediately, every action is refused (`account_frozen`), a reason is required, and the frozen founder sees a plain message at sign-in.
2. A founder cannot freeze themselves (`no_self_freeze`).
3. **Unfreezing** needs both confirmations (`unfreeze_confirm_1` and `_2`) from two different people: the frozen founder or a named second approver first, the founder who is not frozen last (D21-2, C1); each confirmation is audited; after the second the account works again and all its old sessions stay revoked.

### Story 3 — Real-time notice of sensitive actions

Both founders are told (email + a Dashboard notice; SMS too for a freeze — D21-3) when, on either founder account, any of these happens: a wallet adjustment, a rate or setting change, a promo-code change, a manual gold price, a new or disabled staff account, a new device approval, a freeze. Notices never contain the secret itself.

## Surfaces

| Repo | Change |
|---|---|
| Backend | `POST /dashboard/founder/device-approvals/{id}/approve|refuse`, `POST /dashboard/founder/freeze`, `POST /dashboard/founder/unfreeze` (one confirmation per call), listing of pending approvals and freezes, the held-sign-in step in the staff login, audit events, notices, Postman, OpenAPI |
| Dashboard | A *Security* page for founders: pending device approvals, freeze / unfreeze with reason, the history; a held-sign-in screen on the login |
| Customer app | none |

## Depends on / used by

020 (staff creation and disabling share the staff account model). Story 3's channel is the same open question as the pattern alerts in **024** (OI-1.3); they should be answered together.

## Decisions (owner, 2026-10-11)

| ID | Question | Decision |
|---|---|---|
| D21-1 | The recovery path after a confirmed compromise (OI-1.2) is "not written down" | Build only freeze and dual unfreeze; recovery stays a manual procedure outside the system |
| D21-2 | Who confirms an unfreeze when the other founder is the compromised one? | Both founders, the founder who is not frozen confirms last; a stand-in pair if one is unreachable (made precise by C1) |
| D21-3 | The real-time notice channel | Email to both founders plus a Dashboard notice; SMS only for a freeze |
| D21-4 | How long does a held device approval stay valid? | 15 minutes, single use |

## Clarifications (owner, 2026-10-11)

- **C1 (D21-2).** The CEO and COO seed roles are held by the two founders, so "a CEO + COO pair" cannot name a third person. Answer: **confirmation 1** comes from the frozen founder through a narrow call that issues no token (email + password + authenticator code, from a device already known for that founder), **or**, if the frozen founder is unreachable, from a staff member holding the new permission `founder.unfreeze_second` (no seed role; the CEO names someone through roles). **Confirmation 2 is always last and always the founder who is not frozen.** The frozen account never receives a session while frozen.
- **C2 (bootstrap).** The Dashboard does not send `X-Device-Id` today, so no staff device is known anywhere; read literally, both founders would be held at their first sign-in with nobody to approve. Answer: a server command `php artisan founder:trust-device <email> <device-id>` (system actor, audited, no API path) trusts a founder's first device; it is also the way back if both founders lose every device. It is the same kind of controlled manual operation as setting `is_founder`.

## Out of scope

Hardware keys, IP allow-lists, any automatic freezing.

## Mock removed

None.
