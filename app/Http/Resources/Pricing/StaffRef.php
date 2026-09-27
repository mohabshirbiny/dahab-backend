<?php

namespace App\Http\Resources\Pricing;

use App\Models\Staff;

/** `{id, full_name}` of the staff member behind a pricing change, or null. */
final class StaffRef
{
    /** @return array{id: string, full_name: string}|null */
    public static function of(?Staff $staff): ?array
    {
        return $staff === null ? null : ['id' => $staff->staff_id, 'full_name' => $staff->full_name];
    }
}
