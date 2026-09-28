<?php

namespace App\Http\Resources\Staff;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffCustomerSession',
    description: 'An open session of a customer (one token family). Ended sessions are deleted by sign-out and appear in History instead. No token values or abilities.',
    properties: [
        new OA\Property(property: 'session_id', type: 'string', format: 'uuid', description: 'The token family'),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'last_active_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'StaffCustomerDevice',
    description: 'A device the customer signed in from. device_ref is a short prefix of the recorded fingerprint hash, never the whole hash.',
    properties: [
        new OA\Property(property: 'device_ref', type: 'string', example: '3fa9c2e1b07d'),
        new OA\Property(property: 'first_seen_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'last_seen_at', type: 'string', format: 'date-time'),
    ],
)]
class CustomerSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'session_id' => $row->family_id,
            'started_at' => self::time($row->started_at),
            'last_active_at' => self::time($row->last_active_at),
            'expires_at' => self::time($row->expires_at),
        ];
    }

    /** personal_access_tokens stores times without a zone, in the app time zone. */
    private static function time(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
