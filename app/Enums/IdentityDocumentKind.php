<?php

namespace App\Enums;

/** `identity_document.doc_kind` — a foreign phone with a passport is as valid as an Egyptian ID. */
enum IdentityDocumentKind: string
{
    case EGYPTIAN_ID = 'egyptian_id';
    case PASSPORT = 'passport';
}
