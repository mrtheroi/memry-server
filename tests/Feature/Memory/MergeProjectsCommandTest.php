<?php

use App\Memory\Domain\UserPrompt;
use App\Memory\Infrastructure\Persistence\EloquentPromptRepository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function prompt(User $user, string $content, string $project = 'dbmcp'): void
{
    (new EloquentPromptRepository)->save(new UserPrompt(
        userId: $user->id,
        sessionId: 'session-1',
        project: $project,
        content: $content,
    ));
}

test('it moves the observations and prompts of every user from one project to another', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    remember($user, 'Own decision', 'Content');
    remember($other, 'Other decision', 'Content');
    remember($user, 'Unrelated decision', 'Content', project: 'other-app');
    prompt($user, 'Own prompt');

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => 'memry'])
        ->expectsOutputToContain('Moved 2 observations and 1 prompts from dbmcp to memry.')
        ->assertSuccessful();

    $this->assertDatabaseMissing('observations', ['project' => 'dbmcp']);
    $this->assertDatabaseHas('observations', ['title' => 'Own decision', 'project' => 'memry']);
    $this->assertDatabaseHas('observations', ['title' => 'Other decision', 'project' => 'memry']);
    $this->assertDatabaseHas('observations', ['title' => 'Unrelated decision', 'project' => 'other-app']);
    $this->assertDatabaseHas('user_prompts', ['content' => 'Own prompt', 'project' => 'memry']);
});

test('it keeps the timestamps of the moved rows so the context keeps its order', function () {
    $user = User::factory()->create();

    $this->travelTo('2026-09-01 10:00:00');
    remember($user, 'Old decision', 'Content');
    prompt($user, 'Old prompt');
    $this->travelTo('2026-09-28 10:00:00');

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => 'memry'])->assertSuccessful();

    $this->assertDatabaseHas('observations', ['project' => 'memry', 'updated_at' => '2026-09-01 10:00:00']);
    $this->assertDatabaseHas('user_prompts', ['project' => 'memry', 'updated_at' => '2026-09-01 10:00:00']);
});

test('it normalizes both project names', function () {
    $user = User::factory()->create();

    remember($user, 'Own decision', 'Content');

    $this->artisan('memory:merge-projects', ['from' => ' DbMcp ', 'to' => 'Memry'])
        ->expectsOutputToContain('Moved 1 observations and 0 prompts from dbmcp to memry.')
        ->assertSuccessful();

    $this->assertDatabaseHas('observations', ['title' => 'Own decision', 'project' => 'memry']);
});

test('it refuses to merge a project into itself', function () {
    $user = User::factory()->create();

    remember($user, 'Own decision', 'Content');

    $this->artisan('memory:merge-projects', ['from' => 'DbMcp', 'to' => ' dbmcp '])
        ->expectsOutputToContain('Cannot merge project dbmcp into itself.')
        ->assertFailed();

    $this->assertDatabaseHas('observations', ['title' => 'Own decision', 'project' => 'dbmcp']);
});

test('it refuses a blank project name', function () {
    $user = User::factory()->create();

    remember($user, 'Own decision', 'Content');

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => '   '])
        ->expectsOutputToContain('Project names must not be blank.')
        ->assertFailed();

    $this->assertDatabaseHas('observations', ['title' => 'Own decision', 'project' => 'dbmcp']);
});

test('it only moves the rows of the given user when an email is passed', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);
    $other = User::factory()->create();

    remember($user, 'Own decision', 'Content');
    remember($other, 'Other decision', 'Content');
    prompt($user, 'Own prompt');
    prompt($other, 'Other prompt');

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => 'memry', '--email' => 'me@example.com'])
        ->expectsOutputToContain('Moved 1 observations and 1 prompts from dbmcp to memry.')
        ->assertSuccessful();

    $this->assertDatabaseHas('observations', ['title' => 'Own decision', 'project' => 'memry']);
    $this->assertDatabaseHas('observations', ['title' => 'Other decision', 'project' => 'dbmcp']);
    $this->assertDatabaseHas('user_prompts', ['content' => 'Own prompt', 'project' => 'memry']);
    $this->assertDatabaseHas('user_prompts', ['content' => 'Other prompt', 'project' => 'dbmcp']);
});

test('it finds the user when the email differs in case or spacing', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);

    remember($user, 'Own decision', 'Content');

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => 'memry', '--email' => ' Me@Example.COM '])
        ->expectsOutputToContain('Moved 1 observations and 0 prompts from dbmcp to memry.')
        ->assertSuccessful();

    $this->assertDatabaseHas('observations', ['title' => 'Own decision', 'project' => 'memry']);
});

test('it fails without moving anything when the email is unknown', function () {
    $user = User::factory()->create();

    remember($user, 'Own decision', 'Content');

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => 'memry', '--email' => 'ghost@example.com'])
        ->expectsOutputToContain('User ghost@example.com not found.')
        ->assertFailed();

    $this->assertDatabaseHas('observations', ['title' => 'Own decision', 'project' => 'dbmcp']);
});

test('it moves colliding topic keys without deleting anything and reports how many collided', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    remember($user, 'Auth in dbmcp', 'Content', topicKey: 'architecture/auth');
    remember($user, 'Auth in memry', 'Content', project: 'memry', topicKey: 'architecture/auth');
    remember($user, 'Search in dbmcp', 'Content', topicKey: 'architecture/search');
    remember($other, 'Other auth in dbmcp', 'Content', topicKey: 'architecture/search');

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => 'memry'])
        ->expectsOutputToContain('Moved 3 observations and 0 prompts from dbmcp to memry.')
        ->expectsOutputToContain('1 topic_key collisions')
        ->assertSuccessful();

    $this->assertDatabaseCount('observations', 4);
    $this->assertDatabaseHas('observations', ['title' => 'Auth in dbmcp', 'project' => 'memry', 'topic_key' => 'architecture/auth']);
    $this->assertDatabaseHas('observations', ['title' => 'Auth in memry', 'project' => 'memry', 'topic_key' => 'architecture/auth']);
});

test('it only counts the collisions of the given user when an email is passed', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);
    $other = User::factory()->create();

    foreach ([$user, $other] as $owner) {
        remember($owner, 'Auth in dbmcp', 'Content', topicKey: 'architecture/auth');
        remember($owner, 'Auth in memry', 'Content', project: 'memry', topicKey: 'architecture/auth');
    }

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => 'memry', '--email' => 'me@example.com'])
        ->expectsOutputToContain('1 topic_key collisions')
        ->assertSuccessful();
});

test('it keeps the moved observations searchable under the new project', function () {
    $user = User::factory()->create();

    remember($user, 'Sanctum tokens are hashed', 'Stored with SHA-256');

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => 'memry'])->assertSuccessful();

    expect(searchFor($user, 'sanctum', limit: 10, project: 'memry'))->toHaveCount(1)
        ->and(searchFor($user, 'sanctum', limit: 10, project: 'dbmcp'))->toBeEmpty();
});

test('it prints exactly the two summary lines and exits 0', function () {
    $user = User::factory()->create();

    remember($user, 'Auth in dbmcp', 'Content', topicKey: 'architecture/auth');
    remember($user, 'Auth in memry', 'Content', project: 'memry', topicKey: 'architecture/auth');
    remember($user, 'Plain', 'Content');
    prompt($user, 'Own prompt');

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => 'memry'])
        ->expectsOutput('Moved 2 observations and 1 prompts from dbmcp to memry.')
        ->expectsOutput('1 topic_key collisions (same user and topic_key now twice in memry) left to resolve.')
        ->assertExitCode(0);
});

test('it prints exactly the summary lines with --email and counts only that user', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);
    $other = User::factory()->create();

    remember($user, 'Own', 'Content');
    remember($other, 'Other', 'Content');
    remember($other, 'Other two', 'Content');

    $this->artisan('memory:merge-projects', ['from' => 'dbmcp', 'to' => 'memry', '--email' => 'me@example.com'])
        ->expectsOutput('Moved 1 observations and 0 prompts from dbmcp to memry.')
        ->expectsOutput('0 topic_key collisions (same user and topic_key now twice in memry) left to resolve.')
        ->assertExitCode(0);
});

test('it prints exactly one error line and exits 1 on each refusal', function (array $arguments, string $line) {
    $this->artisan('memory:merge-projects', $arguments)
        ->expectsOutput($line)
        ->assertExitCode(1);
})->with([
    'blank' => [['from' => ' ', 'to' => 'memry'], 'Project names must not be blank.'],
    'same' => [['from' => 'A', 'to' => 'a'], 'Cannot merge project a into itself.'],
    'unknown user' => [['from' => 'a', 'to' => 'b', '--email' => ' Ghost@Example.COM '], 'User ghost@example.com not found.'],
]);
