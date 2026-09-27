<?php

namespace App\Support\Audit;

use App\Enums\AuditCategory;
use Carbon\CarbonImmutable;

/**
 * What the viewer asked for (spec 006 FR-003). Dates are Cairo calendar days;
 * the default period is the last 7 days, today included.
 */
final readonly class AuditFilters
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?AuditCategory $category = null,
        public ?string $actor = null,
        public ?string $action = null,
        public ?string $entityType = null,
        public ?string $entityId = null,
    ) {}

    /** @param  array<string, mixed>  $input  validated AuditFilterRequest data */
    public static function fromInput(array $input): self
    {
        $to = isset($input['to']) ? CarbonImmutable::parse($input['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = isset($input['from']) ? CarbonImmutable::parse($input['from'])->startOfDay() : $to->subDays(6)->startOfDay();

        return new self(
            from: $from,
            to: $to,
            category: isset($input['category']) ? AuditCategory::from($input['category']) : null,
            actor: $input['actor'] ?? null,
            action: $input['action'] ?? null,
            entityType: $input['entity_type'] ?? null,
            entityId: $input['entity_id'] ?? null,
        );
    }

    /** @return array<string, string|null> for the export record and the response meta */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'category' => $this->category?->value,
            'actor' => $this->actor,
            'action' => $this->action,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
        ];
    }
}
