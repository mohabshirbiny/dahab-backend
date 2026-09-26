<?php

namespace Database\Factories;

use App\Enums\IdentityDocumentKind;
use App\Enums\IdentityDocumentStatus;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Services\IdentityDocumentStorage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdentityDocument>
 */
class IdentityDocumentFactory extends Factory
{
    protected $model = IdentityDocument::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'doc_kind' => IdentityDocumentKind::EGYPTIAN_ID,
            'front_ref' => 'identity/'.Str::uuid().'/'.Str::uuid().'.enc',
            'back_ref' => 'identity/'.Str::uuid().'/'.Str::uuid().'.enc',
            'status' => IdentityDocumentStatus::PENDING,
        ];
    }

    public function egyptianId(): static
    {
        return $this->state(fn () => ['doc_kind' => IdentityDocumentKind::EGYPTIAN_ID]);
    }

    public function passport(): static
    {
        return $this->state(fn () => [
            'doc_kind' => IdentityDocumentKind::PASSPORT,
            'back_ref' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => IdentityDocumentStatus::PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_reasons' => null,
            'review_note' => null,
        ]);
    }

    public function verified(): static
    {
        return $this->reviewed(IdentityDocumentStatus::VERIFIED);
    }

    public function rejected(): static
    {
        return $this->reviewed(IdentityDocumentStatus::REJECTED);
    }

    public function needsResubmission(array $reasons = ['blurred_or_glare']): static
    {
        return $this->state(fn () => [
            'status' => IdentityDocumentStatus::NEEDS_RESUBMISSION,
            'reviewed_by' => Staff::factory()->role(SeedRole::VERIFICATION),
            'reviewed_at' => now(),
            'review_reasons' => $reasons,
            'review_note' => null,
        ]);
    }

    public function withImage(string $bytes = "\x89PNG\r\n\x1a\nfake-image-bytes"): static
    {
        return $this->afterCreating(function (IdentityDocument $document) use ($bytes) {
            $storage = app(IdentityDocumentStorage::class);
            $storage->putAt($document->front_ref, $bytes);
            if ($document->back_ref) {
                $storage->putAt($document->back_ref, $bytes);
            }
        });
    }

    private function reviewed(IdentityDocumentStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'reviewed_by' => Staff::factory()->role(SeedRole::VERIFICATION),
            'reviewed_at' => now(),
        ]);
    }
}
