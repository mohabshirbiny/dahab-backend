# Spec 025 — Compliance tools: case files and approved inspection messages

**Status**: DRAFT for the owner's confirmation · **Repos**: Backend, Dashboard · **Priority**: last; both stories are staff-only and have open content decisions · **Independent of** 020–024 (it only uses the audit log and the existing order and inspection data)

## Why

Two staff tools are described in the documents and were deferred:
- **Case files**: Part 2 §10 `POST /admin/case-files`, "assemble a case file for law enforcement (`case_file`), audited", permission *Build a full case file* (CEO, COO, Finance), a reason required. The table `case_file` exists (`order_id`, `requested_by`, `received_by`, `built_at`) with no code behind it.
- **Approved messages on an inspection result**: the Dashboard design's *Inspections* result page has *Message to the seller* and *Message to the buyer* with "nothing is sent until you approve it". Spec 014 deferred it; results notify the parties automatically today (spec 012).

## Story 1 — Build a case file

1. **Given** the permission, a reason and an order or a customer, **When** staff build a case file, **Then** the system assembles, read-only, what the documents allow **[OPEN D25-1: exact list]**: the order and its timeline, both parties' identity records and document views, the ledger entries of the order, the messages sent, the sessions and devices, the audit entries about them; the file is sealed with a manifest and a hash; the build is audited with the reason and who it was handed to (`received_by`).
2. Nothing in a case file is editable; every download is audited; the file expires from the system after a retention period **[OPEN D25-2]**.
3. Identity-document images are included only if the data-protection rules the lawyer gives allow it **[SIGN-OFF lawyer]**.

## Story 2 — Review the messages before they are sent

1. **Given** an inspection result, **When** staff open it, **Then** they see the two messages the system would send (to the seller and to the buyer), edit each in both languages, and approve them; nothing about the *result* is held back — only the free-text explanation is.
2. Until approval the parties see the order's new state with the standard sentence; after approval the edited text is added to the order timeline and sent by SMS, email and inbox.
3. The result itself stays immutable (spec 012); the message is a separate record with its editor and approver.
4. **[OPEN D25-3]** whether this applies to every result or only to those that need a buyer decision (a weight difference, a stone regrade, a refusal).

## Surfaces

| Repo | Change |
|---|---|
| Backend | `POST /dashboard/case-files` and `GET …/{id}` (with manifest), the assembler, a retention job; an `inspection_message` record with edit and approve actions, the held notification and its release, error codes, audit, Postman, OpenAPI |
| Dashboard | a *Build case file* action on the Customer file and the Order detail; the two message editors with an *Approve and send* step on the inspection result |
| Customer app | none (the messages appear through the existing order timeline and notifications) |

## Depends on / used by

Reads the audit log, the ledger, the document-view log and the sessions already built. Story 2 uses the notification templates of spec 019 for the standard sentences.

## Decisions needed from the owner

| ID | Question | Proposed |
|---|---|---|
| D25-1 | What exactly is in a case file? | Order, timeline, ledger, messages, parties' identity records, sessions and devices, audit entries |
| D25-2 | Retention and who may download | Held 90 days, downloadable only by the requester and the CEO, every access audited |
| D25-3 | Which inspection results need an approved message? | Only those that need a buyer decision or are refused |
| D25-4 | Lawyer: may identity-document images go into a case file? | Not until answered |

## Mock removed

None.
