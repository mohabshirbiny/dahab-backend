<?php

namespace App\Actions\Promo;

use App\Enums\CreditKind;
use App\Enums\CreditSource;
use App\Enums\SettingKey;
use App\Enums\WaiverKind;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\DatabaseActor;
use App\Support\FirstSale\FirstSaleEligibility;
use App\Support\Listings\ListingPricer;
use App\Support\Pricing\CommissionResolution;
use App\Support\Pricing\CommissionWaivers;
use App\Support\Pricing\CreditCandidate;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use App\Support\Promo\CommissionCredits;
use App\Support\Promo\SellerCode;
use App\Support\Promo\SellerCodeGate;
use App\Support\RequestContext;

/**
 * The sell form's "what would this code do" (spec 023 research R24; contract customer-sell-api §1).
 *
 * Stateless: records nothing and spends nothing. The same resolver and the same credit pool as
 * the settlement, so the answer is what a sale would settle at today: the lowest single
 * commission of none, the seller's credits in the pool and the code asked about, floored at the
 * minimum where the credit is a percentage or an amount. Figures are indicative: the settlement
 * recomputes on the IGI weight and the rates locked at the request.
 */
final class PreviewCommissionAction
{
    public function __construct(
        private readonly SellerCodeGate $gate,
        private readonly CommissionWaivers $waivers,
        private readonly CommissionCredits $credits,
        private readonly Settings $settings,
        private readonly FirstSaleEligibility $advance,
    ) {}

    /**
     * @param  array<string, mixed>  $v  validated by CommissionPreviewRequest
     * @return array<string, mixed>
     */
    public function handle(Customer $seller, array $v, ?RequestContext $ctx = null): array
    {
        $listing = new Listing([
            'seller_id' => $seller->customer_id,
            'category' => $v['category'],
            'karat_code' => $v['karat'] ?? null,
            'stated_weight_g' => $v['weight_g'] ?? null,
            'making_charge_per_g' => $v['making_charge_per_g'] ?? null,
            'asking_price' => $v['asking_price'] ?? null,
        ]);

        $pricer = app(ListingPricer::class);
        $ordinary = $pricer->ordinary($listing);
        if ($ordinary === null) {
            throw DomainApiException::priceUnavailable();
        }
        $rates = $pricer->rates();

        return DatabaseActor::promo(function () use ($seller, $v, $ctx, $ordinary, $rates, $listing) {
            $pool = $this->credits->candidates($this->credits->usable($seller->customer_id, null));
            $without = $this->waivers->resolve($ordinary->commissionTotal(), $rates, false, false, $pool);

            $code = null;
            $candidate = null;
            $found = null;
            if (filled($v['code'] ?? null)) {
                $found = $this->gate->check($seller, (string) $v['code'], $ordinary->buyerTotal, lock: false, ctx: $ctx, category: $listing->category->value);
                [$code, $candidate] = $this->describe($found);
            }

            $with = $candidate === null
                ? $without
                : $this->waivers->resolve($ordinary->commissionTotal(), $rates, false, false, [...$pool, $candidate]);

            $kind = $with->kind === WaiverKind::CREDIT ? 'credit' : 'none';
            $limited = $with->isCredit() && $with->floorApplied && Money::cmp($with->chargedTotal, Money::fixed4($rates->minimumEgp)) === 0;

            return [
                'code' => $code,
                'waiver' => [
                    'kind' => $kind,
                    'source' => $kind === 'credit' ? $this->sourceOf($with, $pool, $candidate) : null,
                    'credit_id' => $with->creditId === 'candidate' ? null : $with->creditId,
                ],
                'commission' => [
                    'ordinary_egp' => $with->ordinary,
                    'charged_egp' => $with->charged,
                    'vat_egp' => $with->vat,
                    'saved_egp' => $with->givenUp,
                    // Package C: what the seller pays and saves, VAT included.
                    'ordinary_total_egp' => $with->ordinaryTotal,
                    'charged_total_egp' => $with->chargedTotal,
                    'saved_total_egp' => $with->givenUpTotal,
                    'minimum_egp' => Money::fixed4($rates->minimumEgp),
                    'floor_applied' => $limited,
                ],
                'you_receive' => [
                    'without_promo_egp' => $without->applyTo($ordinary)->sellerProceeds,
                    'with_promo_egp' => $with->applyTo($ordinary)->sellerProceeds,
                ],
                'pays_advance' => $this->advance->preview($seller, $listing, $found?->promo, $ordinary, $with),
                'indicative' => true,
            ];
        });
    }

    /**
     * @return array{0: array<string, mixed>, 1: CreditCandidate|null}
     */
    private function describe(SellerCode $found): array
    {
        if ($found->isReferral()) {
            $pct = $this->settings->numeric(SettingKey::REFERRAL_FRIEND_PERCENT_OFF);

            return [[
                'value' => $found->value(), 'kind' => 'referral', 'source' => 'referral',
                'commission' => ['mode' => 'percent', 'percent' => $pct],
                'first_sale_only' => true, 'pays_advance' => false,
            ], new CreditCandidate('candidate', CreditKind::PERCENT, CreditSource::REFERRAL_FRIEND, $pct, null, null, '9999')];
        }

        $p = $found->promo;
        $candidate = match ($p->commission_mode) {
            'waive' => new CreditCandidate('candidate', CreditKind::WAIVE, CreditSource::PROMO_CODE, null, null, null, '9999'),
            'percent' => new CreditCandidate('candidate', CreditKind::PERCENT, CreditSource::PROMO_CODE, (string) $p->commission_percent, null, null, '9999'),
            'amount' => new CreditCandidate('candidate', CreditKind::AMOUNT, CreditSource::PROMO_CODE, null, (string) $p->commission_amount_egp, null, '9999'),
            default => null,
        };

        return [[
            'value' => $p->code, 'kind' => $p->kind, 'source' => 'promo_code',
            'commission' => [
                'mode' => $p->commission_mode,
                'percent' => $p->commission_percent === null ? null : number_format((float) $p->commission_percent, 4, '.', ''),
            ] + ($p->commission_mode === 'amount' ? ['amount_egp' => number_format((float) $p->commission_amount_egp, 4, '.', '')] : []),
            'first_sale_only' => $p->first_sale_only, 'pays_advance' => $p->pays_advance,
        ], $candidate];
    }

    /** @param list<CreditCandidate> $pool */
    private function sourceOf(CommissionResolution $r, array $pool, ?CreditCandidate $candidate): ?string
    {
        $all = $candidate === null ? $pool : [...$pool, $candidate];
        foreach ($all as $c) {
            if ($c->id === $r->creditId) {
                return $c->source === CreditSource::PROMO_CODE ? 'promo_code' : 'referral';
            }
        }

        return null;
    }
}
