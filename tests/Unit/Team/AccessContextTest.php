<?php

use App\Team\Domain\AccessContext;
use App\Team\Domain\ProjectAccess;
use App\Team\Domain\Role;

it('is immutable', function () {
    $ctx = AccessContext::forTeam(1, 2, Role::Owner, ProjectAccess::all());

    $ctx->teamId = 9;
})->throws(Error::class);

it('exposes the team binding', function () {
    $ctx = AccessContext::forTeam(1, 2, Role::Admin, ProjectAccess::only(3));

    expect($ctx->teamId)->toBe(1)
        ->and($ctx->userId)->toBe(2)
        ->and($ctx->role)->toBe(Role::Admin)
        ->and($ctx->projects->ids())->toBe([3])
        ->and($ctx->isSystem())->toBeFalse()
        ->and($ctx->isDenied())->toBeFalse();
});

it('system() is not a team context', function () {
    $team = AccessContext::forTeam(1, 2, Role::Owner, ProjectAccess::all());

    expect(AccessContext::system())->not->toEqual($team)
        ->and(AccessContext::system()->isSystem())->toBeTrue()
        ->and(AccessContext::system()->isDenied())->toBeFalse()
        ->and($team->isSystem())->toBeFalse();
});

it('denied() is denied, with or without a user', function () {
    expect(AccessContext::denied()->isDenied())->toBeTrue()
        ->and(AccessContext::denied()->userId)->toBeNull()
        ->and(AccessContext::denied(5)->userId)->toBe(5)
        ->and(AccessContext::denied(5)->isSystem())->toBeFalse()
        ->and(AccessContext::denied(5)->projects->isAll())->toBeFalse();
});

it('cannot be constructed directly', function () {
    new AccessContext;
})->throws(Error::class);
