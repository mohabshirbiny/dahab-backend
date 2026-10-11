<?php

use App\Enums\SeedRole;
use App\Models\Staff;
use App\Models\StaffNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 021 R12 (T054): the bell. Own notices only, unread filter and count, read one / all.

beforeEach(function () {
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    $this->other = Staff::factory()->role(SeedRole::COO)->founder()->create();
});

function snGet($test, Staff $staff, string $query = '')
{
    app('auth')->forgetGuards();

    return $test->withToken(staffAccessToken($staff))->getJson('/api/v1/dashboard/notices'.$query);
}

function snPost($test, Staff $staff, string $path)
{
    app('auth')->forgetGuards();

    return $test->withToken(staffAccessToken($staff))->postJson('/api/v1/dashboard/notices'.$path);
}

it('lists only your own notices, newest first, with the unread count', function () {
    $old = StaffNotice::factory()->create(['staff_id' => $this->ceo->staff_id, 'created_at' => now()->subHour(), 'title' => 'Older']);
    $new = StaffNotice::factory()->create(['staff_id' => $this->ceo->staff_id, 'title' => 'Newer', 'severity' => 'critical']);
    StaffNotice::factory()->read()->create(['staff_id' => $this->ceo->staff_id, 'created_at' => now()->subDay()]);
    StaffNotice::factory()->create(['staff_id' => $this->other->staff_id]);

    snGet($this, $this->ceo)->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $new->notice_id)
        ->assertJsonPath('data.0.severity', 'critical')
        ->assertJsonPath('data.1.id', $old->notice_id)
        ->assertJsonPath('meta.unread_count', 2)
        ->assertJsonPath('meta.total', 3);

    snGet($this, $this->ceo, '?unread=1')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 2);
});

it('marks one read, keeps its time, and refuses someone else\'s with 404', function () {
    $mine = StaffNotice::factory()->create(['staff_id' => $this->ceo->staff_id]);
    $theirs = StaffNotice::factory()->create(['staff_id' => $this->other->staff_id]);

    $first = snPost($this, $this->ceo, "/{$mine->notice_id}/read")->assertOk()->json('data.read_at');
    expect($first)->not->toBeNull();
    $this->travel(5)->minutes();
    snPost($this, $this->ceo, "/{$mine->notice_id}/read")->assertOk()->assertJsonPath('data.read_at', $first);

    snPost($this, $this->ceo, "/{$theirs->notice_id}/read")->assertNotFound()->assertJsonPath('code', 'not_found');
    expect($theirs->fresh()->read_at)->toBeNull();
});

it('marks all of yours read', function () {
    StaffNotice::factory()->count(3)->create(['staff_id' => $this->ceo->staff_id]);
    StaffNotice::factory()->create(['staff_id' => $this->other->staff_id]);

    snPost($this, $this->ceo, '/read-all')->assertOk()->assertJsonPath('data.marked', 3);

    expect(StaffNotice::query()->whereNull('read_at')->count())->toBe(1);
    snGet($this, $this->ceo)->assertJsonPath('meta.unread_count', 0);
});

it('gives staff who are not founders an empty inbox, and keeps a frozen account out', function () {
    snGet($this, Staff::factory()->role(SeedRole::OPERATIONS)->create())->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);

    $frozen = Staff::factory()->role(SeedRole::OPERATIONS)->frozen()->create();
    snGet($this, $frozen)->assertForbidden()->assertJsonPath('code', 'account_frozen');

    app('auth')->forgetGuards();
    $this->withoutToken()->getJson('/api/v1/dashboard/notices')->assertUnauthorized();
});
