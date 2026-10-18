# Implementation Plan: Staff work notices

**Branch**: `feature/staff-work-notices` (Backend, Dashboard) | **Date**: 2026-10-18 | **Spec**: [spec.md](spec.md)

## Summary

Nine customer actions (E1–E9) write a grouped, unread Dashboard notice to the staff who work that queue, in the same request right after commit — no queue worker needed. The existing `staff_notice` table gains a group key and a count; the bell API keeps its shape and gains `count`. A daily job deletes read work notices after 30 days. Spec 021's founder alerts write their Dashboard notice on the `sync` connection too. Details: [research.md](research.md), [data-model.md](data-model.md), [contracts/notices.md](contracts/notices.md).

## Technical Context

**Language/Version**: PHP 8.3 / Laravel 12 (Backend); TypeScript, Vue 3 (Dashboard)
**Primary Dependencies**: Spatie Permission (recipients), l5-swagger, Pest 3
**Storage**: PostgreSQL 16 — `staff_notice` altered (no RLS on staff tables)
**Testing**: Pest feature tests through the HTTP boundary (targeted, dedicated test DB)
**Target Platform**: Linux server (production), Laragon (local)
**Project Type**: web service + SPA
**Performance Goals**: a few inserts per customer event; no noticeable change to the request
**Constraints**: works with no queue worker (FR-004); a notice failure never fails the request
**Scale/Scope**: tens of staff; hundreds of events a day

## Constitution Check

| Principle | Check |
|---|---|
| I. Named actor on every state change | Notices are derived messages, like the customer inbox and spec 021's notices — not audited business state. The customer's action keeps its own audit. ✅ |
| II. Staff authorization by permission data | Recipients are chosen by the queue's permission (and branch), read from the Dashboard-managed role data. No new permission. ✅ |
| III. Docs are the source of truth | Owner decisions D26-1…5 recorded in the spec; CLAUDE.md "Current state" updated. ✅ |
| IV. Foundation before modules | Builds on spec 021's table/channel/endpoints. ✅ |
| V. Test the boundary | Pest feature tests drive the customer endpoints and read `GET /dashboard/notices`. ✅ |

No violations.

## Project Structure

```text
specs/026-staff-work-notices/  plan.md research.md data-model.md quickstart.md contracts/notices.md tasks.md

Backend
database/migrations/2026_10_18_000010_staff_work_notices.php   group_key, item_count, partial unique index
app/Enums/StaffNoticeKind.php                                   + nine work kinds (permission, branch scope, texts)
app/Support/Notices/WorkNotices.php                             record(): afterCommit → recipients → grouped upsert

app/Actions/... (9 hooks)                                       one call each, inside the existing transaction
app/Notifications/FounderSecurityNotification.php               viaConnections: staff_notice on sync
app/Console/Commands/PruneStaffNotices.php + routes/console.php   daily prune
config/dahab-notices.php                                        retention days
app/Http/Resources/Staff/StaffNoticeResource.php                + count (+ #[OA])
tests/Feature/Notices/StaffWorkNoticesTest.php
postman/ (notices requests: description of the new kinds/count)

Dashboard
src/types/notices.ts, src/services/notices*.ts                  + count
src/components/app/NoticesMenu.vue                              empty-state text
```

## Complexity Tracking

None.
