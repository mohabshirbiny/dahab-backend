<?php

namespace App\Support\BuyRequests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The order reference `DH-YYYY-NNNNNN` (Part 2 §5, spec 011 research R8):
 * the year of acceptance in Cairo and a number from `order_ref_seq` that
 * never resets. A rolled-back acceptance leaves a gap; the reference is an
 * identifier, not an invoice number.
 */
final class OrderReference
{
    public static function next(CarbonImmutable $acceptedAt): string
    {
        $n = (int) DB::selectOne("SELECT nextval('order_ref_seq') AS n")->n;

        return sprintf('DH-%s-%06d', $acceptedAt->setTimezone('Africa/Cairo')->format('Y'), $n);
    }
}
