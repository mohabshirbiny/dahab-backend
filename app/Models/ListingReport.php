<?php

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\ReportState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's report on someone else's piece (spec 017 FR-052, FR-053):
 * `RPT-n`, open until staff dismiss it or take the piece down, or the piece
 * leaves the market (`listing_gone`). The seller never sees the reporter.
 * Leaves `open` once (guard DH015).
 *
 * @property string $report_id
 * @property int $report_no
 * @property string $listing_id
 * @property string $reporter_id
 * @property ReportReason $reason
 * @property string|null $note
 * @property ReportState $state
 * @property string|null $handled_by
 * @property CarbonImmutable|null $handled_at
 * @property string|null $staff_note
 * @property CarbonImmutable $created_at
 */
class ListingReport extends Model
{
    use HasUuids;

    protected $table = 'listing_report';

    protected $primaryKey = 'report_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['report_id', 'report_no'];

    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected function casts(): array
    {
        return [
            'report_no' => 'integer',
            'reason' => ReportReason::class,
            'state' => ReportState::class,
            'handled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function reference(): string
    {
        return 'RPT-'.$this->report_no;
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id', 'listing_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'reporter_id', 'customer_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'handled_by', 'staff_id');
    }
}
