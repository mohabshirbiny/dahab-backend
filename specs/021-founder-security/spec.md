# Spec 021 — Founder-account security

**Status**: DRAFT for the owner's confirmation · **Repos**: Backend, Dashboard · **Priority**: after 020 · **Depends on**: 020 (staff accounts)

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
2. **When** the other founder approves it from their own session, **Then** the held sign-in completes; nobody can approve their own (`approver_is_not_self`); an approval expires after a short time and is single use; a refusal is possible and audited.
3. Known devices sign in as today. The first founder device of an account is approved by the other founder.

### Story 2 — Freeze and unfreeze

1. **Given** a founder, **When** they freeze the other founder, **Then** every token of the frozen account stops working immediately, every action is refused (`account_frozen`), a reason is required, and the frozen founder sees a plain message at sign-in.
2. A founder cannot freeze themselves (`no_self_freeze`).
3. **Unfreezing** needs both confirmations (`unfreeze_confirm_1` and `_2`) from two different founders or the founder and a second named approver **[OPEN D21-2]**; each confirmation is audited; after the second the account works again and all its old sessions stay revoked.

### Story 3 — Real-time notice of sensitive actions

Both founders are told (channel **[OPEN D21-3]**) when, on either founder account, any of these happens: a wallet adjustment, a rate or setting change, a promo-code change, a manual gold price, a new or disabled staff account, a new device approval, a freeze. Notices never contain the secret itself.

## Surfaces

| Repo | Change |
|---|---|
| Backend | `POST /dashboard/founder/device-approvals/{id}/approve|refuse`, `POST /dashboard/founder/freeze`, `POST /dashboard/founder/unfreeze` (one confirmation per call), listing of pending approvals and freezes, the held-sign-in step in the staff login, audit events, notices, Postman, OpenAPI |
| Dashboard | A *Security* page for founders: pending device approvals, freeze / unfreeze with reason, the history; a held-sign-in screen on the login |
| Customer app | none |

## Depends on / used by

020 (staff creation and disabling share the staff account model). Story 3's channel is the same open question as the pattern alerts in **024** (OI-1.3); they should be answered together.

## Decisions needed from the owner

| ID | Question | Proposed |
|---|---|---|
| D21-1 | The recovery path after a confirmed compromise (OI-1.2) is "not written down" | Build only what the documents state: freeze and dual unfreeze; recovery stays a manual procedure outside the system until you write it |
| D21-2 | Who confirms an unfreeze when the other founder is the compromised one? | Both founders (the unfrozen one confirms last); a CEO-and-COO pair if one founder is unreachable |
| D21-3 | The real-time notice channel | Email to both founders plus a Dashboard notice; SMS only for a freeze |
| D21-4 | How long does a held device approval stay valid? | 15 minutes, single use |

## Out of scope

Hardware keys, IP allow-lists, any automatic freezing.

## Mock removed

None.
