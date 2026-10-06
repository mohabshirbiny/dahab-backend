<?php

namespace App\Http\Resources\Customer;

use App\Models\Customer;
use App\Models\CustomerNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * One inbox item (spec 017 FR-031). `title`/`body` are in the reader's
 * language (the customer's preferred one; staff get English); both texts are
 * always included. Generic: later specs add types, never fields.
 *
 * @mixin CustomerNotification
 */
#[OA\Schema(
    schema: 'InboxItem',
    required: ['id', 'type', 'params', 'link', 'title', 'body', 'title_en', 'title_ar', 'body_en', 'body_ar', 'created_at', 'read_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', example: 'order.ready_to_collect', description: '<area>.<event>; open list, new types come with later specs'),
        new OA\Property(property: 'params', type: 'object', description: 'Small facts of the event (references, numbers); never codes'),
        new OA\Property(property: 'link', required: ['kind', 'id'], properties: [
            new OA\Property(property: 'kind', type: 'string', enum: ['order', 'listing', 'buy_request', 'wallet', 'withdrawal', 'payout_account', 'topup', 'invoice', 'credit_note', 'dispute', 'account', 'none']),
            new OA\Property(property: 'id', type: 'string', nullable: true, description: 'Null for wallet, account and none'),
        ], type: 'object'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'body', type: 'string'),
        new OA\Property(property: 'title_en', type: 'string'),
        new OA\Property(property: 'title_ar', type: 'string'),
        new OA\Property(property: 'body_en', type: 'string'),
        new OA\Property(property: 'body_ar', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class InboxItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $reader = $request->user('customer');
        $arabic = $reader instanceof Customer && $reader->preferred_lang === 'ar';

        return [
            'id' => $this->notification_id,
            'type' => $this->type,
            'params' => (object) $this->params,
            'link' => ['kind' => $this->link_kind->value, 'id' => $this->link_id],
            'title' => $this->title($arabic),
            'body' => $this->body($arabic),
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'body_en' => $this->body_en,
            'body_ar' => $this->body_ar,
            'created_at' => $this->created_at->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
        ];
    }
}
