<?php

namespace App\Actions\Disputes;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\AuditEvent;
use App\Enums\CompensationReason;
use App\Enums\LedgerEventKind;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Compensation;
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
 * Pay compensation to a party's wallet inside a dispute resolution (spec 014
 * FR-015, research R8; Part 2 §9). One balanced `compensation` entry —
 * `external_equity` to the customer's available account — through the money
 * service, the payer as actor, and its `compensation` row (a deferred check
 * ties the two). The caps hold under a per-payer advisory lock so two
 * payments cannot both pass the day's limit. Inside the resolution's
 * transaction.
 */
final class PayCompensationAction
{
    public function __construct(
        private readonly PostLedgerEntryAction $post,
        private readonly CompensationCaps $caps,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $payer, Dispute $dispute, Order $order, string $party, string $amount,
        CompensationReason $reason, string $note, ?RequestContext $ctx = null): Compensation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Compensation is paid inside the resolution\'s transaction.');
        }

        $amount = Money::fixed4($amount);
        $customerId = $party === 'buyer' ? $order->buyer_id : $order->seller_id;

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
            memo: "Compensation {$dispute->dispute_ref} · {$reason->value}",
            listingId: $order->listing_id,
            orderId: $order->order_id,
        ));

        $compensation = Compensation::query()->create([
            'dispute_id' => $dispute->dispute_id,
            'order_id' => $order->order_id,
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
            ['dispute_ref' => $dispute->dispute_ref, 'order_ref' => $order->order_ref, 'party' => $party,
                'customer_id' => $customerId, 'amount' => $amount, 'reason' => $reason->value, 'ledger_txn_id' => $txn->ledger_txn_id],
            'compensation',
            $compensation->compensation_id,
            $ctx,
            actorStaffId: $payer->staff_id,
            reason: $note,
        );

        return $compensation;
    }
}
