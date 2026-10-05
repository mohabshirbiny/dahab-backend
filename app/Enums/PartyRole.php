<?php

namespace App\Enums;

/** The side of an order a tax invoice is issued to (spec 016; schema `tax_invoice.party_role`). */
enum PartyRole: string
{
    case SELLER = 'seller';
    case BUYER = 'buyer';

    /** The invoice number's suffix after the order reference (spec 016 Clarifications). */
    public function suffix(): string
    {
        return $this === self::SELLER ? '-S' : '-B';
    }
}
