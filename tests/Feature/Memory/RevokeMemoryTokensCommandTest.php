<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('it revokes every token of a user by email', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);
    $user->createToken('laptop');
    $user->createToken('desktop');

    $this->artisan('memory:revoke', ['email' => 'me@example.com'])
        ->expectsOutputToContain('Revoked 2 tokens')
        ->assertSuccessful();

    expect($user->tokens()->count())->toBe(0);
});

test('it fails clearly when the user does not exist', function () {
    $this->artisan('memory:revoke', ['email' => 'ghost@example.com'])
        ->expectsOutputToContain('User ghost@example.com not found.')
        ->assertFailed();
});

test('it keeps the tokens of other users', function () {
    User::factory()->create(['email' => 'me@example.com'])->createToken('laptop');
    $other = User::factory()->create();
    $other->createToken('laptop');

    $this->artisan('memory:revoke', ['email' => 'me@example.com'])->assertSuccessful();

    expect($other->tokens()->count())->toBe(1);
});

test('it finds the user when the email differs in case or spacing', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);
    $user->createToken('laptop');

    $this->artisan('memory:revoke', ['email' => ' Me@Example.COM '])
        ->expectsOutputToContain('Revoked 1 tokens.')
        ->assertSuccessful();

    expect($user->tokens()->count())->toBe(0);
});

test('it prints exactly the revoked count and exits 0, also for a user without tokens', function (int $tokens, string $line) {
    $user = User::factory()->create(['email' => 'me@example.com']);
    for ($i = 0; $i < $tokens; $i++) {
        $user->createToken("t{$i}");
    }

    $exit = Artisan::call('memory:revoke', ['email' => 'me@example.com']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toBe($line."\n");
})->with([
    'none' => [0, 'Revoked 0 tokens.'],
    'one' => [1, 'Revoked 1 tokens.'],
    'three' => [3, 'Revoked 3 tokens.'],
]);

test('it prints exactly the not-found line and exits 1', function () {
    $exit = Artisan::call('memory:revoke', ['email' => ' Ghost@Example.COM ']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toBe("User ghost@example.com not found.\n");
});
