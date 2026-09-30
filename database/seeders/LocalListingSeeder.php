<?php

namespace Database\Seeders;

use App\Enums\ListingMediaKind;
use App\Enums\ListingState;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Staff;
use Database\Factories\ListingFactory;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Listings in every state this feature reaches (spec 010), for the two
 * verified local customers, with photos that can actually be opened, so the
 * review queue, the market and "My listings" can be tried by hand. Refuses
 * to run outside local/testing. Safe to run twice: a customer who already
 * has listings is left alone.
 */
class LocalListingSeeder extends Seeder
{
    /** What each seller gets: factory shape, target state, photos, extras. */
    private const PIECES = [
        '+201000000006' => [
            ['shape' => 'gold', 'path' => [], 'photos' => 2, 'attributes' => ['karat_code' => 18, 'stated_weight_g' => '4.100', 'making_charge_per_g' => '200.00']],
            ['shape' => 'gold', 'path' => ['in_review'], 'photos' => 3, 'invoice' => true, 'attributes' => ['stated_weight_g' => '30.000', 'making_charge_per_g' => '400.00']],
            ['shape' => 'gold', 'path' => ['in_review', 'changes_requested'], 'photos' => 2, 'note' => 'The hallmark photo is blurred and we cannot read the karat. Please retake it in daylight.', 'attributes' => ['karat_code' => 18, 'stated_weight_g' => '3.300', 'making_charge_per_g' => '150.00']],
            ['shape' => 'gold', 'path' => ['in_review', 'live'], 'photos' => 3, 'attributes' => ['stated_weight_g' => '8.000', 'making_charge_per_g' => '250.00']],
            ['shape' => 'gold', 'path' => ['in_review', 'live'], 'photos' => 2, 'attributes' => ['karat_code' => 18, 'stated_weight_g' => '5.200', 'making_charge_per_g' => '180.00']],
            ['shape' => 'gold', 'path' => ['in_review', 'live', 'withdrawn'], 'photos' => 2, 'attributes' => ['stated_weight_g' => '12.400', 'making_charge_per_g' => '300.00']],
        ],
        '+201000000007' => [
            ['shape' => 'diamond', 'path' => ['in_review'], 'photos' => 3, 'certificate' => true, 'attributes' => ['asking_price' => '120000.00', 'description' => 'Diamond ring, 0.8 ct, VS1, G colour. IGI certificate attached. Worn rarely.']],
            ['shape' => 'goldWithDiamond', 'path' => ['in_review', 'live'], 'photos' => 3, 'certificate' => true, 'attributes' => ['stated_weight_g' => '6.100', 'asking_price' => '80150.00', 'description' => 'Gold earrings with diamonds, 21K, 6.10 g plus stones. In their original box.']],
            ['shape' => 'gold', 'path' => ['in_review', 'rejected'], 'photos' => 2, 'note' => 'The photos are not of the piece described. Please list the piece with your own photos.', 'attributes' => ['stated_weight_g' => '15.000', 'making_charge_per_g' => '350.00']],
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalListingSeeder skipped: only runs in local/testing.');

            return;
        }

        $reviewer = Staff::query()->where('email', SeedRole::OPERATIONS->value.'@dahab.test')->value('staff_id');
        $made = 0;

        foreach (self::PIECES as $phone => $pieces) {
            $seller = Customer::query()->where('phone', $phone)->first();

            if ($seller === null || Listing::query()->where('seller_id', $seller->customer_id)->exists()) {
                continue;
            }

            foreach ($pieces as $n => $piece) {
                // One transaction per listing: the database refuses a listing committed without its history.
                DB::transaction(function () use ($seller, $piece, $reviewer, $n) {
                    $listing = Listing::factory()->{$piece['shape']}()->create(['seller_id' => $seller->customer_id] + $piece['attributes']);

                    foreach (range(0, $piece['photos'] - 1) as $i) {
                        ListingFactory::attachMedia($listing, ListingMediaKind::PHOTO, $this->photo($n * 10 + $i), 'image/png', $i);
                    }
                    if ($piece['invoice'] ?? false) {
                        ListingFactory::attachMedia($listing, ListingMediaKind::INVOICE, $this->photo(90), 'image/png');
                    }
                    if ($piece['certificate'] ?? false) {
                        ListingFactory::attachMedia($listing, ListingMediaKind::STONE_CERTIFICATE, $this->photo(95), 'image/png');
                    }

                    ListingFactory::walk($listing, array_map(fn (string $s) => ListingState::from($s), $piece['path']), $piece['note'] ?? null, $reviewer);
                });
                $made++;
            }
        }

        $this->command?->info("LocalListingSeeder: {$made} listings created.");
    }

    /** A small coloured square, different per `$seed`, as a real PNG on disk. */
    private function photo(int $seed): UploadedFile
    {
        $image = imagecreatetruecolor(320, 320);
        imagefill($image, 0, 0, imagecolorallocate($image, 190 + ($seed * 7) % 60, 150 + ($seed * 13) % 70, 60 + ($seed * 29) % 90));
        imagefilledellipse($image, 160, 160, 170 + ($seed * 11) % 80, 170 + ($seed * 11) % 80, imagecolorallocate($image, 235, 205, 110));

        $path = (string) tempnam(sys_get_temp_dir(), 'seed');
        imagepng($image, $path);

        return new UploadedFile($path, "photo{$seed}.png", 'image/png', null, true);
    }
}
