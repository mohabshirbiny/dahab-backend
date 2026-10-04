<?php

namespace App\Models;

use App\Enums\ExtensionRequestReason;
use App\Enums\ExtensionRequestState;
use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seller's request for more time to bring the piece (spec 014 US4; schema 04
 * §10). Staff accept with 6/12/24/48 working hours (an extension written by
 * the spec 012 extend) or refuse; it lapses when the order moves on. Readable
 * by its seller and by staff. Guard DH010.
 *
 * @property string $request_id
 * @property string $order_id
 * @property string $seller_id
 * @property ExtensionRequestReason $reason
 * @property string $detail
 * @property CarbonImmutable $deadline_at_request
 * @property ExtensionRequestState $state
 * @property CarbonImmutable $requested_at
 * @property string|null $answered_by
 * @property CarbonImmutable|null $answered_at
 * @property string|null $answer_note
 * @property int|null $hours_granted
 * @property string|null $extension_id
 */
class OrderExtensionRequest extends Model
{
    use BelongsToOrder;

    protected $table = 'order_extension_request';

    protected $primaryKey = 'request_id';

    protected function casts(): array
    {
        return [
            'reason' => ExtensionRequestReason::class,
            'state' => ExtensionRequestState::class,
            'deadline_at_request' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
            'hours_granted' => 'integer',
        ];
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'answered_by', 'staff_id');
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(OrderDeadlineExtension::class, 'extension_id', 'extension_id');
    }
}
