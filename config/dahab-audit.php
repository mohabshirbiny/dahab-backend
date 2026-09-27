<?php

return [
    // Spec 006 FR-009: the most entries one audit log export holds.
    'export_max_rows' => (int) env('DAHAB_AUDIT_EXPORT_MAX_ROWS', 50000),
];
