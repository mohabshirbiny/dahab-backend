<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
