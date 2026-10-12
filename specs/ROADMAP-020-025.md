# Roadmap: specs 020–025

Built from the audit of 2026-10-11 (sections 1 and 3: the customer-app mock items and the Technical Spec items not built). External integrations (SMS, email, Tax Authority, Rapaport, IGI) and launch readiness are deliberately **not** here.

| Spec | Name | Repos | Depends on | Open decisions |
|---|---|---|---|---|
| **020** | Account recovery and staff access | Backend, Dashboard, App | — | D20-1…5 |
| **021** | Founder-account security | Backend, Dashboard | 020 | D21-1…4 |
| **022** | Change the price of a live piece | Backend, Dashboard (read), App | — | D22-1…3 |
| **023** | First-sale advance, customer-side codes, referral, and the *Promo codes* and *Market maker approvals* pages rebuilt to the design | Backend, Dashboard, App | spec 019 codes; touches 022 | D23-1…9 (Finance sign-off) — built, uncommitted |
| **024** | Notification preferences and operational alerts | Backend, Dashboard, App | shares OI-1.3 with 021; feeds from 023 | D24-1…4 |
| **025** | Compliance tools: case files, approved inspection messages | Backend, Dashboard | — | D25-1…4 |

## Coupling

```
020 ──► 021 ─┐
              ├─ shared open question: who is told, by which channel (OI-1.3)
023 ──► 024 ─┘
022 ──(price must not move after an advance)── 023
025 independent
```

- **020 → 021**: founder security needs staff accounts that can be created and disabled.
- **021 ↔ 024**: the founders' real-time notice and the pattern/cap alerts are one notification channel.
- **023 → 024**: the first-sale exposure and market-maker caps are alert sources.
- **022 ↔ 023**: once an advance was paid on a piece its price must not change.

## Suggested build order

020 → 022 (independent, quick) → 021 → 023 (after Finance) → 024 → 025.

## Mapping from the audit

| Audit item | Spec |
|---|---|
| Customer forgot password; staff forgot password; create/disable staff | 020 |
| Founder device approval and freeze; founder notices | 021 |
| Edit price of a live piece (`R.editprice`, *Change fee*) | 022 |
| Promo card, `first_sale` codes, `MockContentRepository.promo`, Invite a friend; the Promo codes and Market maker pages vs the design | 023 |
| Notification settings screen; pattern/cap alerts | 024 |
| Case files; staff approval of messages | 025 |
| Prototype branch picker, signed-out sample account | already removed in the app (uncommitted) |
