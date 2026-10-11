<?php

use App\Team\Domain\Role;
use App\Team\Infrastructure\RoleProjectAccessPolicy;

it('gives owners and admins every project', function (Role $role) {
    expect((new RoleProjectAccessPolicy)->for($role, 1, 2)->isAll())->toBeTrue();
})->with([Role::Owner, Role::Admin]);

it('gives members no project until grants are read', function () {
    $access = (new RoleProjectAccessPolicy)->for(Role::Member, 1, 2);

    expect($access->isAll())->toBeFalse()->and($access->ids())->toBe([]);
});
