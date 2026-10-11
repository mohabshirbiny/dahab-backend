<?php

namespace App\Enums;

/**
 * The legal texts Dahab publishes (spec 017 FR-045, spec 019 FR-020).
 *
 * `document` codes are listed in the app and, for terms and privacy, accepted at sign-up or
 * when a material version is published. `declaration` codes are ticked inside a form (listing
 * a piece, a buy request, a payout account, naming someone to collect); each form already
 * re-accepts its current version, so they need no gate.
 */
enum LegalDocumentCode: string
{
    case TERMS = 'terms';
    case PRIVACY = 'privacy';
    case SELLING_RULES = 'selling_rules';
    case ID_HANDLING = 'id_handling';
    case OWNERSHIP_DECLARATION = 'ownership_declaration';
    case DEPOSIT_AGREEMENT = 'deposit_agreement';
    case COLLECTION_PROXY_AUTHORISATION = 'collection_proxy_authorisation';
    case PAYOUT_ACCOUNT_DECLARATION = 'payout_account_declaration';

    /** `document` — shown in the app's legal list; `declaration` — ticked in a form. */
    public function kind(): string
    {
        return match ($this) {
            self::TERMS, self::PRIVACY, self::SELLING_RULES, self::ID_HANDLING => 'document',
            default => 'declaration',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TERMS => 'Terms of use',
            self::PRIVACY => 'Privacy policy',
            self::SELLING_RULES => 'Selling rules',
            self::ID_HANDLING => 'How we handle your ID',
            self::OWNERSHIP_DECLARATION => 'Ownership declaration',
            self::DEPOSIT_AGREEMENT => 'Deposit agreement',
            self::COLLECTION_PROXY_AUTHORISATION => 'Collection authorisation',
            self::PAYOUT_ACCOUNT_DECLARATION => 'Payout account declaration',
        };
    }

    /** The two documents every customer must have accepted (D2, Terms §13). */
    public function requiresAcceptance(): bool
    {
        return $this === self::TERMS || $this === self::PRIVACY;
    }

    /** @return list<self> */
    public static function documents(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => $c->kind() === 'document'));
    }

    /** @return list<self> */
    public static function declarations(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => $c->kind() === 'declaration'));
    }

    /** @return list<string> */
    public static function acceptedByEveryone(): array
    {
        return array_values(array_map(fn (self $c) => $c->value, array_filter(self::cases(), fn (self $c) => $c->requiresAcceptance())));
    }
}
