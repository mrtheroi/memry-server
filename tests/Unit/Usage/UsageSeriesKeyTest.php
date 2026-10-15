<?php

use App\Usage\Infrastructure\Security\UsageSeriesKey;

it('derives the key from the decoded bytes of a base64 app key', function () {
    $bytes = random_bytes(32);

    expect(UsageSeriesKey::fromAppKey('base64:'.base64_encode($bytes)))->toBe(UsageSeriesKey::fromAppKey($bytes));
});
