# Prompt for the next session (local) — Spec 019, first release

Paste everything below the line into a new Claude Code session opened in `D:\laragon\www\dahab-backend`.

---

Continue Spec 019 ("Controls and content") for the Dahab platform. Work in the three sibling repos: dahab-backend, dahab-dashboard, dahab-flutter. Implement the **first release only (tasks T001–T026)**, then stop and show me the result.

## 0. Read first
- `CLAUDE.md` (platform rules), `.specify/memory/constitution.md`.
- Everything in `specs/019-app-content/`: `spec.md` (clarified Q1–Q5, review decisions D1–D7), `plan.md`, `research.md` (R1–R17), `data-model.md`, `contracts/app-content-api.md`, `quickstart.md`, `tasks.md`, `checklists/requirements.md`.
- `specs/018-after-collection/spec.md` for the Stage 1 screens (free-relist card, relist form and its estimate line, the seller's "No Dahab fee" note, the rating screen — no invite card, FR-038).

## 1. Base and branches
- Backend `feature/app-content` contains one commit on top of `main` `28d058b` with the spec 019 artefacts. If it is not in your local clone, fetch it: `git fetch origin feature/app-content` (or copy `specs/019-app-content/` from the commit I give you).
- Dashboard `main` `e74282d`, Flutter `main` `3bf8f7c`. Create `feature/app-content` from `main` in each if it does not exist. If any `main` moved, tell me before starting.
- Do not push, merge, rebase or force anything unless I ask.

## 2. Scope of this session: first release (T001–T026) only
- Phase 1 Setup: T001 (databases), T002 (baseline), T003 (`docs/features/app-content.md`).
- Phase 2 Foundational: **T004–T006 first** (Flutter `TextKey`/`TextsController`/store, the Stage 1 key catalogue, the generated manifest copied to `database/data/app_text_keys.json`), then T007–T014 (migration `2026_10_12_000010_app_content.php` with only `content.edit`, schema docs, enum, models, audit events, setting placeholders with fingerprint, placeholder validator, Dashboard permission string).
- Phase 3 US1: T015–T021 (sync command, draft/discard/revert actions, publish, controller + `#[OA]` + Postman, tests incl. concurrency and idempotent replay, Dashboard App text page).
- Phase 4 US2: T022–T026 (public bundle with ETag `<bundle_version>-<settings fingerprint>`, tests, Flutter refresh on start/resume/every 15 min, Flutter tests, deploy note for `app-texts:sync`).
- Nothing from later phases: no legal, templates, FAQ, switches, market makers or people to watch. Seed only `content.edit`; add only the error codes, audit events and permission strings this release uses.

## 3. Decisions already made (do not re-open)
- Q1 readable stable keys by screen and purpose, one per sentence/label/button; code strings are defaults and fallback. Q2 draft → *Publish changes* publishes all pending drafts as one bundle. Q3 app caches the last bundle; refresh on start, on resume, every 15 minutes. Q4 `{placeholders}` from a fixed list per key plus setting placeholders. Q5 market makers: staff side only now.
- Revert to default = a default-marker version that removes the override (the bundle omits the key); restore = republish an earlier value. Keys whose code default changed since their override are flagged.
- The bundle serves only kinds `app` and `faq`, never notification templates; its ETag changes on a publish or on a change of any setting used by a placeholder.
- Every `down()` refuses while it would destroy data (published versions); history rows are immutable by trigger (DH017).
- Arabic written by Claude is AR-DRAFT and needs my approval; Stage 1 reuses the existing English and Arabic unchanged.

## 4. Rules
- Databases: only `dahab_wt019` (tests) and `dahab_wt019_dev` (seeded app), owned by `dahab`. If they don't exist, STOP and ask me to create them. Never run tests, `migrate:fresh` or seeders against the main `dahab` database. Pass `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` explicitly. Run the suite sequentially.
- Known failures on `main` (report, do not fix): 3 Invoices tests (demo issuer config), 2 Flutter withdraw tests.
- Time: a full backend run takes about 30 minutes — once for the baseline (T002) and once at the end of this release; targeted test files in between.
- Postman and `#[OA]` updated in the same task as each endpoint; `composer swagger:generate`.
- Git attribution (Golden rule 10): no Co-Authored-By trailer, no Claude-Session line, no "Generated with Claude Code", no claude.ai links, no model or tool name in any commit message, merge commit, PR title or body. Plain messages in the project style (`feat(app-content): …`, `test(app-content): …`, `docs(app-content): …`), author = the git user already configured on my machine. Check the full message before every commit. Commit only when I ask.
- If spec and code conflict, STOP and report. Never choose silently.

## 5. Open items that block nothing in this release (do not decide them)
- Finance sign-off on D3 (market-maker spread allocation, ledger, invoice, VAT) — blocks Phase 10B only.
- Lawyer on every legal text (terms v1, §5.5 proposal) — nothing is published by this spec.
- My approval of the AR-DRAFT strings (spec Appendix B).
- OD-2 referral; who may edit code/link templates (D7); alert routing beyond D5 (OI-1.3).

## 6. Finish
After T026: run the gates for what changed (Backend targeted + one full run, `./vendor/bin/pint --test`, `composer swagger:generate`; Dashboard `npm run type-check`, `npm run lint`, `npm run build`; Flutter `flutter analyze`, `flutter test`, `flutter build web --release`), then report in the Step 5 shape of `CLAUDE.md` and wait for me.
