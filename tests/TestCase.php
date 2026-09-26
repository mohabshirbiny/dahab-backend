<?php

namespace Tests;

use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Fixtures are built across many customers, so the test body runs in the
     * `maintenance` scope (spec 003 research R7). Every HTTP call pushes its
     * own frame on top and pops it afterwards, so a request in a test sees
     * exactly what it would in production. Isolation tests push a customer
     * frame explicitly.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DatabaseActor::reset();
        DatabaseActor::push('maintenance');
    }

    protected function tearDown(): void
    {
        // The connection is discarded with the app, so only forget the frames:
        // a test may have left its transaction aborted on purpose, and any
        // further statement on it would fail and skip the rollback below
        // (leaving locks behind that hang the next test).
        DatabaseActor::reset(apply: false);

        parent::tearDown();
    }

    /**
     * Send the following requests with this bearer token.
     *
     * Auth guards cache the resolved user for the life of the app instance
     * (production handles one request per process), so reset them whenever a
     * test switches credentials between requests.
     */
    protected function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
