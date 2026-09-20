<?php

namespace App\Actions\Identity;

use App\Enums\IdentityDocumentStatus;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListIdentityDocumentsAction
{
    /**
     * Oldest first, so the review queue is worked in submission order.
     *
     * @return LengthAwarePaginator<int, IdentityDocument>
     */
    public function handle(Staff $actor, IdentityDocumentStatus $status, int $perPage): LengthAwarePaginator
    {
        return IdentityDocument::query()
            ->with('customer')
            ->where('status', $status->value)
            ->orderBy('created_at')
            ->orderBy('document_id')
            ->paginate($perPage);
    }
}
