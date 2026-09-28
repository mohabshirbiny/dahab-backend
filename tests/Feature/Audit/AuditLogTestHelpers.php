<?php

use Illuminate\Support\Facades\DB;

// Shared by the spec 006 audit log tests: write audit rows directly, with a
// chosen time, the way features record them.

if (! function_exists('auditRow')) {
    /**
     * @param  array<string, mixed>  $attributes
     */
    function auditRow(string $action, array $attributes = []): int
    {
        $row = array_merge([
            'actor_staff_id' => null,
            'actor_customer_id' => null,
            'action' => $action,
            'entity_type' => 'test',
            'entity_id' => null,
            'before_json' => null,
            'after_json' => ['outcome' => 'success'],
            'reason' => null,
            'ip_address' => '10.0.0.1',
            'device_fingerprint' => 'fp-test',
            'created_at' => now(),
        ], $attributes);

        foreach (['before_json', 'after_json'] as $json) {
            $row[$json] = $row[$json] === null ? null : json_encode($row[$json]);
        }

        return (int) DB::table('audit_log')->insertGetId($row, 'audit_id');
    }
}
