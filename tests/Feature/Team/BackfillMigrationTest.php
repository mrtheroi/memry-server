<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('runs outside a wrapping transaction so each user backfill commits on its own', function () {
    $migration = require database_path('migrations/2026_10_12_100000_backfill_teams_then_assert_complete.php');

    expect($migration->withinTransaction)->toBeFalse();
});

it('exits 0 and changes nothing when everything is already backfilled', function () {
    User::factory()->create();
    Artisan::call('memory:backfill-teams');

    $this->artisan('memory:backfill-teams')->assertExitCode(0);
    $this->artisan('memory:backfill-teams', ['--check' => true])->assertExitCode(0);
});

it('exits 0 on an empty install', function () {
    $this->artisan('memory:backfill-teams')->assertExitCode(0);
});
