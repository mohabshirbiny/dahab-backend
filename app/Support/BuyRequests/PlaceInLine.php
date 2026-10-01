<?php

namespace App\Support\BuyRequests;

use App\Support\DatabaseActor;
use Illuminate\Support\Facades\DB;

/**
 * A queued request's place in its line (spec 011 research R16): the queued
 * requests ahead of it on the same listing. Never stored. Counting needs other
 * buyers' rows, so it runs in the `queue` scope and returns only numbers,
 * never another buyer's row (analysis C1).
 */
final class PlaceInLine
{
    /**
     * @param  list<string>  $requestIds  requests the caller already holds
     * @return array<string, int> request id => number of queued requests ahead
     */
    public static function aheadOf(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $rows = DatabaseActor::queue(fn () => DB::select(
            "SELECT r.buy_request_id AS id,
                    (SELECT count(*) FROM buy_request q
                      WHERE q.listing_id = r.listing_id AND q.state = 'queued'
                        AND q.queue_position < r.queue_position) AS ahead
               FROM buy_request r
              WHERE r.state = 'queued' AND r.buy_request_id = ANY (?::uuid[])",
            ['{'.implode(',', $requestIds).'}'],
        ));

        $ahead = [];
        foreach ($rows as $row) {
            $ahead[$row->id] = (int) $row->ahead;
        }

        return $ahead;
    }
}
