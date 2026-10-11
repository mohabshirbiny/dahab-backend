<?php

namespace App\Enums;

/**
 * The kinds of Dashboard notice (`staff_notice.kind`). Spec 021 writes the founder alerts;
 * spec 024 adds its own kinds to the same table, channel and read endpoints.
 */
enum StaffNoticeKind: string
{
    /** Spec 021 research R11: a sensitive action on a founder account, or a founder security event. */
    case FOUNDER_ALERT = 'founder_alert';
}
