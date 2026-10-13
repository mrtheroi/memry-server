<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes idle tokens and keeps active ones', function () {
    $user = User::factory()->create();
    $idle = $user->createToken('idle')->accessToken;
    $neverUsedOld = $user->createToken('never-used')->accessToken;
    $active = $user->createToken('active')->accessToken;
    $recent = $user->createToken('recent')->accessToken;
    $idle->forceFill(['created_at' => now()->subDays(90), 'last_used_at' => now()->subDays(31)])->save();
    $neverUsedOld->forceFill(['created_at' => now()->subDays(31)])->save();
    $active->forceFill(['created_at' => now()->subDays(90), 'last_used_at' => now()->subDay()])->save();

    $this->artisan('memory:prune-tokens')->expectsOutput('Pruned 2 tokens.')->assertSuccessful();

    expect($user->tokens()->pluck('id')->all())->toEqualCanonicalizing([$active->id, $recent->id]);
});

it('deletes tokens past the absolute lifetime even when recently used', function () {
    config(['sanctum.expiration' => 60 * 24 * 10]);
    $user = User::factory()->create();
    $old = $user->createToken('old')->accessToken;
    $old->forceFill(['created_at' => now()->subDays(11), 'last_used_at' => now()->subDay()])->save();

    $this->artisan('memory:prune-tokens')->assertSuccessful();

    expect($user->tokens()->count())->toBe(0);
});

it('is idempotent', function () {
    $user = User::factory()->create();
    $user->createToken('idle')->accessToken->forceFill(['created_at' => now()->subDays(40)])->save();

    $this->artisan('memory:prune-tokens')->expectsOutput('Pruned 1 tokens.');
    $this->artisan('memory:prune-tokens')->expectsOutput('Pruned 0 tokens.')->assertSuccessful();
});

it('schedules token pruning daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains($event->command, 'memory:prune-tokens'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
