<?php

use App\Memory\Domain\UserPrompt;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Memory\Infrastructure\Persistence\EloquentPromptRepository;
use App\Models\User;
use App\Team\Domain\AccessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function teamPrompt(User $user, string $project): void
{
    (new EloquentPromptRepository)->save(new UserPrompt($user->id, 's', $project, 'P', teamId: contextFor($user)->teamId));
}

test('a team context moves only the rows of that team', function () {
    [$ada, $bob] = User::factory()->count(2)->create();
    foreach ([$ada, $bob] as $user) {
        remember($user, 'T', 'C', project: 'old');
        teamPrompt($user, 'old');
    }

    $observations = (new EloquentMemoryRepository)->moveToProject(contextFor($ada), 'old', 'new');
    $prompts = (new EloquentPromptRepository)->moveToProject(contextFor($ada), 'old', 'new');

    expect($observations)->toBe(1)->and($prompts)->toBe(1)
        ->and(DB::table('observations')->where('user_id', $bob->id)->value('project'))->toBe('old')
        ->and(DB::table('user_prompts')->where('user_id', $bob->id)->value('project'))->toBe('old');
});

test('the system context moves the rows of every team and each points to its own destination project', function () {
    [$ada, $bob] = User::factory()->count(2)->create();
    foreach ([$ada, $bob] as $user) {
        remember($user, 'T', 'C', project: 'old');
        teamPrompt($user, 'old');
    }

    expect((new EloquentMemoryRepository)->moveToProject(AccessContext::system(), 'old', 'new'))->toBe(2)
        ->and((new EloquentPromptRepository)->moveToProject(AccessContext::system(), 'old', 'new'))->toBe(2);

    foreach (['observations', 'user_prompts'] as $table) {
        foreach ([$ada, $bob] as $user) {
            expect(DB::table($table)->where('user_id', $user->id)->value('project_id'))
                ->toBe(DB::table('projects')->where('team_id', contextFor($user)->teamId)->where('name', 'new')->value('id'));
        }
        expect(DB::table($table)->whereNotNull('project_id')->whereNotIn('project_id', DB::table('projects')->select('id'))->count())->toBe(0);
    }
    expect(DB::table('projects')->where('name', 'old')->count())->toBe(2);
});

test('a topic_key held by two different teams is not a collision', function () {
    [$ada, $bob] = User::factory()->count(2)->create();
    remember($ada, 'A', 'C', project: 'old', topicKey: 'k');
    remember($bob, 'B', 'C', project: 'new', topicKey: 'k');
    remember($bob, 'B2', 'C', project: 'old', topicKey: 'j');
    remember($bob, 'B3', 'C', project: 'new', topicKey: 'j');
    $repository = new EloquentMemoryRepository;

    expect($repository->countTopicKeyCollisions(AccessContext::system(), 'old', 'new'))->toBe(1)
        ->and($repository->countTopicKeyCollisions(contextFor($ada), 'old', 'new'))->toBe(0)
        ->and($repository->countTopicKeyCollisions(contextFor($bob), 'old', 'new'))->toBe(1);
});

test('--email merges only that user\'s personal team and leaves a shared team of the same user alone', function () {
    $ada = User::factory()->create(['email' => 'ada@example.com']);
    $bob = User::factory()->create();
    $shared = sharedTeamOf($ada);
    remember($ada, 'Personal', 'C', project: 'old');
    teamMemory($shared, $bob, 'Shared', project: 'old');

    $this->artisan('memory:merge-projects', ['from' => 'old', 'to' => 'new', '--email' => 'ada@example.com'])
        ->expectsOutputToContain('Moved 1 observations and 0 prompts from old to new.')
        ->assertSuccessful();

    expect(DB::table('observations')->where('title', 'Shared')->value('project'))->toBe('old')
        ->and(DB::table('observations')->where('title', 'Personal')->value('project_id'))
        ->toBe(DB::table('projects')->where('team_id', contextFor($ada)->teamId)->where('name', 'new')->value('id'));
});
