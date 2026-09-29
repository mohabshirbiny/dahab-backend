<?php

namespace App\Support;

use App\Enums\TopUpStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The Incoming transfers filters (spec 009 FR-015, FR-006): statuses, a
 * Cairo-day range on the submission time, and a search by reference (with
 * or without `DAHAB-`), phone or name. Shared by the list and the export.
 */
final readonly class TopUpListQuery
{
    /** @param  list<TopUpStatus>  $statuses */
    public function __construct(
        public array $statuses,
        public string $from,
        public string $to,
        public ?string $q,
    ) {}

    public function apply(Builder $query): Builder
    {
        $tz = (string) config('app.timezone');

        $query->whereIn('status', array_map(fn (TopUpStatus $s) => $s->value, $this->statuses))
            ->where('submitted_at', '>=', Carbon::parse($this->from, $tz)->startOfDay())
            ->where('submitted_at', '<', Carbon::parse($this->to, $tz)->addDay()->startOfDay());

        if ($this->q !== null && $this->q !== '') {
            $ref = TopUpReference::normalise($this->q);
            $phone = (string) preg_replace('/\s+/', '', $this->q);
            $name = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->q)).'%';

            $query->whereHas('customer', fn (Builder $c) => $c
                ->where('display_ref', $ref)
                ->orWhere('phone', $phone)
                ->orWhere('full_name', 'ILIKE', $name));
        }

        return $query;
    }

    /** @return array<string, mixed> for the export's audit row */
    public function toArray(): array
    {
        return [
            'status' => array_map(fn (TopUpStatus $s) => $s->value, $this->statuses),
            'from' => $this->from,
            'to' => $this->to,
            'q' => $this->q,
        ];
    }
}
