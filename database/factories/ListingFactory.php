<?php

namespace Database\Factories;

use App\Enums\ListingMediaKind;
use App\Enums\ListingState;
use App\Enums\PieceCategory;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\ListingStateChange;
use App\Models\PieceType;
use App\Services\IdentityDocumentStorage;
use App\Support\SystemActor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Listings for tests and local seeds (spec 010). A listing is always born a
 * draft with its history row and one branch option, and reaches any other
 * state only through legal moves, each with its history row — the database
 * would refuse anything else (trg_listing_guard, trg_listing_change_recorded).
 *
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    public function definition(): array
    {
        return [
            'seller_id' => Customer::factory()->verified(),
            'category' => PieceCategory::GOLD->value,
            'piece_type_id' => fn (array $a) => self::pieceTypeId($a['category']),
            'karat_code' => 21,
            'stated_weight_g' => '8.000',
            'making_charge_per_g' => '250.00',
            'asking_price' => null,
            'description' => 'Worn a few times and kept in its box. Small scratch on the inner band.',
            'state' => ListingState::DRAFT->value,
            // The application clock, so tests that travel in time order listings as they expect.
            'created_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Listing $listing) {
            ListingStateChange::query()->create([
                'listing_id' => $listing->listing_id,
                'from_state' => null,
                'to_state' => ListingState::DRAFT,
                'actor_customer_id' => $listing->seller_id,
            ]);

            DB::table('listing_queue_seq')->insert(['listing_id' => $listing->listing_id]);

            if (! DB::table('listing_branch_option')->where('listing_id', $listing->listing_id)->exists()) {
                DB::table('listing_branch_option')->insert([
                    'listing_id' => $listing->listing_id,
                    'branch_id' => Branch::query()->where('is_enabled', true)->value('branch_id') ?? Branch::factory()->create()->branch_id,
                ]);
            }

            $listing->refresh();
        });
    }

    public function gold(): static
    {
        return $this->state(fn () => [
            'category' => PieceCategory::GOLD->value, 'karat_code' => 21, 'stated_weight_g' => '8.000',
            'making_charge_per_g' => '250.00', 'asking_price' => null,
        ]);
    }

    public function diamond(): static
    {
        return $this->state(fn () => [
            'category' => PieceCategory::DIAMOND->value, 'karat_code' => null, 'stated_weight_g' => null,
            'making_charge_per_g' => null, 'asking_price' => '120000.00',
        ]);
    }

    public function goldWithDiamond(): static
    {
        return $this->state(fn () => [
            'category' => PieceCategory::GOLD_WITH_DIAMOND->value, 'karat_code' => 21, 'stated_weight_g' => '6.100',
            'making_charge_per_g' => null, 'asking_price' => '80150.00',
        ]);
    }

    /** Attach `$count` photos, stored like real uploads (chunk-encrypted on the private disk). */
    public function withPhotos(int $count = 2): static
    {
        return $this->afterCreating(function (Listing $listing) use ($count) {
            for ($i = 0; $i < $count; $i++) {
                self::attachMedia($listing, ListingMediaKind::PHOTO, UploadedFile::fake()->image("photo{$i}.png", 8, 8), 'image/png', $i);
            }
        });
    }

    public function withInvoice(): static
    {
        return $this->afterCreating(fn (Listing $listing) => self::attachMedia(
            $listing, ListingMediaKind::INVOICE,
            UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"), 'application/pdf',
        ));
    }

    public function withCertificate(): static
    {
        return $this->afterCreating(fn (Listing $listing) => self::attachMedia(
            $listing, ListingMediaKind::STONE_CERTIFICATE, UploadedFile::fake()->image('certificate.png', 8, 8), 'image/png',
        ));
    }

    public function draft(): static
    {
        return $this;
    }

    public function inReview(): static
    {
        return $this->afterCreating(fn (Listing $l) => self::walk($l, [ListingState::IN_REVIEW]));
    }

    public function changesRequested(string $note = 'The hallmark photo is blurred. Please retake it in daylight.'): static
    {
        return $this->afterCreating(fn (Listing $l) => self::walk($l, [ListingState::IN_REVIEW, ListingState::CHANGES_REQUESTED], $note));
    }

    public function live(): static
    {
        return $this->afterCreating(fn (Listing $l) => self::walk($l, [ListingState::IN_REVIEW, ListingState::LIVE]));
    }

    public function rejected(string $note = 'The photos are not of this piece.'): static
    {
        return $this->afterCreating(fn (Listing $l) => self::walk($l, [ListingState::IN_REVIEW, ListingState::REJECTED], $note));
    }

    /** Withdrawn by the seller. */
    public function withdrawn(): static
    {
        return $this->afterCreating(fn (Listing $l) => self::walk($l, [ListingState::IN_REVIEW, ListingState::LIVE, ListingState::WITHDRAWN]));
    }

    public function suspendedHold(): static
    {
        return $this->afterCreating(fn (Listing $l) => self::walk(
            $l, [ListingState::IN_REVIEW, ListingState::LIVE, ListingState::SUSPENDED_HOLD], ListingStateChange::NOTE_SUSPENDED,
        ));
    }

    /**
     * Move a listing along `$path`, writing each history row. Seller moves
     * (submit, withdraw) are the seller's; the rest are a staff member's
     * (the system actor unless `$staffId` is given). `$note` goes on the last
     * move.
     *
     * @param  list<ListingState>  $path
     */
    public static function walk(Listing $listing, array $path, ?string $note = null, ?string $staffId = null): Listing
    {
        foreach ($path as $i => $to) {
            $from = $listing->state;
            $bySeller = in_array($to, [ListingState::IN_REVIEW, ListingState::WITHDRAWN], true);

            $listing->state = $to;
            $listing->save();

            ListingStateChange::query()->create([
                'listing_id' => $listing->listing_id,
                'from_state' => $from,
                'to_state' => $to,
                'actor_customer_id' => $bySeller ? $listing->seller_id : null,
                'actor_staff_id' => $bySeller ? null : ($staffId ?? SystemActor::id()),
                'note' => $i === array_key_last($path) ? $note : null,
            ]);

            $listing->refresh();
        }

        return $listing;
    }

    public static function attachMedia(Listing $listing, ListingMediaKind $kind, UploadedFile $file, string $mime, int $position = 0): ListingMedia
    {
        return ListingMedia::query()->create([
            'listing_id' => $listing->listing_id,
            'kind' => $kind,
            'storage_ref' => app(IdentityDocumentStorage::class)->storeChunkedAt('listing-media', $listing->seller_id, $file),
            'is_private' => $kind->isPrivate(),
            'mime' => $mime,
            'position' => $position,
        ]);
    }

    private static function pieceTypeId(string|PieceCategory $category): int
    {
        $category = $category instanceof PieceCategory ? $category->value : $category;

        return (int) PieceType::query()->where('category', $category)->where('name_en', 'Ring')->value('piece_type_id');
    }
}
