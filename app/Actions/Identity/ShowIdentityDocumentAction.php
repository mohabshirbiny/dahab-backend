<?php

namespace App\Actions\Identity;

use App\Models\IdentityDocument;
use App\Models\Staff;

/**
 * Review metadata only — never the image. Opening the image is a *view* and
 * goes through ViewIdentityDocumentAction, which logs it.
 */
final class ShowIdentityDocumentAction
{
    public function handle(Staff $actor, string $documentId): IdentityDocument
    {
        return IdentityDocument::query()->with('customer')->findOrFail($documentId);
    }
}
