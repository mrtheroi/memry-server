<?php

use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

function memoryOn(User $author, string $createdAt, string $type = 'decision', string $project = 'dbmcp', string $scope = 'project'): void
{
    $saved = (new EloquentMemoryRepository)->save(new Observation(
        userId: $author->id, sessionId: 's', type: $type, title: 'Stat '.uniqid(), content: 'c',
        project: $project, scope: $scope, topicKey: null, teamId: contextFor($author)->teamId,
    ));
    DB::table('observations')->where('id', $saved->id)->update(['created_at' => $createdAt]);
}

test('daily counts memories on the UTC day of created_at', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    memoryOn($ada, '2026-10-14 00:00:00');
    memoryOn($ada, '2026-10-13 23:59:59');
    memoryOn($ada, '2026-10-13 00:00:00');
    memoryOn($ada, '2026-10-13 12:00:00');
    memoryOn($ada, '2026-09-15 00:00:00');
    memoryOn($ada, '2026-09-14 23:59:59'); // before the range

    $daily = collect(readAs(bearerOf($ada), '/api/stats')->assertOk()->json('data.daily'))->pluck('memories', 'date');

    expect($daily['2026-10-14'])->toBe(1)
        ->and($daily['2026-10-13'])->toBe(3)
        ->and($daily['2026-09-15'])->toBe(1)
        ->and($daily['2026-10-12'])->toBe(0)
        ->and($daily->sum())->toBe(5)
        ->and($daily->keys()->all())->toBe($daily->keys()->sort()->values()->all());
});

test('daily counts session summaries separately and memories include them', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    memoryOn($ada, '2026-10-14 08:00:00', 'session_summary');
    memoryOn($ada, '2026-10-14 09:00:00', 'session_summary');
    memoryOn($ada, '2026-10-14 10:00:00', 'decision');
    memoryOn($ada, '2026-10-13 10:00:00', 'decision');

    $daily = collect(readAs(bearerOf($ada), '/api/stats')->assertOk()->json('data.daily'))->keyBy('date');

    expect($daily['2026-10-14'])->toBe(['date' => '2026-10-14', 'memories' => 3, 'session_summaries' => 2])
        ->and($daily['2026-10-13'])->toBe(['date' => '2026-10-13', 'memories' => 1, 'session_summaries' => 0]);
});
