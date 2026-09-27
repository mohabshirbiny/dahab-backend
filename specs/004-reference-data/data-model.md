# Data Model: Reference Data

Tables verbatim from `docs/Database schema/01_schema_core.sql` §2, plus the additions marked **(004)** (research R1).

| Table | Columns | Constraints |
|---|---|---|
| `piece_category` (enum) | `gold`, `diamond`, `gold_with_diamond` | — |
| `karat` | `karat_code SMALLINT PK`, `purity_ratio NUMERIC(6,5)`, `is_enabled BOOL default true`, `sort_order SMALLINT` | `karat_purity_range (0,1]`; **(004)** `karat_code_range 1..24` |
| `piece_type` | `piece_type_id SMALLSERIAL PK`, `category`, `name_en`, `name_ar`, `typical_min_g`, `typical_max_g NUMERIC(10,3)`, `is_enabled` | `UNIQUE (category, name_en)`; **(004)** `piece_type_weight_order` |
| `branch` | `branch_id SMALLSERIAL PK`, `name_en`, `name_ar`, `address_en`, `address_ar`, `timezone default 'Africa/Cairo'`, `is_enabled` | — |
| `branch_hours` | `branch_id FK`, `dow 0..6`, `opens_at TIME`, `closes_at TIME` | `PK (branch_id, dow, opens_at)`, `branch_hours_order`; non-overlap in the Action |
| `branch_closure` | `closure_id SERIAL PK`, `branch_id FK NULL (= all)`, `closure_date DATE`, `reason_en`, `reason_ar` | `UNIQUE (branch_id, closure_date)`; the Action also refuses a duplicate all-branch date (NULLs are distinct in a unique key) |
| `staff` | `branch_id` **(004)** `REFERENCES branch(branch_id)` | — |

## Seeds (every environment)

**Karats** (code, purity, enabled, order):
- 24 (0.999, on, 1)
- 22 (0.916, off, 2)
- 21 (0.875, on, 3)
- 20 (0.833, off, 4)
- 18 (0.750, on, 5)

**Piece types**:
- gold: Ring, Earrings, Chain, Bangle, Pendant, Other
- diamond: Ring, Earrings, Pendant, Bridal set, Bracelet, Other
- gold_with_diamond: the same as diamond

Arabic names: خاتم، حلق، سلسلة، غويشة، دلاية، أخرى، طقم عروسة، أسورة. Typical weights for gold, taken from the Customer App's hints:
- Ring: 3–5 g
- Earrings: 3–6 g
- Chain: 8–15 g
- Bangle: 15–30 g

**Local only**:
- IGI Nasr City (Sunday–Thursday 10:00–18:00)
- IGI Mohandessin (Saturday–Thursday 11:00–19:00, closed Friday)
- All-branch holidays: 6 Oct, 7 Jan, 25 Apr (next occurrences)

## Permission codes (StaffPermission)

| Code | Group | Seed roles (+ ceo) |
|---|---|---|
| `reference.view` | Reference data | coo, finance, operations |
| `karats.toggle` | Reference data | finance |
| `karats.create` | Reference data | coo |
| `branches.manage` | Reference data | coo, operations |

## Audit events

| Event | Payload |
|---|---|
| `reference.karat.created` | karat fields |
| `reference.karat.toggled` | `{karat_code, is_enabled}`; before: previous value |
| `reference.branch.created` / `reference.branch.updated` | branch fields (before/after) |
| `reference.branch.hours_replaced` | before/after week |
| `reference.closure.added` / `reference.closure.removed` | the closure |
| `authz.staff.branch_changed` | `{from, to}` + reason; entity = staff |
