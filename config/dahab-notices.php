<?php

/*
| Dashboard notices (the bell). Spec 021 writes the founder security alerts; spec 026 the work
| notices. Read work notices are deleted after this many days (D26-5); unread notices and the
| founder alerts are kept.
*/

return [
    'work_read_retention_days' => (int) env('DAHAB_WORK_NOTICE_RETENTION_DAYS', 30),
];
