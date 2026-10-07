<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 017 US6, FR-045: the legal documents the app lists and how to reach Dahab.

it('lists the four legal documents, published or not yet', function () {
    $rows = collect($this->getJson('/api/v1/reference/legal-documents')->assertOk()->json('data'))->keyBy('code');

    expect($rows->keys()->all())->toBe(['terms', 'privacy', 'selling_rules', 'id_handling'])
        ->and($rows['privacy']['published'])->toBeFalse()
        ->and($rows['privacy']['version'])->toBeNull();
    if ($rows['terms']['published']) {
        $this->getJson('/api/v1/reference/legal-documents/terms')->assertOk()->assertJsonPath('data.version', $rows['terms']['version']);
    }
    $this->getJson('/api/v1/reference/legal-documents/privacy')->assertNotFound();
});

it('serves the support contacts from the configuration', function () {
    config(['dahab-support.phone' => '19999']);

    $this->getJson('/api/v1/reference/support-contacts')->assertOk()
        ->assertJsonPath('data.phone', '19999')
        ->assertJsonStructure(['data' => ['phone', 'hours_en', 'hours_ar', 'whatsapp', 'email', 'social' => ['facebook', 'instagram', 'tiktok']]]);
});
