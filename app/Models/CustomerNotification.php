<?php

namespace App\Models;

use App\Enums\InboxLinkKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of a customer's in-app inbox (spec 017 FR-030–FR-032): written by
 * the `inbox` notification channel next to SMS and email, with both texts,
 * a type, its parameters and what it opens. Only `read_at` ever changes
 * (guard DH014); the customer reads their own (forced RLS).
 *
 * @property string $notification_id
 * @property string $customer_id
 * @property string $type
 * @property array<string, mixed> $params
 * @property InboxLinkKind $link_kind
 * @property string|null $link_id
 * @property string $title_en
 * @property string $title_ar
 * @property string $body_en
 * @property string $body_ar
 * @property string $dedupe_key
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $read_at
 */
class CustomerNotification extends Model
{
    use HasUuids;

    protected $table = 'customer_notification';

    protected $primaryKey = 'notification_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'link_kind' => InboxLinkKind::class,
            'created_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function title(bool $arabic): string
    {
        return $arabic ? $this->title_ar : $this->title_en;
    }

    public function body(bool $arabic): string
    {
        return $arabic ? $this->body_ar : $this->body_en;
    }
}
