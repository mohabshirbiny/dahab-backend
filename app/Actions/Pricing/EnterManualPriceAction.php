<?php

namespace App\Actions\Pricing;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Pricing\Concerns\LocksGoldPrice;
use App\Enums\AuditEvent;
use App\Enums\ManualPriceStatus;
use App\Enums\PriceSource;
use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\GoldPrice;
use App\Models\ManualGoldPrice;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use App\Support\Pricing\FeedHealth;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;

/**
 * Enter the 24K bid and ask by hand while the feed is down (spec 005 US2,
 * FR-012–FR-015). Above manualprice.confirm_deviation_pct the request waits
 * for a confirmation; otherwise, or when there is no price yet, it takes
 * effect at once.
 */
final class EnterManualPriceAction
{
    use LocksGoldPrice;

    public function __construct(
        private readonly FeedHealth $feed,
        private readonly Settings $settings,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** Either both prices or a percentage change from the current pair. */
    public function handle(Staff $actor, ?string $bid, ?string $ask, ?string $changePct, ?string $reason): ManualGoldPrice
    {
        ReasonRule::assertPresent($reason);

        return $this->withPriceLock(function () use ($actor, $bid, $ask, $changePct, $reason) {
            if ($this->feed->isHealthy()) {
                throw DomainApiException::priceFeedHealthy();
            }

            $current = GoldPrice::current();

            if ($changePct !== null) {
                $current ?? throw DomainApiException::noGoldPrice();
                $bid = Money::round4(Money::add((string) $current->bid_24k, Money::percent((string) $current->bid_24k, $changePct)));
                $ask = Money::round4(Money::add((string) $current->ask_24k, Money::percent((string) $current->ask_24k, $changePct)));
            }

            $deviation = $current === null ? null : Money::round4(Money::max(
                self::deviation((string) $current->bid_24k, $bid),
                self::deviation((string) $current->ask_24k, $ask),
            ));
            $requiresConfirmation = $deviation !== null
                && Money::cmp($deviation, $this->settings->numeric(SettingKey::MANUALPRICE_CONFIRM_DEVIATION_PCT)) > 0;

            $this->closePending();

            $manual = ManualGoldPrice::query()->create([
                'bid_24k' => $bid,
                'ask_24k' => $ask,
                'previous_gold_price_id' => $current?->gold_price_id,
                'deviation_pct' => $deviation,
                'requires_confirmation' => $requiresConfirmation,
                'reason' => trim($reason),
                'entered_by' => $actor->staff_id,
                'status' => $requiresConfirmation ? ManualPriceStatus::PENDING : ManualPriceStatus::EFFECTIVE,
                'expires_at' => $requiresConfirmation
                    ? now()->addHours($this->settings->integer(SettingKey::MANUALPRICE_PENDING_EXPIRY_HOURS))
                    : null,
            ]);

            if (! $requiresConfirmation) {
                GoldPrice::query()->create([
                    'source' => PriceSource::MANUAL,
                    'bid_24k' => $bid,
                    'ask_24k' => $ask,
                    'manual_gold_price_id' => $manual->manual_gold_price_id,
                    'recorded_by' => $actor->staff_id,
                ]);
            }

            // audit_log.entity_id is a UUID: the integer id goes in the payload.
            $this->audit->execute(
                AuditEvent::MANUAL_PRICE_ENTERED,
                'success',
                [
                    'manual_gold_price_id' => $manual->manual_gold_price_id,
                    'bid_24k' => $bid,
                    'ask_24k' => $ask,
                    'deviation_pct' => $deviation,
                    'status' => $manual->status->value,
                ],
                entityType: 'gold_price',
                actorStaffId: $actor->staff_id,
                before: $current === null ? null : ['bid_24k' => (string) $current->bid_24k, 'ask_24k' => (string) $current->ask_24k],
                reason: trim($reason),
            );

            return $manual->refresh();
        });
    }

    /** |new − old| ÷ old × 100. */
    private static function deviation(string $old, string $new): string
    {
        $diff = Money::sub($new, $old);
        $abs = Money::cmp($diff, '0') < 0 ? Money::sub('0', $diff) : $diff;

        return Money::div(Money::mul($abs, '100'), $old);
    }
}
