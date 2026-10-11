<?php

namespace Database\Factories;

use App\Enums\StaffNoticeKind;
use App\Models\Staff;
use App\Models\StaffNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffNoticeFactory extends Factory
{
    protected $model = StaffNotice::class;

    public function definition(): array
    {
        return [
            'staff_id' => fn () => Staff::factory()->create()->staff_id,
            'kind' => StaffNoticeKind::FOUNDER_ALERT->value,
            'severity' => 'info',
            'title' => 'Staff member created',
            'body' => 'A staff member was created.',
            'params' => ['event' => 'authz.staff.created'],
            'link' => '/audit',
            'created_at' => now(),
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }
}
