<?php

namespace App\Http\Resources\FounderSecurity;

use App\Actions\FounderSecurity\ListDeviceApprovalsAction;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardDeviceApproval',
    description: 'Spec 021: a founder sign-in from a new device, held until the other founder decides. The device is shown by its ref (the first 8 hex characters of the fingerprint hash); the device id and the poll ref are never returned.',
    required: ['id', 'founder', 'status', 'device_ref', 'requested_at', 'expires_at', 'can_decide'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'founder', ref: '#/components/schemas/DashboardStaffRef'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'refused', 'expired']),
        new OA\Property(property: 'platform', type: 'string', nullable: true, example: 'web'),
        new OA\Property(property: 'user_agent', type: 'string', nullable: true),
        new OA\Property(property: 'ip_address', type: 'string', nullable: true),
        new OA\Property(property: 'device_ref', type: 'string', example: 'a1b2c3d4'),
        new OA\Property(property: 'requested_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'decided_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'decision_note', type: 'string', nullable: true),
        new OA\Property(property: 'collected_at', type: 'string', format: 'date-time', nullable: true, description: 'When the held device collected its session (single use).'),
        new OA\Property(property: 'can_decide', type: 'boolean', description: 'Pending, not the viewer\'s own, and the viewer is a founder who is not frozen.'),
    ],
)]
class DeviceApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FounderDeviceApproval $a */
        $a = $this->resource;
        $viewer = $request->user();
        $viewer = $viewer instanceof Staff ? $viewer : null;
        $decider = $a->approved_at !== null ? $a->approver : ($a->refused_at !== null ? $a->refuser : null);

        return [
            'id' => $a->approval_id,
            'founder' => self::ref($a->staff),
            'status' => $a->status(),
            'platform' => $a->platform,
            'user_agent' => $a->user_agent,
            'ip_address' => $a->ip_address,
            'device_ref' => $a->deviceRef(),
            'requested_at' => $a->requested_at->toIso8601String(),
            'expires_at' => $a->expires_at->toIso8601String(),
            'decided_by' => self::ref($decider),
            'decided_at' => ($a->approved_at ?? $a->refused_at)?->toIso8601String(),
            'decision_note' => $a->decision_note,
            'collected_at' => $a->consumed_at?->toIso8601String(),
            'can_decide' => ListDeviceApprovalsAction::canDecide($a, $viewer, self::viewerFrozen($request, $viewer)),
        ];
    }

    /** @return array{id: string, full_name: string}|null */
    public static function ref(?Staff $staff): ?array
    {
        return $staff === null ? null : ['id' => $staff->staff_id, 'full_name' => $staff->full_name];
    }

    /** Read once per request, not once per row. */
    private static function viewerFrozen(Request $request, ?Staff $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if (! $request->attributes->has('founder_security.viewer_frozen')) {
            $request->attributes->set('founder_security.viewer_frozen', $viewer->activeFreeze()->exists());
        }

        return (bool) $request->attributes->get('founder_security.viewer_frozen');
    }
}
