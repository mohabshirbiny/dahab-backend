<?php

namespace App\Enums;

/** The legal documents the app lists (spec 017 FR-045, approved codes). Only published versions are shown. */
enum LegalDocumentCode: string
{
    case TERMS = 'terms';
    case PRIVACY = 'privacy';
    case SELLING_RULES = 'selling_rules';
    case ID_HANDLING = 'id_handling';
}
