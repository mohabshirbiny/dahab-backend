<?php

namespace App\Actions\Promo;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\CreditKind;
use App\Enums\CreditStatus;
use App\Exceptions\DomainApiException;
use App\Models\CommissionCredit;
use App\Models\CommissionCreditEvent;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Orders\SettlementFigures;
use App\Support\Pricing\Money;
use App\Support\RequestContext;
use LogicException;

/**
 * Spend the commission credit a settlement used (spec 023 research R2, R3). Called by the buyer's
 * payment inside its transaction, in the audited `order` scope, after the figures were computed
 * from credits the payment had locked: the row is re-read FOR UPDATE and re-checked, its counter
 * moves, and the `spent` event names the order, the ordinary commission, the charged one and the
 * reduction, with the buyer as actor. The database ties the stored waiver to this event (DH026).
 *
 * Only the winning credit is touched; a sale with no credit waiver does nothing here.
 */
final class SpendCommissionCreditAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Order $order, SettlementFigures $f, Customer $buyer, ?RequestContext $ctx = null): void
    {
        if ($f->waiverCreditId === null) {
            return;
        }

        $credit = CommissionCredit::query()->whereKey($f->waiverCreditId)->lockForUpdate()->firstOrFail();
        $reduction = $f->commissionGivenUp;

        if ($credit->status !== CreditStatus::ACTIVE || $credit->customer_id !== $order->seller_id) {
            // The credit was locked before the figures were read; reaching this is a bug, not a race.
            throw new LogicException("Commission credit {$credit->credit_id} cannot be spent on order {$order->order_id}.");
        }

        if ($credit->kind === CreditKind::AMOUNT) {
            if (Money::cmp($reduction, (string) $credit->amount_left_egp) > 0) {
                throw DomainApiException::settlementTermsMismatch();
            }
            $left = Money::fixed4(Money::sub((string) $credit->amount_left_egp, $reduction));
            $credit->amount_left_egp = $left;
            $exhausted = Money::cmp($left, '0') === 0;
            $usesLeftAfter = null;
        } else {
            if ((int) $credit->uses_left < 1) {
                throw DomainApiException::settlementTermsMismatch();
            }
            $credit->uses_left = (int) $credit->uses_left - 1;
            $exhausted = $credit->uses_left === 0;
            $left = null;
            $usesLeftAfter = $credit->uses_left;
        }

        if ($exhausted) {
            $credit->status = CreditStatus::EXHAUSTED;
            $credit->ended_at = now();
        }
        $credit->save();

        CommissionCreditEvent::query()->create([
            'credit_id' => $credit->credit_id,
            'kind' => 'spent',
            'order_id' => $order->order_id,
            'ordinary_commission_egp' => $f->ordinaryCommission,
            'charged_commission_egp' => $f->commission,
            'reduction_egp' => $reduction,
            'amount_left_after_egp' => $left,
            'uses_left_after' => $usesLeftAfter,
            'actor_customer_id' => $buyer->customer_id,
        ]);

        $this->audit->execute(
            AuditEvent::COMMISSION_CREDIT_SPENT,
            'success',
            [
                'credit_id' => $credit->credit_id, 'order_ref' => $order->order_ref, 'source' => $credit->source->value,
                'ordinary_commission' => $f->ordinaryCommission, 'charged_commission' => $f->commission, 'reduction' => $reduction,
            ],
            'commission_credit',
            $credit->credit_id,
            $ctx,
            actorCustomerId: $buyer->customer_id,
        );
    }
}
