<?php

namespace Database\Factories;

use App\Enums\StaffRole;
use App\Models\DocumentViewLog;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentViewLog>
 */
class DocumentViewLogFactory extends Factory
{
    protected $model = DocumentViewLog::class;

    public function definition(): array
    {
        return [
            'document_id' => IdentityDocument::factory(),
            'viewed_by' => Staff::factory()->role(StaffRole::VERIFICATION),
            'viewed_at' => now(),
            'ip_address' => '127.0.0.1',
        ];
    }
}
