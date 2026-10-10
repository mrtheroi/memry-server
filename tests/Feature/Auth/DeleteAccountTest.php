<?php

use App\Memory\Domain\UserPrompt;
use App\Memory\Infrastructure\Persistence\EloquentPromptRepository;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('it deletes the account of the authenticated user and answers 204', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/account', ['email' => 'ada@example.com'])
        ->assertNoContent();

    $this->assertModelMissing($user);
});

test('it deletes the memories and prompts of the user', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;
    remember($user, 'Auth model', 'Sanctum bearer tokens');
    (new EloquentPromptRepository)->save(new UserPrompt($user->id, 'session-1', 'dbmcp', 'Add login'));

    $this->withToken($token)->deleteJson('/api/account', ['email' => 'ada@example.com'])
        ->assertNoContent();

    $this->assertDatabaseMissing('observations', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('user_prompts', ['user_id' => $user->id]);
});

test('it revokes every token of the user, not only the current one', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;
    $user->createToken('claude-code');

    $this->withToken($token)->deleteJson('/api/account', ['email' => 'ada@example.com'])
        ->assertNoContent();

    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
    ]);
});

test('it deletes the login codes of the account email', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;
    LoginCode::issue('ada@example.com');

    $this->withToken($token)->deleteJson('/api/account', ['email' => 'ada@example.com'])
        ->assertNoContent();

    $this->assertDatabaseMissing('login_codes', ['email' => 'ada@example.com']);
});

test('it deletes the sessions of the user', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;
    DB::table('sessions')->insert(['id' => 'session-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

    $this->withToken($token)->deleteJson('/api/account', ['email' => 'ada@example.com'])
        ->assertNoContent();

    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
});

test('it deletes the password reset tokens of the account email', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;
    DB::table('password_reset_tokens')->insert(['email' => 'ada@example.com', 'token' => 'hashed-token']);

    $this->withToken($token)->deleteJson('/api/account', ['email' => 'ada@example.com'])
        ->assertNoContent();

    $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ada@example.com']);
});

test('it leaves the data of other users untouched', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;
    $stranger = User::factory()->create(['email' => 'grace@example.com']);
    $strangerToken = $stranger->createToken('memry-cli')->plainTextToken;
    remember($stranger, 'Stranger decision', 'Must survive');
    (new EloquentPromptRepository)->save(new UserPrompt($stranger->id, 'session-1', 'dbmcp', 'Stranger prompt'));
    LoginCode::issue('grace@example.com');

    $this->withToken($token)->deleteJson('/api/account', ['email' => 'ada@example.com'])
        ->assertNoContent();

    $this->assertModelExists($stranger);
    $this->assertDatabaseHas('observations', ['user_id' => $stranger->id]);
    $this->assertDatabaseHas('user_prompts', ['user_id' => $stranger->id]);
    $this->assertDatabaseHas('login_codes', ['email' => 'grace@example.com']);

    $this->app['auth']->forgetGuards();

    $this->withToken($strangerToken)->get('/api/context?project=dbmcp')->assertOk();
});

test('it refuses to delete the account when the email does not match', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;
    remember($user, 'Auth model', 'Sanctum bearer tokens');

    $this->withToken($token)->deleteJson('/api/account', ['email' => 'grace@example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertModelExists($user);
    $this->assertDatabaseHas('observations', ['user_id' => $user->id]);
    expect($user->tokens()->count())->toBe(1);
});

test('it accepts the email with a different case and surrounding spaces', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/account', ['email' => '  Ada@Example.COM '])
        ->assertNoContent();

    $this->assertModelMissing($user);
});

test('it requires the email as a string', function (mixed $email) {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/account', ['email' => $email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertModelExists($user);
})->with([
    'missing' => [null],
    'an array' => [['ada@example.com']],
]);

test('it rejects account deletion without a valid token', function () {
    $this->deleteJson('/api/account', ['email' => 'ada@example.com'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    $this->withToken('1|not-a-real-token')->deleteJson('/api/account', ['email' => 'ada@example.com'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('it deletes nothing when a step of the deletion fails', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;
    LoginCode::issue('ada@example.com');
    User::deleting(fn () => throw new RuntimeException('Deletion failed.'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->withToken($token)->deleteJson('/api/account', ['email' => 'ada@example.com']))
        ->toThrow(RuntimeException::class, 'Deletion failed.');

    $this->assertModelExists($user);
    expect($user->tokens()->count())->toBe(1);
    $this->assertDatabaseHas('login_codes', ['email' => 'ada@example.com']);
});

test('it rate limits account deletion attempts per user', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;

    foreach (range(1, 60) as $attempt) {
        $this->withToken($token)->deleteJson('/api/account', ['email' => 'grace@example.com'])
            ->assertUnprocessable();
    }

    $this->withToken($token)->deleteJson('/api/account', ['email' => 'grace@example.com'])
        ->assertTooManyRequests();
});

test('it answers an empty 204 and wipes every row of a user with several tokens', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $current = $user->createToken('memry-cli')->plainTextToken;
    $other = $user->createToken('claude-code')->plainTextToken;
    $third = $user->createToken('mcp')->plainTextToken;
    remember($user, 'Auth model', 'Sanctum bearer tokens');
    remember($user, 'Search', 'Postgres FTS', project: 'memry');
    (new EloquentPromptRepository)->save(new UserPrompt($user->id, 'session-1', 'dbmcp', 'Add login'));

    $this->withToken($current)->deleteJson('/api/account', ['email' => 'ada@example.com'])
        ->assertNoContent()
        ->assertContent('');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('observations', 0);
    $this->assertDatabaseCount('user_prompts', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);

    foreach ([$other, $third] as $token) {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/context?project=dbmcp')->assertUnauthorized();
    }
});

test('it answers the exact validation json when the email does not match', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('memry-cli')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/account', ['email' => 'grace@example.com'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The email does not match your account.',
            'errors' => ['email' => ['The email does not match your account.']],
        ]);
});
