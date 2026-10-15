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

test('by_type counts the range by type, count descending then type ascending', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    foreach (['decision', 'decision', 'decision', 'bugfix', 'bugfix', 'pattern', 'config', 'session_summary'] as $type) {
        memoryOn($ada, '2026-10-10 10:00:00', $type);
    }
    memoryOn($ada, '2026-08-01 10:00:00', 'pattern'); // outside the range

    $byType = readAs(bearerOf($ada), '/api/stats')->assertOk()->json('data.by_type');

    expect($byType)->toBe([
        ['type' => 'decision', 'count' => 3],
        ['type' => 'bugfix', 'count' => 2],
        ['type' => 'config', 'count' => 1],
        ['type' => 'pattern', 'count' => 1],
        ['type' => 'session_summary', 'count' => 1],
    ]);
});

test('by_project is the top 10 of the range, count descending then name ascending', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    foreach (range(1, 12) as $n) { // p01..p12 get one memory each
        memoryOn($ada, '2026-10-10 10:00:00', 'decision', sprintf('p%02d', $n));
    }
    memoryOn($ada, '2026-10-11 10:00:00', 'decision', 'p12');
    memoryOn($ada, '2026-10-11 10:00:00', 'decision', 'p12');
    memoryOn($ada, '2026-10-11 10:00:00', 'decision', 'p11');
    memoryOn($ada, '2026-08-01 10:00:00', 'decision', 'old'); // outside the range

    $byProject = readAs(bearerOf($ada), '/api/stats')->assertOk()->json('data.by_project');

    expect($byProject)->toHaveCount(10)
        ->and(array_slice($byProject, 0, 4))->toBe([
            ['project' => 'p12', 'count' => 3],
            ['project' => 'p11', 'count' => 2],
            ['project' => 'p01', 'count' => 1],
            ['project' => 'p02', 'count' => 1],
        ])
        ->and($byProject[9])->toBe(['project' => 'p08', 'count' => 1]);
});

test('totals are all time, count session summaries inside memories, and ignore the range', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    memoryOn($ada, '2026-10-10 10:00:00', 'decision', 'alpha');
    memoryOn($ada, '2026-10-10 10:00:00', 'session_summary', 'alpha');
    memoryOn($ada, '2025-01-01 10:00:00', 'decision', 'beta');
    memoryOn($ada, '2025-01-01 10:00:00', 'session_summary', 'gamma');

    $data = readAs(bearerOf($ada), '/api/stats?days=7')->assertOk()->json('data');

    expect($data['totals'])->toBe(['memories' => 4, 'projects' => 3, 'session_summaries' => 2])
        ->and(array_sum(array_column($data['daily'], 'memories')))->toBe(2);
});
