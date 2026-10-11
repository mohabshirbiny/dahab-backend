<?php

namespace App\Actions\FounderSecurity;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Collection;

/**
 * Each founder with the devices known for them (spec 021 contracts §2): the device ref, first
 * and last seen. There is no removal (D21-1 keeps recovery manual).
 */
final class ListFounderDevicesAction
{
    /** @return Collection<int, Staff> */
    public function handle(): Collection
    {
        return Staff::query()
            ->manageable()
            ->where('is_founder', true)
            ->with(['deviceFingerprints' => fn ($q) => $q->orderByDesc('last_seen_at')])
            ->orderBy('full_name')
            ->get();
    }
}
