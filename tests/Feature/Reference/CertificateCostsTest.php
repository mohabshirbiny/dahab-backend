<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// What IGI's certificate costs the seller, by the certificate they bring: settings, read live, public.

const CERT_COSTS_URL = '/api/v1/reference/certificate-costs';

it('gives each certificate cost from the settings, to anyone', function () {
    $this->getJson(CERT_COSTS_URL)->assertOk()
        ->assertExactJson(['data' => ['igi' => '0.0000', 'other_lab' => '1000.0000', 'none' => '2000.0000']]);
});

it('follows a changed setting at once', function () {
    Setting::query()->whereKey('certificate.other_lab_cost_egp')->update(['value_numeric' => 750]);

    $this->getJson(CERT_COSTS_URL)->assertOk()->assertJsonPath('data.other_lab', '750.0000');
});
