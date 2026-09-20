<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Models\Customer;
use App\Support\SessionDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function issueSessionFor(Customer $customer): SessionDto
{
    return app(IssueTokenFamilyAction::class)->forCustomer($customer);
}

it('revokes the current access + refresh pair on logout', function () {
    $customer = Customer::factory()->create();
    $session = issueSessionFor($customer);

    $this->withHeader('Authorization', 'Bearer '.$session->accessToken)
        ->postJson('/api/v1/customer/auth/logout')
        ->assertStatus(204);

    // subsequent request with the same access token is refused
    $this->withHeader('Authorization', 'Bearer '.$session->accessToken)
        ->getJson('/api/v1/customer/auth/me')
        ->assertStatus(401);

    // both rows in the family are gone
    $live = DB::table('personal_access_tokens')
        ->where('family_id', $session->familyId)
        ->count();
    expect($live)->toBe(0);
});

it('revokes every token for the actor on logout-all', function () {
    $customer = Customer::factory()->create();
    $sessionA = issueSessionFor($customer);
    $sessionB = issueSessionFor($customer);

    $this->withHeader('Authorization', 'Bearer '.$sessionA->accessToken)
        ->postJson('/api/v1/customer/auth/logout-all')
        ->assertStatus(204);

    $liveA = DB::table('personal_access_tokens')->where('family_id', $sessionA->familyId)->count();
    $liveB = DB::table('personal_access_tokens')->where('family_id', $sessionB->familyId)->count();
    expect($liveA)->toBe(0);
    expect($liveB)->toBe(0);
});
