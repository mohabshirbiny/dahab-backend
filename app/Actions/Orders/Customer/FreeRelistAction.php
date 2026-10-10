<?php

namespace App\Actions\Orders\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\InboxLinkKind;
use App\Enums\ListingState;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use App\Models\AgreementAcceptance;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\ListingOwnershipDeclaration;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Support\DatabaseActor;
use App\Support\Orders\FreeRelistOffer;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The buyer of a collected piece puts it back on the market at once, with no
 * commission, inside the free-relist window the staff handover stored (spec
 * 018 US2, FR-004–FR-016; research R4–R7). Trade gate. One transaction in the
 * non-elevated `order` scope creates a NEW listing owned by the buyer, linked
 * to the order (`relisted_from_order_id` — the link is the waiver), copied
 * from the sale (the IGI-measured karat and weight, the public media, the
 * branch options still enabled — never the private invoice), and moves it
 * `draft → live` through the one guarded transition that skips review. The
 * origin listing, order and history are not touched. There is no staff step.
 */
final class FreeRelistAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated by FreeRelistRequest
     */
    public function handle(Customer $buyer, string $orderId, array $data, ?RequestContext $ctx = null): Listing
    {
        $order = Order::query()->findOrFail($orderId);
        if ($order->buyer_id !== $buyer->customer_id) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        return DatabaseActor::order(fn () => DB::transaction(function () use ($buyer, $order, $data, $ctx) {
            // Lock order: listing → order → collection (a handover locks them the same way).
            $origin = $this->lockListing($order->listing_id);
            $order = $this->lockOrder($order->order_id);
            $collection = OrderCollection::query()->where('order_id', $order->order_id)->lockForUpdate()->first();

            if ($order->state !== OrderState::COMPLETED || $collection === null || $collection->collected_at === null) {
                throw DomainApiException::illegalOrderTransition();
            }

            $existing = Listing::query()->where('relisted_from_order_id', $order->order_id)->first();
            $offer = FreeRelistOffer::for($order->setRelation('listing', $origin), $collection, $existing);

            if ($offer->status === FreeRelistOffer::USED) {
                throw DomainApiException::alreadyRelisted();
            }
            if (! $offer->isOpen()) {
                throw DomainApiException::freeRelistExpired();
            }

            $declaration = LegalDocument::current(LegalDocument::OWNERSHIP_DECLARATION);
            if ($declaration === null || (int) ($data['ownership_legal_doc_id'] ?? 0) !== $declaration->legal_doc_id) {
                throw DomainApiException::ownershipDeclarationRequired();
            }

            $this->assertPrice($origin->category, $data);

            $branchIds = $this->enabledBranchIdsOf($origin);
            $result = $order->latestInspection();
            $stones = $origin->category === PieceCategory::DIAMOND;

            // What the lab measured wins over what the seller stated (research R6).
            $karat = $stones ? null : ($result?->measured_karat ?? $origin->karat_code);
            $weight = $stones ? null : ($result?->measured_weight_g ?? $origin->stated_weight_g);

            $new = Listing::query()->create([
                'seller_id' => $buyer->customer_id,
                'category' => $origin->category,
                'piece_type_id' => $origin->piece_type_id,
                'karat_code' => $karat,
                'stated_weight_g' => $weight === null ? null : (string) $weight,
                'making_charge_per_g' => $origin->category === PieceCategory::GOLD ? $data['making_charge_per_g'] : null,
                'asking_price' => $origin->category === PieceCategory::GOLD ? null : $data['asking_price'],
                'description' => $data['description'] ?? null,
                'state' => ListingState::DRAFT,
                'relisted_from_order_id' => $order->order_id,
            ]);

            $this->recordListingCreated($new, $buyer);
            $this->copyPublicMedia($origin, $new);

            DB::table('listing_branch_option')->insert(array_map(
                fn (int $id) => ['listing_id' => $new->listing_id, 'branch_id' => $id],
                $branchIds,
            ));

            ListingOwnershipDeclaration::query()->create([
                'listing_id' => $new->listing_id,
                'customer_id' => $buyer->customer_id,
                'legal_doc_id' => $declaration->legal_doc_id,
            ]);
            AgreementAcceptance::query()->create([
                'customer_id' => $buyer->customer_id,
                'legal_doc_id' => $declaration->legal_doc_id,
                'context' => AgreementAcceptance::CONTEXT_LIST_PIECE,
                'ip_address' => filled($ctx?->ip) ? $ctx->ip : null,
                'device_fingerprint' => $ctx?->deviceFingerprintHash,
            ]);
            DB::table('listing_queue_seq')->insert(['listing_id' => $new->listing_id]);

            $new = $this->moveListing($new, ListingState::LIVE, $buyer, null, "free relist of order {$order->order_ref}");

            $this->audit->execute(
                AuditEvent::ORDER_FREE_RELISTED,
                'success',
                ['order_ref' => $order->order_ref, 'origin_listing_id' => $origin->listing_id, 'listing_id' => $new->listing_id,
                    'karat_code' => $new->karat_code, 'stated_weight_g' => $new->stated_weight_g === null ? null : (string) $new->stated_weight_g,
                    'making_charge_per_g' => $new->making_charge_per_g === null ? null : (string) $new->making_charge_per_g,
                    'asking_price' => $new->asking_price === null ? null : (string) $new->asking_price],
                'order',
                $order->order_id,
                $ctx,
                actorCustomerId: $buyer->customer_id,
            );

            $this->tellOrder($buyer->customer_id, OrderEvent::FREE_RELISTED, $order, $new,
                linkKind: InboxLinkKind::LISTING, linkId: $new->listing_id);
            $this->flushOrderOutbox();

            return $new;
        }));
    }

    /**
     * The field the category needs, and nothing the category does not use.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertPrice(PieceCategory $category, array $data): void
    {
        $field = $category === PieceCategory::GOLD ? 'making_charge_per_g' : 'asking_price';
        $other = $category === PieceCategory::GOLD ? 'asking_price' : 'making_charge_per_g';

        if (blank($data[$field] ?? null)) {
            throw ValidationException::withMessages([$field => ["The {$field} field is required for this piece."]]);
        }
        if (filled($data[$other] ?? null)) {
            throw ValidationException::withMessages([$other => ["The {$other} field does not apply to this piece."]]);
        }
    }

    /**
     * The origin's branch options that are still enabled; `branch_options_required` when none is.
     *
     * @return list<int>
     */
    private function enabledBranchIdsOf(Listing $origin): array
    {
        $ids = DB::table('listing_branch_option')->where('listing_id', $origin->listing_id)->pluck('branch_id')->map(fn ($id) => (int) $id)->all();

        $enabled = $ids === [] ? [] : Branch::query()->whereIn('branch_id', $ids)->where('is_enabled', true)
            ->orderBy('branch_id')->pluck('branch_id')->map(fn ($id) => (int) $id)->all();

        if ($enabled === []) {
            throw DomainApiException::branchOptionsRequired();
        }

        return $enabled;
    }

    /** Photos, video and stone certificate as new rows on the same stored objects; never the private invoice. */
    private function copyPublicMedia(Listing $origin, Listing $new): void
    {
        $rows = ListingMedia::query()->where('listing_id', $origin->listing_id)->where('is_private', false)
            ->orderBy('position')->orderBy('created_at')->get();

        foreach ($rows as $row) {
            ListingMedia::query()->create([
                'listing_id' => $new->listing_id,
                'kind' => $row->kind,
                'storage_ref' => $row->storage_ref,
                'is_private' => false,
                'mime' => $row->mime,
                'position' => $row->position,
            ]);
        }
    }
}
