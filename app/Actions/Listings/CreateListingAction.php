<?php

namespace App\Actions\Listings;

use App\Actions\Listings\Concerns\AttachesListingMedia;
use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\ListingMediaKind;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\AgreementAcceptance;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\Listing;
use App\Models\ListingOwnershipDeclaration;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * A seller lists a piece (spec 010 US1, FR-001–FR-005; Part 2 §3): one
 * transaction writes the draft, its creation row in the history, its media,
 * its branch options, the ownership declaration (tied to the piece and in the
 * general record of acceptances) and its queue counter. Not audited — a
 * customer action, recorded in the listing history (Part 2 §3).
 */
final class CreateListingAction
{
    use AttachesListingMedia, MovesListing;

    /**
     * @param  array<string, mixed>  $data  validated by StoreListingRequest
     */
    public function handle(Customer $seller, array $data, ?RequestContext $ctx = null): Listing
    {
        return DB::transaction(function () use ($seller, $data, $ctx) {
            $branchIds = self::enabledBranchIds($data['branch_option_ids'] ?? []);

            // The text may have a new version since the form was opened.
            $declaration = LegalDocument::current(LegalDocument::OWNERSHIP_DECLARATION);
            if ($declaration === null || (int) ($data['ownership_legal_doc_id'] ?? 0) !== $declaration->legal_doc_id) {
                throw DomainApiException::ownershipDeclarationRequired();
            }

            $listing = Listing::query()->create([
                'seller_id' => $seller->customer_id,
                'category' => $data['category'],
                'piece_type_id' => $data['piece_type_id'],
                'karat_code' => $data['karat_code'] ?? null,
                'stated_weight_g' => $data['stated_weight_g'] ?? null,
                'making_charge_per_g' => $data['making_charge_per_g'] ?? null,
                'asking_price' => $data['asking_price'] ?? null,
                'description' => $data['description'] ?? null,
                'state' => ListingState::DRAFT,
            ]);

            $this->recordListingCreated($listing, $seller);

            $this->attachMedia($listing, $seller, ListingMediaKind::PHOTO, array_values($data['photo_tokens'] ?? []));
            foreach ([
                'video_token' => ListingMediaKind::VIDEO,
                'invoice_token' => ListingMediaKind::INVOICE,
                'stone_certificate_token' => ListingMediaKind::STONE_CERTIFICATE,
            ] as $field => $kind) {
                if (filled($data[$field] ?? null)) {
                    $this->attachMedia($listing, $seller, $kind, [$data[$field]]);
                }
            }
            $this->assertMediaLimits($listing);

            DB::table('listing_branch_option')->insert(array_map(
                fn (int $id) => ['listing_id' => $listing->listing_id, 'branch_id' => $id],
                $branchIds,
            ));

            ListingOwnershipDeclaration::query()->create([
                'listing_id' => $listing->listing_id,
                'customer_id' => $seller->customer_id,
                'legal_doc_id' => $declaration->legal_doc_id,
            ]);
            AgreementAcceptance::query()->create([
                'customer_id' => $seller->customer_id,
                'legal_doc_id' => $declaration->legal_doc_id,
                'context' => AgreementAcceptance::CONTEXT_LIST_PIECE,
                'ip_address' => filled($ctx?->ip) ? $ctx->ip : null,
                'device_fingerprint' => $ctx?->deviceFingerprintHash,
            ]);

            // Needed before any buy request can take a position (Part 2 §3).
            DB::table('listing_queue_seq')->insert(['listing_id' => $listing->listing_id]);

            return $listing->refresh();
        });
    }

    /**
     * The distinct ids, all of them enabled branches — or `branch_options_required`.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    public static function enabledBranchIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === [] || Branch::query()->whereIn('branch_id', $ids)->where('is_enabled', true)->count() !== count($ids)) {
            throw DomainApiException::branchOptionsRequired();
        }

        return $ids;
    }
}
