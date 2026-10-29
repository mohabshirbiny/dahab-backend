<?php

namespace App\Actions\Identity;

use App\Enums\IdentityDocumentStatus;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListIdentityDocumentsAction
{
    /**
     * Newest first (owner notes of 2026-10-29: every list reads newest to oldest).
     *
     * @return LengthAwarePaginator<int, IdentityDocument>
     */
    public function handle(Staff $actor, IdentityDocumentStatus $status, int $perPage): LengthAwarePaginator
    {
        return IdentityDocument::query()
            ->with('customer')
            ->where('status', $status->value)
            ->orderByDesc('created_at')
            ->orderByDesc('document_id')
            ->paginate($perPage);
    }
}
