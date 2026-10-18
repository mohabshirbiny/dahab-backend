# Tasks — Spec 026 Staff work notices

## Phase 1 — Foundation (Backend)

- [x] T001 Migration `database/migrations/2026_10_18_000010_staff_work_notices.php`: `group_key`, `item_count` (CHECK ≥ 1), partial unique index `uq_staff_notice_open_group`; reversible
- [x] T002 `app/Enums/StaffNoticeKind.php`: nine work kinds with `permission()`, `branchScoped()`, `title(int)`, `body(int, ?ref)`, `queueLink()`
- [x] T003 `app/Support/Notices/WorkNotices.php`: `record()` → afterCommit → recipients (permission, active, not system, not frozen, branch) → grouped upsert with count guard; failures logged, never thrown
- [x] T004 `app/Models/StaffNotice.php` casts/fillable + docblock; `StaffNoticeResource` + `count` and `#[OA]`

## Phase 2 — US1 events (Backend)

- [x] T005 [P] E1 `SubmitIdentityDocumentAction`, `SubmitCustomerRegistrationAction`
- [x] T006 [P] E2 `SubmitTopUpNoticeAction`
- [x] T007 [P] E3 `SubmitListingAction`
- [x] T008 [P] E4 `AcceptBuyRequestAction` (branch)
- [x] T009 [P] E5 `OpenDisputeAction`
- [x] T010 [P] E6 `SubmitWithdrawalAction`
- [x] T011 [P] E7 `AddPayoutAccountAction`
- [x] T012 [P] E8 `RequestMoreTimeAction` (branch)
- [x] T013 [P] E9 `ListingReportsAction::report`

## Phase 3 — US3 / US4

- [x] T014 `FounderSecurityNotification::viaConnections()` → staff notice on `sync`
- [x] T015 `config/dahab-notices.php`, `PruneStaffNotices` (`staff-notices:prune`), daily schedule in `routes/console.php`

## Phase 4 — Tests and contract

- [x] T016 `tests/Feature/Notices/StaffWorkNoticesTest.php`: recipients per event, branch scope, disabled/frozen/system skipped, grouping + restart after read, link per count, rollback writes nothing, prune
- [x] T017 Run spec 021 notice tests + touched-action tests (targeted)
- [x] T018 `composer swagger:generate`; Postman notices requests' descriptions; `./vendor/bin/pint`
- [x] T019 Docs: `docs/features/staff-work-notices.md` (incl. the deployment note), CLAUDE.md "Current state"

## Phase 5 — Dashboard

- [x] T020 `src/types/notices.ts` + service mapping: `count`
- [x] T021 `NoticesMenu.vue` empty-state text
- [x] T022 `npm run type-check`, `npm run lint`, `npm run build`
