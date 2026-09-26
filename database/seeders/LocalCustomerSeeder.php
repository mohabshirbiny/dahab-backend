<?php

namespace Database\Seeders;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\Governorate;
use App\Enums\IdentityDocumentKind;
use App\Enums\IdentityDocumentStatus;
use App\Enums\SeedRole;
use App\Enums\SuspendedReason;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Services\IdentityDocumentStorage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Customers in every Users and Verification tab, with identity images that
 * can actually be opened, so the review cycle can be tried by hand. Refuses
 * to run outside local/testing, like LocalStaffSeeder. Safe to run twice:
 * rows are matched by phone and a customer keeps the document it already has.
 */
class LocalCustomerSeeder extends Seeder
{
    /** @var list<array<string, mixed>> */
    private const PEOPLE = [
        ['name' => 'Mona Hassan Ibrahim', 'gov' => Governorate::CAIRO, 'status' => CustomerStatus::PENDING_VERIFICATION, 'doc' => IdentityDocumentKind::EGYPTIAN_ID, 'hoursAgo' => 9],
        ['name' => 'Youssef N. Kamal', 'gov' => Governorate::GIZA, 'status' => CustomerStatus::PENDING_VERIFICATION, 'doc' => IdentityDocumentKind::EGYPTIAN_ID, 'hoursAgo' => 7],
        ['name' => 'Laura Bianchi', 'gov' => Governorate::CAIRO, 'status' => CustomerStatus::PENDING_VERIFICATION, 'doc' => IdentityDocumentKind::PASSPORT, 'hoursAgo' => 4],
        ['name' => 'Sara M. Abdelrahman', 'gov' => Governorate::ALEXANDRIA, 'status' => CustomerStatus::PENDING_VERIFICATION, 'doc' => IdentityDocumentKind::EGYPTIAN_ID, 'hoursAgo' => 2, 'type' => CustomerType::MARKET_MAKER],
        ['name' => 'Omar Fathy Salem', 'gov' => Governorate::DAKAHLIA, 'status' => CustomerStatus::PENDING_VERIFICATION, 'doc' => IdentityDocumentKind::EGYPTIAN_ID, 'hoursAgo' => 30, 'docStatus' => IdentityDocumentStatus::NEEDS_RESUBMISSION, 'reasons' => ['blurred_or_glare', 'back_missing']],
        ['name' => 'Hoda Mahmoud Ali', 'gov' => Governorate::LUXOR, 'status' => CustomerStatus::ACTIVE, 'doc' => IdentityDocumentKind::EGYPTIAN_ID, 'hoursAgo' => 96, 'docStatus' => IdentityDocumentStatus::VERIFIED],
        ['name' => 'Karim Adel Nour', 'gov' => Governorate::GIZA, 'status' => CustomerStatus::ACTIVE, 'doc' => IdentityDocumentKind::PASSPORT, 'hoursAgo' => 120, 'docStatus' => IdentityDocumentStatus::VERIFIED, 'type' => CustomerType::MARKET_MAKER],
        ['name' => 'Tamer Zaki Hegazy', 'gov' => Governorate::SUEZ, 'status' => CustomerStatus::REJECTED, 'doc' => IdentityDocumentKind::EGYPTIAN_ID, 'hoursAgo' => 72, 'docStatus' => IdentityDocumentStatus::REJECTED, 'reasons' => ['name_does_not_match', 'card_expired']],
        ['name' => 'Nadia Samir Fouad', 'gov' => Governorate::ASWAN, 'status' => CustomerStatus::SUSPENDED, 'doc' => IdentityDocumentKind::EGYPTIAN_ID, 'hoursAgo' => 200, 'docStatus' => IdentityDocumentStatus::VERIFIED],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalCustomerSeeder skipped: only runs in local/testing.');

            return;
        }

        $reviewer = Staff::query()->where('email', SeedRole::VERIFICATION->value.'@dahab.test')->first();

        foreach (self::PEOPLE as $i => $person) {
            $status = $person['status'];
            $created = now()->subHours($person['hoursAgo']);

            $customer = Customer::query()->updateOrCreate(
                ['phone' => '+2010000000'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)],
                [
                    'display_ref' => '9'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                    'full_name' => $person['name'],
                    'email' => Str::slug($person['name'], '.').'@example.test',
                    'preferred_lang' => 'en',
                    'governorate' => $person['gov'],
                    'customer_type' => $person['type'] ?? CustomerType::ORDINARY,
                    'status' => $status,
                    'is_verified' => in_array($status, [CustomerStatus::ACTIVE, CustomerStatus::SUSPENDED], true),
                    'is_suspended' => $status === CustomerStatus::SUSPENDED,
                    'suspended_reason' => $status === CustomerStatus::SUSPENDED ? SuspendedReason::POLICY_VIOLATION : null,
                    'suspended_by' => $status === CustomerStatus::SUSPENDED ? $reviewer?->staff_id : null,
                    'suspended_at' => $status === CustomerStatus::SUSPENDED ? now() : null,
                ],
            );

            // Not fillable: `created_at` is only ever set by the database.
            $customer->forceFill(['created_at' => $created])->save();

            if (IdentityDocument::query()->where('customer_id', $customer->customer_id)->exists()) {
                continue;
            }

            $this->createDocument($customer, $person, $created, $reviewer);
        }
    }

    /** @param array<string, mixed> $person */
    private function createDocument(Customer $customer, array $person, Carbon $created, ?Staff $reviewer): void
    {
        $kind = $person['doc'];
        $docStatus = $person['docStatus'] ?? IdentityDocumentStatus::PENDING;
        $storage = app(IdentityDocumentStorage::class);

        $front = 'identity/'.$customer->customer_id.'/'.Str::uuid().'.enc';
        $back = $kind === IdentityDocumentKind::PASSPORT ? null : 'identity/'.$customer->customer_id.'/'.Str::uuid().'.enc';

        $storage->putAt($front, $this->card($person['name'], $kind, 'FRONT'));
        if ($back !== null) {
            $storage->putAt($back, $this->card($person['name'], $kind, 'BACK'));
        }

        $decided = $docStatus !== IdentityDocumentStatus::PENDING;

        $document = IdentityDocument::query()->make([
            'customer_id' => $customer->customer_id,
            'doc_kind' => $kind,
            'front_ref' => $front,
            'back_ref' => $back,
            'status' => $docStatus,
            'reviewed_by' => $decided ? $reviewer?->staff_id : null,
            'reviewed_at' => $decided ? $created->copy()->addHours(2) : null,
            'review_reasons' => $person['reasons'] ?? null,
        ]);
        $document->created_at = $created;
        $document->save();
    }

    /** A plain card-shaped PNG so the reviewer has something real to look at. */
    private function card(string $name, IdentityDocumentKind $kind, string $side): string
    {
        $image = imagecreatetruecolor(856, 540);
        $paper = imagecolorallocate($image, 238, 233, 220);
        $ink = imagecolorallocate($image, 60, 56, 44);
        $line = imagecolorallocate($image, 190, 180, 150);

        imagefilledrectangle($image, 0, 0, 856, 540, $paper);
        imagerectangle($image, 20, 20, 836, 520, $line);
        imagestring($image, 5, 48, 48, 'SAMPLE - '.($kind === IdentityDocumentKind::PASSPORT ? 'PASSPORT' : 'EGYPTIAN ID').' - '.$side, $ink);
        imagestring($image, 5, 48, 120, $name, $ink);
        imagestring($image, 3, 48, 460, 'Local test data. Not a real document.', $ink);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
