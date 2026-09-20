<?php

it('exposes the api v1 health endpoint', function () {
    $response = $this->getJson('/api/v1/health');

    $response
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('version', 'v1');
});

it('exposes the framework health endpoint', function () {
    $this->get('/up')->assertOk();
});
