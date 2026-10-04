<?php

namespace Tests\Support;

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Dispute;
use App\Models\LegalDocument;
use App\Models\Order;
use App\Models\Staff;
use App\Support\DatabaseActor;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shared fixtures for the spec 014 tests. Every state is reached through the
 * API: an order through the spec 011/012 flow, a dispute through the
 * customer's own request, a resolution through the Disputes endpoints.
 */
final class Disputes
{
    public const STAFF_URL = '/api/v1/dashboard/disputes';

    public const REQUESTS_URL = '/api/v1/dashboard/extension-requests';

    public const DETAIL = 'IGI weighed it lower than my own scale did; please weigh it again.';

    /** An order at inspection (received, no result yet). */
    public static function atInspection(TestCase $test): Order
    {
        $order = Orders::accepted($test);
        Orders::staff($test, SeedRole::OPERATIONS);
        Orders::receive($test, $order)->assertOk();

        return $order->refresh();
    }

    /** An order waiting on the buyer's decision (weight 5% short). */
    public static function deciding(TestCase $test): Order
    {
        return Orders::inspected($test, Orders::accepted($test), '9.500');
    }

    /** An order awaiting the balance (passed inspection). */
    public static function awaitingBalance(TestCase $test): Order
    {
        return Orders::inspected($test, Orders::accepted($test), '10.000');
    }

    /** A paid order ready to collect. */
    public static function readyToCollect(TestCase $test): Order
    {
        $order = self::awaitingBalance($test);
        Orders::pay($test, Orders::buyer($order), $order)->assertOk();

        return $order->refresh();
    }

    /** @param  array<string, mixed>  $body */
    public static function open(TestCase $test, Customer $customer, Order $order, array $body = [], ?string $key = null): TestResponse
    {
        return Listings::as($test, $customer)->postJson(Orders::CUSTOMER_URL."/{$order->order_id}/disputes",
            $body + ['reason' => 'disagree_inspection', 'detail' => self::DETAIL], Listings::key($key));
    }

    /** The buyer opens a dispute; returns it (read as staff). */
    public static function opened(TestCase $test, Order $order, ?Customer $by = null): Dispute
    {
        self::open($test, $by ?? Orders::buyer($order), $order)->assertCreated();

        return self::of($order);
    }

    /** The order's unresolved dispute, read past row security. */
    public static function of(Order $order): Dispute
    {
        return DatabaseActor::elevate('maintenance', fn () => Dispute::query()->where('order_id', $order->order_id)
            ->orderByDesc('opened_at')->firstOrFail());
    }

    /** @param  array<string, mixed>  $body */
    public static function resolve(TestCase $test, Dispute $dispute, array $body = [], ?string $key = null): TestResponse
    {
        return $test->postJson(self::STAFF_URL."/{$dispute->dispute_id}/resolve",
            $body + ['outcome' => 'resume', 'reply' => 'We weighed it again and the result stands.'], Listings::key($key));
    }

    /** @param  array<string, mixed>  $body */
    public static function passOn(TestCase $test, Dispute $dispute, array $body, ?string $key = null): TestResponse
    {
        return $test->postJson(self::STAFF_URL."/{$dispute->dispute_id}/pass-on", $body, Listings::key($key));
    }

    /** Act again as a staff member created earlier. */
    public static function actAs(Staff $staff): Staff
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs($staff, ['staff:access'], 'staff');

        return $staff;
    }

    public static function photoToken(TestCase $test, Customer $customer): string
    {
        return Listings::uploadToken($test, $customer, 'dispute_photo', Listings::png());
    }

    /** @param  array<string, mixed>  $body */
    public static function askMoreTime(TestCase $test, Order $order, array $body = [], ?string $key = null): TestResponse
    {
        return Listings::as($test, Orders::seller($order))->postJson(Orders::CUSTOMER_URL."/{$order->order_id}/extension-requests",
            $body + ['reason' => 'branch_closed', 'detail' => 'The branch was closed when I went yesterday evening.'], Listings::key($key));
    }

    public static function proxyAuthorisationId(): int
    {
        return (int) DatabaseActor::elevate('maintenance', fn () => LegalDocument::current(
            LegalDocument::COLLECTION_PROXY_AUTHORISATION)->legal_doc_id);
    }

    /** @param  array<string, mixed>  $body */
    public static function nameProxy(TestCase $test, Order $order, array $body = [], ?string $key = null): TestResponse
    {
        $buyer = Orders::buyer($order);
        $body += [
            'name' => 'Ahmed Samir Hassan',
            'phone' => '+201001234567',
            'id_upload_token' => array_key_exists('id_upload_token', $body) ? $body['id_upload_token'] : Listings::uploadToken($test, $buyer, 'proxy_id', Listings::png()),
            'authorisation_id' => self::proxyAuthorisationId(),
            'authorisation_accepted' => true,
        ];

        return Listings::as($test, $buyer)->postJson(Orders::CUSTOMER_URL."/{$order->order_id}/proxy", $body, Listings::key($key));
    }
}
