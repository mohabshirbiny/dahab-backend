# Spec 024 — Notification preferences and operational alerts

**Status**: DRAFT for the owner's confirmation · **Repos**: Backend, Dashboard, Customer app · **Priority**: after 020 and 021 (they share one open question) · **Depends on**: answer to OI-1.3

## Why

Two separate gaps share one missing decision, *who is told, and how*:
- The customer app's notification settings show three fixed "Always on" rows; there is nothing a customer may change.
- The "pattern and cap flag" job (Part 2 §11) produces no alert, because who receives it and through which channel is unanswered (OI-1.3 / OI-2.2). Part 1 §8 also asks for a real-time notice of sensitive actions to both founders (spec 021 Story 3).

## What exists today

- Customer messages are templates (spec 019), sent by SMS, email when there is one, and the in-app inbox (spec 017). Codes and confirmation links never reach the inbox.
- People to watch is a list staff open (spec 019); nothing is pushed.
- Dashboard banners exist only for *Stop everything* (spec 019).

## Story 1 — Customer preferences (conditional on D24-1)

Mandatory notices stay on by definition (a buyer's request, deadline reminders, money, security). A preference screen is built **only** for messages the owner defines as optional (for example marketing, tips, new-feature notices, price alerts). **If none exist, no switch is built**: the screen stays as an honest list of what is always sent, loses its red flag, and says so. Preferences are per channel (SMS, email, in-app) and per category; a customer's choice is stored on the account and honoured by the notification channel layer; an optional message to a customer with the channel off is simply not sent, never queued.

## Story 2 — Staff alerts for patterns and caps

1. The existing counts job raises an **alert** (not a verdict) when a customer crosses `flag.pattern_txn_threshold`, when a market-maker code reaches or nears its monthly cap, or when a first-sale advance exposure crosses a ceiling (spec 023).
2. Each alert goes to the holders of `people_to_watch.view` (and, for caps, `promo.manage`) by the channel chosen in D24-2; it names the person or code, the figure and the period, and links to People to watch or the code's use log. It never suspends anyone (Part 3 §9.3).
3. Alerts are de-duplicated per person and period, and every alert and dismissal is audited. A Dashboard *Alerts* list shows open ones, who acknowledged them and when.

## Story 3 — Sensitive-action notices to the founders

Delivered here as the shared channel for spec 021 Story 3: one notification service with channels (email, Dashboard notice, optionally SMS), recipients by permission or founder flag, and templates editable in App text.

## Surfaces

| Repo | Change |
|---|---|
| Backend | preference storage and the channel-layer check; the alert job and an `alert` table with acknowledgement; a recipient/channel configuration; audit events; Postman; OpenAPI |
| Dashboard | an *Alerts* page and a bell count in the top bar; acknowledgement; (maybe) a recipient setting |
| Customer app | the notification settings screen: honest list or real switches per D24-1 |

## Depends on / used by

Spec 021 Story 3 uses the same channel. Spec 023's exposure alert uses Story 2. Independent of 022.

## Decisions needed from the owner

| ID | Question | Proposed |
|---|---|---|
| D24-1 | Which customer messages are optional? | None today: keep the screen honest, build switches when a first optional message exists |
| D24-2 | Who receives pattern and cap alerts, and how? | A daily digest email to `people_to_watch.view` holders plus a Dashboard alert; immediate Dashboard alert when a market-maker code hits its cap |
| D24-3 | What is a "cap" alert (market-maker cap, first-sale ceiling, compensation caps)? | The first two; compensation already refuses at the cap |
| D24-4 | Should an alert ever block anything? | Never |

## Mock removed

The fixed "Always on" rows lose their implied toggles only if switches are built; otherwise nothing is removed.
