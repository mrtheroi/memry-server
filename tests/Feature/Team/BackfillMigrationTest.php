<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

it('does no per-user work on start when nothing is left to backfill', function () {
    User::factory()->count(3)->create();
    $userLocks = 0;
    DB::listen(function ($query) use (&$userLocks) {
        if (str_contains($query->sql, 'from "users"') && str_contains($query->sql, 'for share')) {
            $userLocks++;
        }
    });

    expect(Artisan::call('memory:backfill-teams'))->toBe(0)
        ->and($userLocks)->toBe(0)
        ->and(Artisan::output())->toBe("Nothing to backfill.\n");
});
