<?php

namespace App\Actions\Referral;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\ReferralState;
use App\Enums\SettingKey;
use App\Models\CommissionCredit;
use App\Models\CommissionCreditEvent;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Referral;
use App\Notifications\ReferralRewardNotification;
use App\Support\DatabaseActor;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use App\Support\SystemActor;
use Illuminate\Support\Facades\DB;

/**
 * The inviter's reward, once the friend completed a sale (spec 023 research R22–R23).
 *
 * Not granted inside the friend's settlement: that runs as the friend's buyer in the `order`
 * scope and cannot write a third customer's credit. A job after commit (and a healer every five
 * minutes) calls this as the system actor. Idempotent: the referral's state moves once, and
 * `inviter_credit_id` is set once. Conditions: `referral.enabled`, the referral still `joined`,
 * the friend's order settled with a sale value of at least `referral.min_friend_sale_egp`.
 * Grants one `amount` credit of `referral.inviter_reward_egp`, unbound (the inviter's pool),
 * expiring in `referral.credit_expiry_days`; tells the inviter by SMS, email and the inbox.
 */
final class GrantReferralRewardAction
{
    public function __construct(
        private readonly Settings $settings,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** The referral rewarded by this order (the friend sold it), or null when nothing was granted. */
    public function forOrder(string $orderId): ?Referral
    {
        return DatabaseActor::elevate('system', function () use ($orderId) {
            $order = Order::query()->whereKey($orderId)->first();
            if ($order === null || $order->settlement_txn_id === null || $order->final_buyer_total === null) {
                return null;
            }

            return $this->grant($order);
        });
    }

    private function grant(Order $order): ?Referral
    {
        if (! $this->settings->bool(SettingKey::REFERRAL_ENABLED)) {
            return null;
        }
        if (Money::cmp((string) $order->final_buyer_total, $this->settings->numeric(SettingKey::REFERRAL_MIN_FRIEND_SALE_EGP)) < 0) {
            return null;
        }

        $notice = null;
        $referral = DB::transaction(function () use ($order, &$notice) {
            $referral = Referral::query()->where('friend_id', $order->seller_id)
                ->where('state', ReferralState::JOINED->value)->lockForUpdate()->first();
            if ($referral === null) {
                return null;
            }

            $amount = Money::fixed4($this->settings->numeric(SettingKey::REFERRAL_INVITER_REWARD_EGP));
            $system = SystemActor::id();

            $credit = CommissionCredit::query()->create([
                'customer_id' => $referral->inviter_id,
                'source' => 'referral_inviter',
                'kind' => 'amount',
                'amount_egp' => $amount,
                'amount_left_egp' => $amount,
                'referral_id' => $referral->referral_id,
                'expires_at' => now()->addDays($this->settings->integer(SettingKey::REFERRAL_CREDIT_EXPIRY_DAYS)),
            ])->refresh();

            CommissionCreditEvent::query()->create([
                'credit_id' => $credit->credit_id,
                'kind' => 'granted',
                'amount_left_after_egp' => $amount,
                'actor_staff_id' => $system,
            ]);

            $referral->forceFill([
                'state' => ReferralState::REWARDED,
                'reward_order_id' => $order->order_id,
                'rewarded_at' => now(),
                'inviter_credit_id' => $credit->credit_id,
            ])->save();

            $this->audit->execute(
                AuditEvent::REFERRAL_REWARDED, 'success',
                ['referral_id' => $referral->referral_id, 'order_ref' => $order->order_ref, 'credit_id' => $credit->credit_id, 'amount' => $amount],
                'referral', $referral->referral_id, actorStaffId: $system,
            );
            $this->audit->execute(
                AuditEvent::COMMISSION_CREDIT_GRANTED, 'success',
                ['credit_id' => $credit->credit_id, 'source' => 'referral_inviter', 'kind' => 'amount', 'amount' => $amount],
                'commission_credit', $credit->credit_id, actorStaffId: $system,
            );

            $notice = [$referral->inviter_id, $amount];

            return $referral;
        });

        if ($notice !== null) {
            Customer::query()->find($notice[0])?->notify(new ReferralRewardNotification($notice[1]));
        }

        return $referral;
    }

    /**
     * The healer's work (research R23): every referral still `joined` whose friend has a settled
     * order of enough value gets its reward. Returns how many were granted.
     */
    public function healPending(): int
    {
        return DatabaseActor::elevate('system', function () {
            $min = $this->settings->numeric(SettingKey::REFERRAL_MIN_FRIEND_SALE_EGP);
            $orders = DB::table('referral as r')
                ->join('order as o', 'o.seller_id', '=', 'r.friend_id')
                ->where('r.state', ReferralState::JOINED->value)
                ->whereNotNull('o.settlement_txn_id')->where('o.final_buyer_total', '>=', $min)
                ->orderBy('o.settled_at')->pluck('o.order_id');

            $granted = 0;
            foreach ($orders as $orderId) {
                if ($this->forOrder((string) $orderId) !== null) {
                    $granted++;
                }
            }

            return $granted;
        });
    }
}
