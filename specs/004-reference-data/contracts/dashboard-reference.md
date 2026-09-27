# Contract: Dashboard reference-data endpoints (planning artefact — code and `#[OA]` win)

All endpoints are under `/api/v1/dashboard`, with guard `auth:staff`, ability `staff:access`, `staff.standing`, and security scheme `dashboardBearer`. The envelope is `{data}`; errors are `{message, code, errors?}`.

## Karats
- `GET /karats` (`reference.view`) → `data: Karat[]`, ordered by `sort_order`
  - `Karat = { code: int, purity: "0.87500", is_enabled: bool, sort_order: int }`
- `POST /karats` (`karats.create`) `{ code: 1..24, purity: decimal (0,1] max 5 places, sort_order?: int }` → `201 Karat`, created off
  - `422 validation_failed`: duplicate code, out of range
- `POST /karats/{code}/toggle` (`karats.toggle`) `{ enabled: bool }` → `200 Karat` · `404 not_found`

## Branches
- `GET /branches` (`reference.view`) → `data: Branch[]`
  - `Branch = { id, name_en, name_ar, address_en, address_ar, timezone, is_enabled, hours: Hour[] }`
  - `Hour = { dow: 0..6, opens_at: "10:00", closes_at: "18:00" }`, sorted by dow then opens_at
- `POST /branches` (`branches.manage`) `{ name_en, name_ar, address_en, address_ar, timezone?, is_enabled?, hours: Hour[] }` → `201 Branch`
- `PATCH /branches/{id}` (`branches.manage`) any of the fields; `hours`, when present, replaces the whole week → `200 Branch`
  - `422 validation_failed`: `hours.N` messages for order/overlap; the timezone must be a valid IANA name

## Closures
- `GET /branch-closures` (`reference.view`) → `data: Closure[]`, date ascending
  - `Closure = { id, branch_id: int|null, closure_date: "YYYY-MM-DD", reason_en, reason_ar }`
- `POST /branch-closures` (`branches.manage`) `{ branch_id: int|null, closure_date, reason_en?, reason_ar? }` → `201 Closure`
  - `409 closure_exists`: same date + scope
  - `422`: the date is in the past
- `DELETE /branch-closures/{id}` (`branches.manage`) → `204`
  - `409 closure_in_past`: the date is today or earlier

## Staff branch
- `PUT /staff/{staff}/branch` (`roles.manage`) `{ branch_id: int|null, reason }` → `200 StaffMember`
  - `403 escalation_denied`: on yourself
  - `422 reason_required`
  - `422 validation_failed`: a disabled or unknown branch
  - `404`: the system actor
