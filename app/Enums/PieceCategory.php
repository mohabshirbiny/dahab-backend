<?php

namespace App\Enums;

/** `piece_category` (schema §1): what a piece is, which decides how it is priced. */
enum PieceCategory: string
{
    case GOLD = 'gold';
    case DIAMOND = 'diamond';
    case GOLD_WITH_DIAMOND = 'gold_with_diamond';
}
