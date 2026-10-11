<?php

namespace App\Http\Resources\FounderSecurity;

use App\Models\Staff;
use App\Models\StaffDeviceFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardFounderDevices',
    description: 'Spec 021: a founder and the devices known for them (a sign-in from one is not held). Devices are shown by ref only.',
    required: ['founder', 'devices'],
    properties: [
        new OA\Property(property: 'founder', ref: '#/components/schemas/DashboardStaffRef'),
        new OA\Property(property: 'devices', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'device_ref', type: 'string', example: 'a1b2c3d4'),
            new OA\Property(property: 'first_seen_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'last_seen_at', type: 'string', format: 'date-time'),
        ], type: 'object')),
    ],
)]
class FounderDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Staff $founder */
        $founder = $this->resource;

        return [
            'founder' => DeviceApprovalResource::ref($founder),
            'devices' => $founder->deviceFingerprints->map(fn (StaffDeviceFingerprint $d) => [
                'device_ref' => substr($d->fingerprint_hash, 0, 8),
                'first_seen_at' => $d->first_seen_at->toIso8601String(),
                'last_seen_at' => $d->last_seen_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
