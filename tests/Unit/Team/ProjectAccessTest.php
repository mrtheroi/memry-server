<?php

use App\Team\Domain\ProjectAccess;

it('all() adds no restriction', function () {
    expect(ProjectAccess::all()->isAll())->toBeTrue()
        ->and(ProjectAccess::all()->ids())->toBe([]);
});

it('only() restricts to the given ids', function () {
    $access = ProjectAccess::only(3);

    expect($access->isAll())->toBeFalse()->and($access->ids())->toBe([3]);
});

it('none() restricts to nothing', function () {
    expect(ProjectAccess::none()->isAll())->toBeFalse()
        ->and(ProjectAccess::none()->ids())->toBe([]);
});

it('rejects non-positive ids', function (int $id) {
    ProjectAccess::only($id);
})->with([0, -1])->throws(InvalidArgumentException::class);
