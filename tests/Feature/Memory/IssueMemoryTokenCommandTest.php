<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('it issues an MCP token for a user by email', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);

    $this->artisan('memory:token', ['email' => 'me@example.com'])
        ->expectsOutputToContain('Token:')
        ->assertSuccessful();

    expect($user->tokens()->count())->toBe(1);
});

test('it creates the user after confirmation when the email does not exist', function () {
    $this->artisan('memory:token', ['email' => 'new@example.com'])
        ->expectsConfirmation('User new@example.com does not exist. Create it?', 'yes')
        ->expectsOutputToContain('Token:')
        ->assertSuccessful();

    expect(User::where('email', 'new@example.com')->first()->tokens()->count())->toBe(1);
});

test('it creates nothing when the user creation is declined', function () {
    $this->artisan('memory:token', ['email' => 'typo@example.com'])
        ->expectsConfirmation('User typo@example.com does not exist. Create it?', 'no')
        ->expectsOutputToContain('No token issued.')
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('it creates the user without asking when the create option is given', function () {
    $this->artisan('memory:token', ['email' => 'cloud@example.com', '--create' => true])
        ->expectsOutputToContain('Token:')
        ->assertSuccessful();

    expect(User::where('email', 'cloud@example.com')->first()->tokens()->count())->toBe(1);
});

test('it finds the existing user when the email differs in case or spacing', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);

    $this->artisan('memory:token', ['email' => ' Me@Example.COM '])
        ->expectsOutputToContain('Token:')
        ->assertSuccessful();

    expect($user->tokens()->count())->toBe(1);
    $this->assertDatabaseCount('users', 1);
});

test('it stores the email of a created user trimmed and lowercased', function () {
    $this->artisan('memory:token', ['email' => ' Cloud@Example.COM ', '--create' => true])
        ->assertSuccessful();

    expect(User::sole()->email)->toBe('cloud@example.com');
});

test('it prints exactly one Token line with a sanctum plain text token and exits 0', function (array $arguments) {
    User::factory()->create(['email' => 'me@example.com']);

    $exit = Artisan::call('memory:token', $arguments + ['email' => 'me@example.com']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toMatch('/\AToken: \d+\|[A-Za-z0-9]{48}\n\z/');
})->with([
    'existing user' => [[]],
    'existing user with --create' => [['--create' => true]],
]);

test('it prints the same Token line for a user created with --create', function () {
    $exit = Artisan::call('memory:token', ['email' => 'new@example.com', '--create' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toMatch('/\AToken: \d+\|[A-Za-z0-9]{48}\n\z/');
    expect(User::sole()->tokens()->sole()->name)->toBe('mcp');
    expect(User::sole()->name)->toBe('new@example.com');
});

test('it prints exactly the failure line and exits 1 for an unknown email when not confirmed', function () {
    $this->artisan('memory:token', ['email' => 'ghost@example.com'])
        ->expectsConfirmation('User ghost@example.com does not exist. Create it?', 'no')
        ->expectsOutput('No token issued.')
        ->assertExitCode(1);
});
