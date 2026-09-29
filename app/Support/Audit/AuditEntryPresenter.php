<?php

namespace App\Support\Audit;

use App\Enums\AuditCategory;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use App\Support\SystemActor;
use Illuminate\Support\Collection;

/**
 * Turns stored audit rows into what people read (spec 006 FR-002): who, the
 * plain label, a short subject, and short before/after summaries. Names are
 * loaded once per page, never per row. Customers appear by display_ref only.
 */
final class AuditEntryPresenter
{
    /** @var array<string, string> */
    private array $staffNames = [];

    /** @var array<string, string> */
    private array $customerRefs = [];

    /** @var array<int, string> */
    private array $branchNames = [];

    /** @var array<string, string> */
    private array $roleNames = [];

    /** @var array<string, string> document id → customer display_ref */
    private array $documentRefs = [];

    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return list<array<string, mixed>>
     */
    public function present(Collection $rows, bool $details = false): array
    {
        $this->load($rows);

        return $rows->map(fn (AuditLog $row) => $this->row($row, $details))->values()->all();
    }

    /** @return array<string, mixed> */
    private function row(AuditLog $row, bool $details): array
    {
        $event = AuditEvent::tryFrom($row->action);
        $before = is_array($row->before_json) ? $row->before_json : [];
        $after = is_array($row->after_json) ? $row->after_json : [];
        [$beforeSummary, $afterSummary] = $this->summaries($event, $before, $after);

        $entry = [
            'id' => $row->audit_id,
            'at' => $row->created_at?->toIso8601String(),
            'actor' => $this->actor($row),
            'action' => $row->action,
            'label' => $event?->label() ?? $row->action,
            'category' => ($event?->category() ?? AuditCategory::SYSTEM)->value,
            'subject' => $this->subject($row, $event, $before, $after),
            'before_summary' => $beforeSummary,
            'after_summary' => $afterSummary,
            'reason' => $row->reason,
            'ip' => $row->ip_address,
            'outcome' => is_string($after['outcome'] ?? null) ? $after['outcome'] : null,
            'entity' => ['type' => $row->entity_type, 'id' => $row->entity_id],
        ];

        if ($details) {
            $entry['before'] = $row->before_json;
            $entry['after'] = $row->after_json;
            $entry['device_fingerprint'] = $row->device_fingerprint;
        }

        return $entry;
    }

    /** @return array<string, string|null> */
    private function actor(AuditLog $row): array
    {
        if ($row->actor_staff_id !== null && $row->actor_staff_id === SystemActor::id()) {
            return ['type' => 'system'];
        }
        if ($row->actor_staff_id !== null) {
            return ['type' => 'staff', 'id' => $row->actor_staff_id, 'name' => $this->staffNames[$row->actor_staff_id] ?? '—'];
        }

        return ['type' => 'customer', 'id' => $row->actor_customer_id, 'ref' => $this->customerRefs[$row->actor_customer_id] ?? '—'];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function subject(AuditLog $row, ?AuditEvent $event, array $before, array $after): ?string
    {
        $pick = fn (string $key) => $after[$key] ?? $before[$key] ?? null;

        return match (true) {
            in_array($event, [AuditEvent::RLS_SYSTEM_ELEVATION, AuditEvent::RLS_MAINTENANCE_ELEVATION], true) => $pick('command') ?? $pick('job'),
            $event === AuditEvent::SETTING_CHANGED => (string) $pick('key'),
            $event === AuditEvent::ADJUSTMENT_CHANGED => $pick('karat_code').'K '.$pick('side').' side',
            in_array($event, [AuditEvent::MANUAL_PRICE_ENTERED, AuditEvent::MANUAL_PRICE_CONFIRMED], true) => '24K bid / ask',
            $pick('karat_code') !== null => $pick('karat_code').'K',
            $row->entity_type === 'branch_closure' => trim(($pick('closure_date') ?? '').' · '.($pick('branch_id') === null ? 'All branches' : ($this->branchNames[(int) $pick('branch_id')] ?? 'Branch '.$pick('branch_id'))), ' ·'),
            $row->entity_type === 'branch' && $pick('branch_id') !== null => $this->branchNames[(int) $pick('branch_id')] ?? 'Branch '.$pick('branch_id'),
            $pick('role') !== null => $this->roleNames[(string) $pick('role')] ?? (string) $pick('role'),
            // Spec 009: the top-up number and the customer; the receiving account's label.
            $row->entity_type === 'topup' => trim(($pick('number') ?? '').' · '.($pick('customer_ref') ?? ''), ' ·') ?: null,
            $row->entity_type === 'receiving_account' => $pick('label'),
            $row->entity_type === 'staff' && $row->entity_id !== null => $this->staffNames[$row->entity_id] ?? null,
            $row->entity_type === 'customer' && $row->entity_id !== null => $this->customerRefs[$row->entity_id] ?? null,
            $row->entity_id !== null && isset($this->documentRefs[$row->entity_id]) => $this->documentRefs[$row->entity_id],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: ?string, 1: ?string}
     */
    private function summaries(?AuditEvent $event, array $before, array $after): array
    {
        $onOff = fn ($v) => $v === null ? null : ($v ? 'On' : 'Off');
        $adjustment = fn ($a) => is_array($a) ? self::number((string) ($a['value'] ?? '')).(($a['kind'] ?? '') === 'percent' ? ' %' : ' EGP') : null;
        $list = fn ($v) => is_array($v) ? ($v === [] ? 'None' : implode(', ', $v)) : null;

        return match ($event) {
            AuditEvent::SETTING_CHANGED => [self::scalar($before['old'] ?? null), self::scalar($after['new'] ?? null)],
            AuditEvent::KARAT_TOGGLED => [$onOff($before['is_enabled'] ?? null), $onOff($after['is_enabled'] ?? null)],
            AuditEvent::ADJUSTMENT_CHANGED => [$adjustment($before), $adjustment($after['new'] ?? null)],
            AuditEvent::MANUAL_PRICE_ENTERED => [
                isset($before['bid_24k']) ? self::number($before['bid_24k']).' / '.self::number($before['ask_24k']) : null,
                isset($after['bid_24k']) ? self::number($after['bid_24k']).' / '.self::number($after['ask_24k']).(($after['status'] ?? '') === 'pending' ? ' (waiting)' : '') : null,
            ],
            AuditEvent::STAFF_ROLES_CHANGED => [$list($before['roles'] ?? null), $list($after['roles'] ?? null)],
            AuditEvent::STAFF_BRANCH_CHANGED => [$this->branchLabel($before['branch_id'] ?? null), $this->branchLabel($after['branch_id'] ?? null)],
            AuditEvent::ROLE_PERMISSIONS_CHANGED => [
                isset($before['permissions']) ? count($before['permissions']).' permissions' : null,
                self::changes($after['added'] ?? [], $after['removed'] ?? []),
            ],
            AuditEvent::MANUAL_PRICE_CONFIRMED => [null, 'Now live'],
            AuditEvent::RLS_SYSTEM_ELEVATION, AuditEvent::RLS_MAINTENANCE_ELEVATION => [null, null],
            AuditEvent::IDENTITY_DOCUMENT_VIEWED => [null, isset($after['side']) ? ucfirst((string) $after['side']).' side' : null],
            AuditEvent::CUSTOMER_REGISTRATION_SUBMITTED, AuditEvent::IDENTITY_DOCUMENT_SUBMITTED,
            AuditEvent::IDENTITY_DOCUMENT_RESUBMITTED => [null, self::words($after['doc_kind'] ?? null)],
            AuditEvent::CUSTOMER_VERIFICATION_DETAILS_VIEWED => [null, self::words($after['status'] ?? null)],
            AuditEvent::LEDGER_STATEMENT_VIEWED, AuditEvent::LEDGER_STATEMENT_EXPORTED => [null, trim(self::words($after['view'] ?? null).' · '.($after['from'] ?? '').' to '.($after['to'] ?? ''), ' ·')],
            AuditEvent::TOPUP_MATCHED, AuditEvent::TOPUP_CREDITED_BY_HAND => [
                self::words($before['status'] ?? null),
                isset($after['credited_amount']) ? 'Credited '.self::number($after['credited_amount']).' EGP'.(($after['customer_status'] ?? null) === 'suspended' ? ' (customer suspended)' : '') : null,
            ],
            AuditEvent::TOPUP_HELD, AuditEvent::TOPUP_UNHELD => [self::words($before['status'] ?? null), self::words($after['status'] ?? null)],
            AuditEvent::TOPUP_REJECTED => [self::words($before['status'] ?? null), trim('Rejected: '.(self::words($after['reject_reason'] ?? null) ?? ''), ': ')],
            AuditEvent::TOPUP_LIST_EXPORTED => [null, isset($after['rows']) ? $after['rows'].' rows' : null],
            AuditEvent::CUSTOMER_VERIFICATION_APPROVED, AuditEvent::CUSTOMER_VERIFICATION_REJECTED,
            AuditEvent::IDENTITY_DOCUMENT_APPROVED, AuditEvent::IDENTITY_DOCUMENT_REJECTED,
            AuditEvent::IDENTITY_DOCUMENT_RESUBMISSION_REQUESTED => [
                self::words($before['status'] ?? null),
                trim(self::words($after['decision'] ?? $after['status'] ?? null).(empty($after['reasons']) ? '' : ': '.implode(', ', array_map([self::class, 'words'], (array) $after['reasons']))), ': ') ?: null,
            ],
            default => [self::compact($before), self::compact(array_diff_key($after, ['outcome' => true]))],
        };
    }

    private function branchLabel(mixed $branchId): string
    {
        return $branchId === null ? 'No branch' : ($this->branchNames[(int) $branchId] ?? 'Branch '.$branchId);
    }

    /** @param  Collection<int, AuditLog>  $rows */
    private function load(Collection $rows): void
    {
        $values = fn (string $key) => $rows->flatMap(fn (AuditLog $r) => [
            ($r->before_json ?? [])[$key] ?? null, ($r->after_json ?? [])[$key] ?? null,
        ])->filter(fn ($v) => $v !== null && ! is_array($v))->unique()->values();

        $staffIds = $rows->pluck('actor_staff_id')
            ->merge($rows->where('entity_type', 'staff')->pluck('entity_id'))
            ->filter()->unique()->values();
        $this->staffNames = Staff::query()->whereIn('staff_id', $staffIds)->pluck('full_name', 'staff_id')->all();

        $customerIds = $rows->pluck('actor_customer_id')
            ->merge($rows->where('entity_type', 'customer')->pluck('entity_id'))
            ->filter()->unique()->values();

        $documentIds = $rows->whereNotIn('entity_type', ['staff', 'customer', 'topup', 'receiving_account'])->pluck('entity_id')->filter()->unique()->values();
        $documents = IdentityDocument::query()->whereIn('document_id', $documentIds)->pluck('customer_id', 'document_id');

        $this->customerRefs = Customer::query()->whereIn('customer_id', $customerIds->merge($documents->values())->unique())
            ->pluck('display_ref', 'customer_id')->all();
        $this->documentRefs = $documents->map(fn ($customerId) => $this->customerRefs[$customerId] ?? null)->filter()->all();

        $this->branchNames = Branch::query()->whereIn('branch_id', $values('branch_id')->map(fn ($v) => (int) $v))->pluck('name_en', 'branch_id')->all();
        $this->roleNames = StaffRoleModel::query()->whereIn('name', $values('role'))->pluck('display_name', 'name')->all();
    }

    private static function scalar(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_numeric($value) => self::number((string) $value),
            is_scalar($value) => (string) $value,
            default => self::compact($value),
        };
    }

    /** "20.0000" → "20", "-15.5000" → "−15.5" */
    private static function number(mixed $value): string
    {
        $v = (string) $value;
        $v = str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;

        return str_starts_with($v, '-') ? '−'.substr($v, 1) : $v;
    }

    /**
     * @param  list<string>  $added
     * @param  list<string>  $removed
     */
    private static function changes(array $added, array $removed): ?string
    {
        $parts = array_merge(
            array_map(fn ($p) => '+'.$p, $added),
            array_map(fn ($p) => '−'.$p, $removed),
        );

        return $parts === [] ? null : mb_strimwidth(implode(', ', $parts), 0, 80, '…');
    }

    /** "card_cut_off" → "Card cut off" */
    private static function words(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? ucfirst(str_replace('_', ' ', $value)) : null;
    }

    /**
     * Personal fields never appear in a summary; the entry's details still show
     * everything as recorded, to those allowed to open it (spec 006 Assumptions).
     */
    private const PERSONAL_KEYS = ['phone', 'email', 'full_name', 'name', 'national_id', 'id_number', 'passport_number', 'address', 'date_of_birth', 'customer_id'];

    private static function compact(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = array_diff_key($value, array_flip(self::PERSONAL_KEYS));
        }
        if ($value === null || $value === []) {
            return null;
        }

        return mb_strimwidth((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 80, '…');
    }
}
