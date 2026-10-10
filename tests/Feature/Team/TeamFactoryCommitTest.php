<?php

use App\Models\User;
use App\Team\Infrastructure\Persistence\ProjectRecord;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Support\Facades\DB;

// Deliberately NOT using RefreshDatabase: every statement autocommits, as in
// seeders and scripts. Rows are removed by hand so other tests see a clean DB.
afterEach(function () {
    // Deleting the owners cascades to teams, memberships and projects.
    User::query()->whereIn('id', TeamRecord::query()->pluck('owner_id'))->delete();
});

test('the factory creates a team with its owner membership outside a transaction', function () {
    expect(DB::transactionLevel())->toBe(0);

    $team = TeamRecord::factory()->create();

    expect(DB::table('team_user')->where('team_id', $team->id)->where('user_id', $team->owner_id)->exists())->toBeTrue();
});

test('a team created through a related factory also gets its owner membership', function () {
    $project = ProjectRecord::factory()->create();

    expect(DB::table('team_user')->where('team_id', $project->team_id)->count())->toBe(1);
});
