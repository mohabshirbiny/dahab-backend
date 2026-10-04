<?php

namespace App\Actions\Finance;

use App\Actions\Disputes\PayCompensationAction;
use App\Enums\CompensationReason;
use App\Enums\CustomerStatus;
use App\Enums\WalletEvent;
use App\Exceptions\DomainApiException;
use App\Jobs\NotifyCustomerJob;
use App\Models\Compensation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Staff;
use App\Notifications\WalletNotification;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pay compensation from the Compensation page, outside a dispute (spec 015
 * FR-004, Clarification Q1): the spec 014 payment — same caps, same lock, same
 * entry — in its own transaction, optionally naming one of the customer's
 * orders (the party follows). Only a verified customer, suspended or not, as
 * for crediting by hand (spec 009). The customer is told after commit.
 */
final class PayDirectCompensationAction
{
    public function __construct(private readonly PayCompensationAction $pay) {}

    public function handle(Staff $payer, string $customerId, string $amount, CompensationReason $reason, string $note,
        ?string $orderId = null, ?RequestContext $ctx = null): Compensation
    {
        return DB::transaction(function () use ($payer, $customerId, $amount, $reason, $note, $orderId, $ctx) {
            $customer = Customer::query()->whereKey($customerId)->firstOrFail();
            $status = $customer->status instanceof CustomerStatus ? $customer->status : CustomerStatus::from((string) $customer->status);
            $verified = $status === CustomerStatus::ACTIVE
                || ($status === CustomerStatus::SUSPENDED && $customer->status_before_suspension === CustomerStatus::ACTIVE);
            if (! $verified) {
                throw DomainApiException::verificationRequired();
            }

            [$order, $party] = [null, null];
            if ($orderId !== null) {
                $order = Order::query()->whereKey($orderId)->first();
                $party = match ($customerId) {
                    $order?->buyer_id => 'buyer',
                    $order?->seller_id => 'seller',
                    default => throw ValidationException::withMessages(['order_id' => ['This order is not the customer\'s.']]),
                };
            }

            $paid = $this->pay->handle($payer, $customerId, $amount, $reason, $note, $order, $party, null, $ctx);

            DB::afterCommit(fn () => NotifyCustomerJob::dispatch($customerId, new WalletNotification(
                WalletEvent::COMPENSATION_PAID, (string) $paid->amount, reason: $reason->label(), reasonAr: $reason->labelAr(),
            )));

            return $paid;
        });
    }
}
