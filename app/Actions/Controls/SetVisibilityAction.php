<?php

namespace App\Actions\Controls;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\SettingGroup;
use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\SettingHistory;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Show or hide one of the four items under "What customers can see" (spec 019 US7, FR-073).
 * The value is the setting's, so the change is in the setting history and the audit log; it
 * never touches pricing. Setting a value it already has changes nothing.
 */
final class SetVisibilityAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, SettingKey $key, bool $shown, ?RequestContext $ctx = null): Setting
    {
        abort_unless($key->group() === SettingGroup::VISIBILITY, 404);

        return DB::transaction(function () use ($actor, $key, $shown, $ctx) {
            $setting = Setting::query()->lockForUpdate()->findOrFail($key->value);
            $old = (bool) $setting->value_bool;

            if ($old === $shown) {
                return $setting;
            }

            $setting->forceFill(['value_bool' => $shown, 'updated_by' => $actor->staff_id, 'updated_at' => now()])->save();

            SettingHistory::query()->create([
                'setting_key' => $key->value,
                'old_bool' => $old,
                'new_bool' => $shown,
                'changed_by' => $actor->staff_id,
                'reason' => 'Changed from Switches',
            ]);

            $this->audit->execute(
                AuditEvent::VISIBILITY_CHANGED,
                'success',
                ['key' => $key->visibilityName(), 'shown' => $shown],
                entityType: 'setting',
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
                before: ['key' => $key->visibilityName(), 'shown' => $old],
            );

            return $setting->refresh();
        });
    }
}
