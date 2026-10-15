<?php

use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Usage\Application\UsageReport;
use App\Usage\Domain\UsageMetric;
use App\Usage\Domain\UsagePoint;
use App\Usage\Domain\UsageRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('the usage read answers 401 without credentials', function () {
    $this->getJson('/api/usage')->assertUnauthorized();
});

test('a usage:write-only token cannot read usage', function () {
    $user = User::factory()->create();
    $token = $user->createTeamToken('memry-usage', contextFor($user)->teamId, ['usage:write'])->plainTextToken;

    $this->withToken($token)->getJson('/api/usage')->assertForbidden();
});

test('days must be an integer from 1 to 90', function (mixed $days) {
    $ada = User::factory()->create();

    readAs(bearerOf($ada), '/api/usage?days='.$days)->assertUnprocessable()->assertJsonValidationErrors('days');
})->with(['0', '91', '-1', 'abc', '10.5', 'empty' => '', 'blank' => '%20%20']);

test('with no usage the default window is 30 UTC days, zero-filled oldest first', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();

    $body = readAs(bearerOf($ada), '/api/usage')->assertOk()->json();

    $zero = ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheCreation' => 0];
    expect($body['days'])->toBe(30)
        ->and($body['from'])->toBe('2026-09-15')
        ->and($body['to'])->toBe('2026-10-14')
        ->and($body['daily'])->toHaveCount(30)
        ->and($body['daily'][0])->toEqual(['date' => '2026-09-15', 'tokens' => $zero, 'cost_usd' => 0.0])
        ->and($body['daily'][29])->toEqual(['date' => '2026-10-14', 'tokens' => $zero, 'cost_usd' => 0.0])
        ->and($body['by_model'])->toBe([])
        ->and($body['totals'])->toEqual(['tokens' => 0, 'cost_usd' => 0.0]);
});

test('days sets the window, today included', function (int $days, string $from) {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();

    $body = readAs(bearerOf($ada), '/api/usage?days='.$days)->assertOk()->json();

    expect([$body['days'], $body['from'], $body['to']])->toBe([$days, $from, '2026-10-14'])
        ->and($body['daily'])->toHaveCount($days);
})->with([[1, '2026-10-14'], [7, '2026-10-08'], [90, '2026-07-17']]);

/** Nanoseconds since the epoch of a UTC timestamp. */
function nanosAt(string $utc): string
{
    return Carbon::parse($utc, 'UTC')->getTimestamp().'000000000';
}

/** Stores one point through the ingest port, ending at $endUtc (the point covers the minute before). */
function usagePoint(int $teamId, int $userId, string $endUtc, UsageMetric $metric, string $value, ?string $tokenType = null, string $model = 'claude-opus-4-7'): void
{
    $end = nanosAt($endUtc);
    $start = (string) ((int) $end - 60_000_000_000);

    app(UsageRepository::class)->record($teamId, $userId, [new UsagePoint(
        $metric, $model, $tokenType, 'main', 'sess-1', $start, $end, $value, hash('sha256', uniqid('', true)),
    )]);
}

function ownUsage(User $user, string $endUtc, UsageMetric $metric, string $value, ?string $tokenType = null, string $model = 'claude-opus-4-7'): void
{
    usagePoint(contextFor($user)->teamId, $user->id, $endUtc, $metric, $value, $tokenType, $model);
}

test('daily sums tokens by type and cost on the UTC day of the point end time', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    ownUsage($ada, '2026-10-14 00:00:00', UsageMetric::Tokens, '100', 'input');
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '20', 'input');
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '7', 'output');
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '9000', 'cacheRead');
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '300', 'cacheCreation');
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Cost, '0.0421');
    ownUsage($ada, '2026-10-14 10:00:00', UsageMetric::Cost, '0.0000015');
    ownUsage($ada, '2026-10-13 23:59:59', UsageMetric::Tokens, '5', 'output');
    ownUsage($ada, '2026-09-15 00:00:00', UsageMetric::Tokens, '1', 'input');
    ownUsage($ada, '2026-09-14 23:59:59', UsageMetric::Tokens, '999', 'input'); // before the window

    $daily = collect(readAs(bearerOf($ada), '/api/usage')->assertOk()->json('daily'))->keyBy('date');

    expect($daily['2026-10-14'])->toEqual(['date' => '2026-10-14', 'tokens' => ['input' => 120, 'output' => 7, 'cacheRead' => 9000, 'cacheCreation' => 300], 'cost_usd' => 0.042102])
        ->and($daily['2026-10-13']['tokens'])->toBe(['input' => 0, 'output' => 5, 'cacheRead' => 0, 'cacheCreation' => 0])
        ->and($daily['2026-09-15']['tokens']['input'])->toBe(1)
        ->and($daily->sum(fn ($day) => array_sum($day['tokens'])))->toBe(9433)
        ->and($daily->keys()->all())->toBe($daily->keys()->sort()->values()->all());
});

test('by_model and totals sum the window, most tokens first, and count token types daily leaves out', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '100', 'input', 'claude-opus-4-7');
    ownUsage($ada, '2026-10-13 09:00:00', UsageMetric::Tokens, '50', 'output', 'claude-opus-4-7');
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Cost, '0.5', model: 'claude-opus-4-7');
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '9000', 'cacheRead', 'claude-sonnet-4-6');
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '25', 'reasoning', 'claude-sonnet-4-6'); // unknown type
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Cost, '0.0000004', model: 'claude-sonnet-4-6');
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Cost, '0.25', model: 'claude-haiku-4-5'); // cost only
    ownUsage($ada, '2026-09-01 09:00:00', UsageMetric::Tokens, '777', 'input', 'claude-opus-4-7'); // before the window

    $body = readAs(bearerOf($ada), '/api/usage')->assertOk()->json();

    expect($body['by_model'])->toEqual([
        ['model' => 'claude-sonnet-4-6', 'tokens' => 9025, 'cost_usd' => 0.0],
        ['model' => 'claude-opus-4-7', 'tokens' => 150, 'cost_usd' => 0.5],
        ['model' => 'claude-haiku-4-5', 'tokens' => 0, 'cost_usd' => 0.25],
    ])
        ->and($body['totals'])->toEqual(['tokens' => 9175, 'cost_usd' => 0.75])
        ->and(collect($body['daily'])->sum(fn ($day) => array_sum($day['tokens'])))->toBe(9150);
});

test('another user\'s usage on the same team never appears', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    $bob = User::factory()->create();
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '10', 'input');
    usagePoint(contextFor($ada)->teamId, $bob->id, '2026-10-14 09:00:00', UsageMetric::Tokens, '500', 'input');
    usagePoint(contextFor($ada)->teamId, $bob->id, '2026-10-14 09:00:00', UsageMetric::Cost, '3');
    ownUsage($bob, '2026-10-14 09:00:00', UsageMetric::Tokens, '700', 'input');

    $body = readAs(bearerOf($ada), '/api/usage')->assertOk()->json();

    expect($body['totals'])->toEqual(['tokens' => 10, 'cost_usd' => 0.0])
        ->and(collect($body['daily'])->last()['tokens']['input'])->toBe(10);
});

test('the caller\'s usage on another team never appears', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    $otherTeam = sharedTeamOf($ada);
    expect($otherTeam)->not->toBe(contextFor($ada)->teamId);
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '10', 'input');
    usagePoint($otherTeam, $ada->id, '2026-10-14 09:00:00', UsageMetric::Tokens, '500', 'input', 'claude-sonnet-4-6');

    $body = readAs(bearerOf($ada), '/api/usage')->assertOk()->json();

    expect($body['totals']['tokens'])->toBe(10)
        ->and(collect($body['by_model'])->pluck('model')->all())->toBe(['claude-opus-4-7']);
});

test('a dashboard session reads its own usage', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '42', 'output');

    sessionOf($ada)->getJson('/api/usage?days=1')->assertOk()->assertJsonPath('daily.0.tokens.output', 42);
});

test('a caller with no team, or the system context, gets zeros and an empty by_model', function (string $kind) {
    $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
    $ada = User::factory()->create();
    ownUsage($ada, '2026-10-14 09:00:00', UsageMetric::Tokens, '10', 'input');

    $context = $kind === 'denied' ? AccessContext::denied($ada->id) : AccessContext::system();
    $report = app(UsageReport::class)($context, 7);

    expect($report['daily'])->toHaveCount(7)
        ->and(collect($report['daily'])->sum(fn ($day) => array_sum($day['tokens'])))->toBe(0)
        ->and($report['by_model'])->toBe([])
        ->and($report['totals'])->toEqual(['tokens' => 0, 'cost_usd' => 0.0]);
})->with(['denied', 'system']);
