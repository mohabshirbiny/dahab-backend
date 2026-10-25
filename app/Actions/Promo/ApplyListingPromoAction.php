<?php

namespace App\Actions\Promo;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\CreditSource;
use App\Enums\ListingState;
use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\CommissionCredit;
use App\Models\CommissionCreditEvent;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingPromo;
use App\Models\PromoCodeUse;
use App\Models\Referral;
use App\Support\Controls\Controls;
use App\Support\DatabaseActor;
use App\Support\Listings\ListingPricer;
use App\Support\Pricing\Settings;
use App\Support\Promo\CustomerIdKey;
use App\Support\Promo\SellerCode;
use App\Support\Promo\SellerCodeGate;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Record a code on one of the seller's listings (spec 023 research R6, R22; contract customer-sell-api §2).
 *
 * One transaction in the `promo` scope: lock the listing, refuse once a buyer is in line, replace
 * an earlier code (its credit voided), run the gate under the code's row lock, write the allowed
 * use, the bound credit with its `granted` event and the `listing_promo` row. A referral code
 * instead joins the friend to the inviter and grants the friend's bound percentage credit. A
 * refused attempt is logged on its own so the refusal that rolled this back keeps it.
 */
final class ApplyListingPromoAction
{
    public function __construct(
        private readonly SellerCodeGate $gate,
        private readonly RecordAuditLogAction $audit,
        private readonly Settings $settings,
    ) {}

    public function handle(Customer $seller, string $listingId, string $code, ?RequestContext $ctx = null): Listing
    {
        $listing = Listing::query()->whereKey($listingId)->where('seller_id', $seller->customer_id)->firstOrFail();
        app(Controls::class)->assertPlatformRunning();

        try {
            return DatabaseActor::promo(fn () => DB::transaction(
                fn () => $this->within($seller, Listing::query()->whereKey($listing->listing_id)->lockForUpdate()->firstOrFail(), $code, $ctx),
            ));
        } catch (DomainApiException $e) {
            if ($e->errorCode === 'promo_code_invalid') {
                DatabaseActor::promo(fn () => $this->gate->logBlocked($seller, $code, (string) ($e->details['reason'] ?? 'unknown'), $listing->listing_id, $ctx));
            }
            throw $e;
        }
    }

    /**
     * The redemption itself, inside the caller's transaction and `promo` scope with `$listing`
     * locked (also called by listing creation, in its own transaction).
     */
    public function within(Customer $seller, Listing $listing, string $code, ?RequestContext $ctx): Listing
    {
        $this->assertChangeable($listing);

        // Replace: the earlier code is voided first, so its share is free for the check below.
        $existing = ListingPromo::query()->where('listing_id', $listing->listing_id)->first();
        if ($existing !== null) {
            app(RemoveListingPromoAction::class)->within($seller, $listing, 'code_replaced', $ctx);
        }

        $price = $this->price($listing);
        $found = $this->gate->check($seller, $code, $price, lock: true, ctx: $ctx, category: $listing->category->value);

        $credit = $found->isReferral()
            ? $this->join($seller, $listing, $found, $ctx)
            : $this->redeem($seller, $listing, $found, $price, $ctx);

        ListingPromo::query()->create([
            'listing_id' => $listing->listing_id,
            'promo_code' => $found->promo?->code,
            'referral_inviter_id' => $found->inviterId,
            'credit_id' => $credit?->credit_id,
        ]);

        $this->audit->execute(
            AuditEvent::PROMO_APPLIED,
            'success',
            [
                'listing_id' => $listing->listing_id,
                'code' => $found->isReferral() ? 'referral' : $found->promo->code,
                'kind' => $found->isReferral() ? 'referral' : $found->promo->kind,
                'credit_id' => $credit?->credit_id,
                'id_key_kind' => CustomerIdKey::kind($found->idKey),
            ],
            'listing',
            $listing->listing_id,
            $ctx,
            actorCustomerId: $seller->customer_id,
        );

        return $listing->refresh();
    }

    private function redeem(Customer $seller, Listing $listing, SellerCode $found, string $price, ?RequestContext $ctx): ?CommissionCredit
    {
        $promo = $found->promo;
        $credit = match ($promo->commission_mode) {
            'waive' => ['kind' => 'waive', 'uses_total' => 1, 'uses_left' => 1],
            'percent' => ['kind' => 'percent', 'percent_off' => $promo->commission_percent, 'uses_total' => 1, 'uses_left' => 1],
            'amount' => ['kind' => 'amount', 'amount_egp' => $promo->commission_amount_egp, 'amount_left_egp' => $promo->commission_amount_egp],
            default => null,
        };

        $created = $credit === null ? null : $this->grant($seller, $credit + [
            'source' => CreditSource::PROMO_CODE->value,
            'listing_id' => $listing->listing_id,
            'promo_code' => $promo->code,
        ], $ctx);

        PromoCodeUse::query()->create([
            'code' => $promo->code,
            'customer_id' => $seller->customer_id,
            'allowed' => true,
            'amount_egp' => $price,
            'listing_id' => $listing->listing_id,
            'id_key' => $found->idKey,
            'credit_id' => $created?->credit_id,
            'device_fingerprint' => $ctx?->deviceFingerprintHash,
        ]);

        return $created;
    }

    /** Referral: the friend joins, and the friend's own percentage credit is bound to this listing. */
    private function join(Customer $friend, Listing $listing, SellerCode $found, ?RequestContext $ctx): CommissionCredit
    {
        $referral = Referral::query()->create([
            'inviter_id' => $found->inviterId,
            'friend_id' => $friend->customer_id,
            'code' => $found->referralCode,
            'listing_id' => $listing->listing_id,
            'device_fingerprint' => $ctx?->deviceFingerprintHash,
            'id_key' => $found->idKey,
        ]);

        $credit = $this->grant($friend, [
            'source' => CreditSource::REFERRAL_FRIEND->value,
            'kind' => 'percent',
            'percent_off' => $this->settings->numeric(SettingKey::REFERRAL_FRIEND_PERCENT_OFF),
            'uses_total' => $this->settings->integer(SettingKey::REFERRAL_FRIEND_SALES_COVERED),
            'uses_left' => $this->settings->integer(SettingKey::REFERRAL_FRIEND_SALES_COVERED),
            'listing_id' => $listing->listing_id,
            'referral_id' => $referral->referral_id,
            'expires_at' => now()->addDays($this->settings->integer(SettingKey::REFERRAL_CREDIT_EXPIRY_DAYS)),
        ], $ctx);

        $referral->friend_credit_id = $credit->credit_id;
        $referral->save();

        $this->audit->execute(
            AuditEvent::REFERRAL_JOINED,
            'success',
            ['referral_id' => $referral->referral_id, 'inviter_id' => $found->inviterId, 'friend_id' => $friend->customer_id,
                'listing_id' => $listing->listing_id, 'device_known' => $ctx?->deviceFingerprintHash !== null],
            'referral',
            $referral->referral_id,
            $ctx,
            actorCustomerId: $friend->customer_id,
        );

        return $credit;
    }

    /** @param array<string, mixed> $attributes */
    private function grant(Customer $seller, array $attributes, ?RequestContext $ctx): CommissionCredit
    {
        $credit = CommissionCredit::query()->create($attributes + ['customer_id' => $seller->customer_id])->refresh();

        CommissionCreditEvent::query()->create([
            'credit_id' => $credit->credit_id,
            'kind' => 'granted',
            'amount_left_after_egp' => $credit->amount_left_egp,
            'uses_left_after' => $credit->uses_left,
            'actor_customer_id' => $seller->customer_id,
        ]);

        $this->audit->execute(
            AuditEvent::COMMISSION_CREDIT_GRANTED,
            'success',
            ['credit_id' => $credit->credit_id, 'source' => $credit->source->value, 'kind' => $credit->kind->value,
                'percent_off' => $credit->percent_off, 'listing_id' => $credit->listing_id],
            'commission_credit',
            $credit->credit_id,
            $ctx,
            actorCustomerId: $seller->customer_id,
        );

        return $credit;
    }

    private function assertChangeable(Listing $listing): void
    {
        $open = in_array($listing->state, [ListingState::DRAFT, ListingState::CHANGES_REQUESTED, ListingState::IN_REVIEW, ListingState::LIVE], true);
        $inLine = DB::table('buy_request')->where('listing_id', $listing->listing_id)->whereIn('state', ['queued', 'accepted'])->exists();

        if (! $open || $inLine) {
            throw DomainApiException::listingPromoLocked();
        }
    }

    /** The listing's indicative price now (the figure a cap counts), else what it asks. */
    private function price(Listing $listing): string
    {
        $quote = app(ListingPricer::class)->quote($listing);

        return (string) ($quote->currentPrice ?? $listing->asking_price ?? '0.0000');
    }
}
