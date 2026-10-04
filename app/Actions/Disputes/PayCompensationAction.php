<?php

namespace App\Actions\Disputes;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\AuditEvent;
use App\Enums\CompensationReason;
use App\Enums\CustomerStatus;
use App\Enums\LedgerEventKind;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Compensation;
use App\Models\Customer;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\Disputes\CompensationCaps;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Pricing\Money;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Pay compensation to a customer's wallet (spec 014 FR-015, research R8; Part
 * 2 §9) — inside a dispute resolution, or since spec 015 from the
 * Compensation page with no dispute (and optionally an order). One balanced
 * `compensation` entry — `external_equity` to the customer's available account
 * — through the money service, the payer as actor, and its `compensation` row
 * (a deferred check ties the two). The caps hold under a per-payer advisory
 * lock so two payments, from a dispute or not, cannot both pass the day's
 * limit. Runs inside the caller's transaction.
 */
final class PayCompensationAction
{
    public function __construct(
        private readonly PostLedgerEntryAction $post,
        private readonly CompensationCaps $caps,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @param  'buyer'|'seller'|null  $party  required with an order: which side of it is paid
     */
    public function handle(Staff $payer, string $customerId, string $amount, CompensationReason $reason, string $note,
        ?Order $order = null, ?string $party = null, ?Dispute $dispute = null, ?RequestContext $ctx = null): Compensation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Compensation is paid inside the caller\'s transaction.');
        }
        if (($order === null) !== ($party === null) || ($dispute !== null && $order === null)) {
            throw new LogicException('A party goes with an order, and a dispute with its order.');
        }

        $amount = Money::fixed4($amount);

        if (! $this->caps->uncapped($payer)) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['comp:'.$payer->staff_id]);
            $perPayment = $this->caps->perPayment();
            $left = $this->caps->leftToday($payer->staff_id);
            if (Money::cmp($amount, $perPayment) > 0 || Money::cmp($amount, $left) > 0) {
                throw DomainApiException::compensationCapExceeded($perPayment, $left);
            }
        }

        [$equity, $available] = DatabaseActor::ledger(fn () => [
            Account::internal(AccountKind::EXTERNAL_EQUITY),
            Account::forCustomerKind($customerId, AccountKind::CUST_AVAILABLE),
        ]);

        $txn = $this->post->handle(new LedgerEntry(
            LedgerEventKind::COMPENSATION,
            [new LedgerLine($equity, '-'.$amount), new LedgerLine($available, $amount)],
            actorStaffId: $payer->staff_id,
            memo: 'Compensation '.($dispute?->dispute_ref ?? 'direct')." · {$reason->value}",
            listingId: $order?->listing_id,
            orderId: $order?->order_id,
        ));

        $compensation = Compensation::query()->create([
            'dispute_id' => $dispute?->dispute_id,
            'order_id' => $order?->order_id,
            'customer_id' => $customerId,
            'party' => $party,
            'amount' => $amount,
            'reason' => $reason,
            'note' => $note,
            'paid_by' => $payer->staff_id,
            'ledger_txn_id' => $txn->ledger_txn_id,
            'paid_at' => CarbonImmutable::now(),
        ]);

        $this->audit->execute(
            AuditEvent::COMPENSATION_PAID,
            'success',
            ['dispute_ref' => $dispute?->dispute_ref, 'order_ref' => $order?->order_ref, 'party' => $party,
                'customer_id' => $customerId, 'customer_status' => $this->status($customerId),
                'amount' => $amount, 'reason' => $reason->value, 'ledger_txn_id' => $txn->ledger_txn_id],
            'compensation',
            $compensation->compensation_id,
            $ctx,
            actorStaffId: $payer->staff_id,
            reason: $note,
        );

        return $compensation;
    }

    /** The customer's status at the time, for the audit row (spec 015 research R2). */
    private function status(string $customerId): ?string
    {
        $status = Customer::query()->whereKey($customerId)->value('status');

        return $status instanceof CustomerStatus ? $status->value : $status;
    }
}
