<?php

namespace App\Enums;

/**
 * What a buy-request message tells its reader (spec 011 FR-024, research
 * R13). `NEW_REQUEST` goes to the seller, `ORDER_CANCELLED` to both sides,
 * the rest to the buyer.
 */
enum BuyRequestEvent: string
{
    case NEW_REQUEST = 'new_request';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case NOT_CHOSEN = 'not_chosen';
    case EXPIRED = 'expired';
    case PIECE_WITHDRAWN = 'piece_withdrawn';
    case SELLER_SUSPENDED = 'seller_suspended';
    case FREE_AGAIN = 'free_again';
    case ORDER_CANCELLED = 'order_cancelled';
}
