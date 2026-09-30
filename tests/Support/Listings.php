<?php

namespace Tests\Support;

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\PieceCategory;
use App\Enums\SeedRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GoldPrice;
use App\Models\LegalDocument;
use App\Models\PieceType;
use App\Models\Staff;
use App\Support\SystemActor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the spec 010 listing and market tests: real customer
 * tokens, seeded staff roles, uploads, idempotency keys and valid bodies.
 */
final class Listings
{
    public const SELLER_URL = '/api/v1/customer/me/listings';

    public const STAFF_URL = '/api/v1/dashboard/listings';

    public const MARKET_URL = '/api/v1/market/listings';

    public const UPLOAD_URL = '/api/v1/customer/me/uploads';

    public static function token(Customer $customer): string
    {
        return app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;
    }

    /** Send the next request as `$customer` (guards cache the user per app instance). */
    public static function as(TestCase $test, Customer $customer): TestCase
    {
        app('auth')->forgetGuards();

        return $test->withToken(self::token($customer));
    }

    /** Send the next request with no credentials at all. */
    public static function anonymous(TestCase $test): TestCase
    {
        app('auth')->forgetGuards();

        return $test->withoutToken();
    }

    public static function actAsStaff(TestCase $test, SeedRole $role): Staff
    {
        return TopUps::actAsStaff($test, $role);
    }

    /** @return array<string, string> */
    public static function key(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
    }

    /** A current gold price: 24K bid 7,944 / ask 7,990 EGP per gram. */
    public static function goldPrice(string $bid = '7944', string $ask = '7990'): GoldPrice
    {
        return GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => $bid, 'ask_24k' => $ask, 'recorded_by' => SystemActor::id()]);
    }

    public static function pieceTypeId(PieceCategory $category, string $name = 'Ring'): int
    {
        return (int) PieceType::query()->where('category', $category->value)->where('name_en', $name)->value('piece_type_id');
    }

    public static function declarationId(): int
    {
        return (int) LegalDocument::current(LegalDocument::OWNERSHIP_DECLARATION)->legal_doc_id;
    }

    /**
     * A real file on disk, so the upload is judged by its content as in
     * production (UploadedFile::fake() reports a type guessed from the name).
     */
    public static function file(string $bytes, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'lst');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    public static function pngBytes(int $side = 40): string
    {
        ob_start();
        imagepng(imagecreatetruecolor($side, $side));

        return (string) ob_get_clean();
    }

    public static function png(string $name = 'photo.png', int $padBytes = 0): UploadedFile
    {
        return self::file(self::pngBytes().str_repeat(chr(0), $padBytes), $name);
    }

    public static function jpeg(string $name = 'photo.jpg'): UploadedFile
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(40, 40));

        return self::file((string) ob_get_clean(), $name);
    }

    public static function pdfBytes(): string
    {
        return implode(PHP_EOL, ['%PDF-1.4', '1 0 obj<<>>endobj', 'trailer<<>>', '%%EOF', '']);
    }

    public static function pdf(string $name = 'invoice.pdf'): UploadedFile
    {
        return self::file(self::pdfBytes(), $name);
    }

    /** A small file whose content is an MP4 container (ISO base media, brand isom). */
    public static function mp4(string $name = 'clip.mp4', int $padBytes = 256): UploadedFile
    {
        return self::file(self::mp4Header().str_repeat(chr(0), $padBytes), $name);
    }

    /** `ftyp` box (brand isom) followed by an empty `free` box. */
    public static function mp4Header(): string
    {
        return pack('N', 32).'ftypisom'.pack('N', 512).'isomiso2avc1mp41'.pack('N', 8).'free';
    }

    public static function upload(TestCase $test, Customer $customer, string $purpose, ?UploadedFile $file = null): TestResponse
    {
        return self::as($test, $customer)->post(self::UPLOAD_URL, ['purpose' => $purpose, 'file' => $file ?? self::png()], ['Accept' => 'application/json']);
    }

    /** Upload as `$customer` and return the token. */
    public static function uploadToken(TestCase $test, Customer $customer, string $purpose = 'listing_photo', ?UploadedFile $file = null): string
    {
        return (string) self::upload($test, $customer, $purpose, $file)->assertCreated()->json('data.upload_token');
    }

    /** @return list<string> */
    public static function photoTokens(TestCase $test, Customer $customer, int $count = 2): array
    {
        return array_map(fn (int $i) => self::uploadToken($test, $customer, 'listing_photo', self::png("p{$i}.png")), range(1, $count));
    }

    /**
     * A valid create body for a gold listing; pass overrides (null removes a key).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function goldBody(TestCase $test, Customer $customer, array $overrides = [], int $photos = 2): array
    {
        $body = [
            'category' => 'gold',
            'piece_type_id' => self::pieceTypeId(PieceCategory::GOLD),
            'karat_code' => 21,
            'stated_weight_g' => '8.000',
            'making_charge_per_g' => '250.00',
            'description' => 'Worn a few times and kept in its box. Small scratch on the inner band.',
            'branch_option_ids' => [Branch::factory()->create()->branch_id],
            'photo_tokens' => $photos > 0 ? self::photoTokens($test, $customer, $photos) : [],
            'ownership_declaration_accepted' => true,
            'ownership_legal_doc_id' => self::declarationId(),
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($body[$key]);
            } else {
                $body[$key] = $value;
            }
        }

        return $body;
    }
}
