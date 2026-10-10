<?php

use App\Memory\Domain\Observation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it persists an observation and finds it by id', function () {
    $user = User::factory()->create();

    $saved = remember($user, 'Use Postgres full-text search', 'tsvector + GIN index');

    $found = recall($user, $saved->id);

    expect($found->title)->toBe('Use Postgres full-text search')
        ->and($found->userId)->toBe($user->id);
});

test('it returns null when the observation does not exist', function () {
    $user = User::factory()->create();

    expect(recall($user, 999))->toBeNull();
});

test('it ranks title matches above content matches', function () {
    $user = User::factory()->create();

    remember($user, 'Deploy checklist', 'Rotate the Sanctum tokens after deploying');
    remember($user, 'Sanctum tokens are hashed', 'Stored with SHA-256');

    $titles = array_map(fn (Observation $o) => $o->title, searchFor($user, 'sanctum tokens', limit: 10));

    expect($titles)->toBe(['Sanctum tokens are hashed', 'Deploy checklist']);
});

test('it returns at most the requested number of results', function () {
    $user = User::factory()->create();

    foreach (['first', 'second', 'third'] as $position) {
        remember($user, "Sanctum tokens {$position}", 'Stored hashed');
    }

    expect(searchFor($user, 'sanctum', limit: 2))->toHaveCount(2);
});

test('it only returns matches of the given project', function () {
    $user = User::factory()->create();

    remember($user, 'Sanctum tokens in dbmcp', 'Stored hashed', project: 'dbmcp');
    remember($user, 'Sanctum tokens elsewhere', 'Stored hashed', project: 'other');

    $titles = array_map(fn (Observation $o) => $o->title, searchFor($user, 'sanctum', limit: 10, project: 'other'));

    expect($titles)->toBe(['Sanctum tokens elsewhere']);
});
