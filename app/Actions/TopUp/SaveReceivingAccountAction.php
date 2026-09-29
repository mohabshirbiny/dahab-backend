<?php

namespace App\Actions\TopUp;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\TopUpMethod;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Add or change one of Dahab's receiving accounts (spec 009 US4, FR-002,
 * FR-003). Details of other methods are cleared so the per-method CHECK
 * always holds; the method itself never changes after creation. Every change
 * is audited with the full before/after, because wrong details send
 * customers' money to the wrong place. Accounts are never deleted.
 */
final class SaveReceivingAccountAction
{
    public const FIELDS = [
        'label', 'bank_name', 'account_holder', 'account_number', 'iban', 'instapay_address', 'wallet_number',
        'daily_limit', 'provider_fee_percent', 'customer_note', 'sort_order', 'is_active',
    ];

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(Staff $actor, TopUpMethod $method, array $data, ?RequestContext $ctx = null): ReceivingAccount
    {
        return DB::transaction(function () use ($actor, $method, $data, $ctx) {
            $account = new ReceivingAccount(['method' => $method]);
            $account->fill(self::normalise($method, array_intersect_key($data, array_flip(self::FIELDS))) + ['updated_by' => $actor->staff_id]);
            $account->save();

            // audit_log.entity_id is a UUID; the account's integer id travels in the payload (as branches do).
            $this->audit->execute(AuditEvent::RECEIVING_ACCOUNT_CREATED, 'success', self::snapshot($account->refresh()),
                'receiving_account', null, $ctx, actorStaffId: $actor->staff_id);

            return $account;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Staff $actor, int $accountId, array $data, ?RequestContext $ctx = null): ReceivingAccount
    {
        return DB::transaction(function () use ($actor, $accountId, $data, $ctx) {
            $account = ReceivingAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $before = self::snapshot($account);

            $merged = array_merge($account->only(self::FIELDS), array_intersect_key($data, array_flip(self::FIELDS)));
            $account->fill(self::normalise($account->method, $merged) + ['updated_by' => $actor->staff_id]);
            $account->save();

            $this->audit->execute(AuditEvent::RECEIVING_ACCOUNT_UPDATED, 'success', self::snapshot($account->refresh()),
                'receiving_account', null, $ctx, actorStaffId: $actor->staff_id, before: $before);

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalise(TopUpMethod $method, array $data): array
    {
        $own = $method->detailKeys();
        foreach (['bank_name', 'account_number', 'iban', 'instapay_address', 'wallet_number'] as $detail) {
            if (! in_array($detail, $own, true)) {
                $data[$detail] = null;
            }
        }

        return array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
    }

    /** @return array<string, mixed> */
    private static function snapshot(ReceivingAccount $account): array
    {
        return ['receiving_account_id' => $account->receiving_account_id, 'method' => $account->method->value] + array_map(
            fn ($v) => $v instanceof \BackedEnum ? $v->value : $v,
            $account->only(self::FIELDS),
        );
    }
}
