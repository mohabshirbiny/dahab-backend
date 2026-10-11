<?php

namespace App\Enums;

/** `piece_category` (schema §1): what a piece is, which decides how it is priced. */
enum PieceCategory: string
{
    case GOLD = 'gold';
    case DIAMOND = 'diamond';
    case GOLD_WITH_DIAMOND = 'gold_with_diamond';

    /** The name customers read (spec 019 messages about a category). */
    public function label(bool $arabic = false): string
    {
        return match ($this) {
            self::GOLD => $arabic ? 'الدهب' : 'gold',
            self::DIAMOND => $arabic ? 'الألماظ' : 'diamond',
            self::GOLD_WITH_DIAMOND => $arabic ? 'الدهب مع الألماظ' : 'gold with diamond',
        };
    }
}
