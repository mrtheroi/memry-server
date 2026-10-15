<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the stats answer 401 without credentials', function () {
    $this->getJson('/api/stats')->assertUnauthorized();
});

test('days must be an integer from 7 to 365', function (mixed $days) {
    $ada = User::factory()->create();

    readAs(bearerOf($ada), '/api/stats?days='.$days)->assertUnprocessable()->assertJsonValidationErrors('days');
})->with(['6', '366', '0', '-1', 'abc', '10.5']);

test('with no memories the default range is 30 UTC days, zero-filled oldest first', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();

    $data = readAs(bearerOf($ada), '/api/stats')->assertOk()->json('data');

    expect($data['range'])->toBe(['days' => 30, 'from' => '2026-09-15', 'to' => '2026-10-14'])
        ->and($data['totals'])->toBe(['memories' => 0, 'projects' => 0, 'session_summaries' => 0])
        ->and($data['daily'])->toHaveCount(30)
        ->and($data['daily'][0])->toBe(['date' => '2026-09-15', 'memories' => 0, 'session_summaries' => 0])
        ->and($data['daily'][29])->toBe(['date' => '2026-10-14', 'memories' => 0, 'session_summaries' => 0])
        ->and($data['by_type'])->toBe([])
        ->and($data['by_project'])->toBe([]);
});

test('a custom days value sets the range', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();

    $data = readAs(bearerOf($ada), '/api/stats?days=7')->assertOk()->json('data');

    expect($data['range'])->toBe(['days' => 7, 'from' => '2026-10-08', 'to' => '2026-10-14'])
        ->and($data['daily'])->toHaveCount(7);
});
