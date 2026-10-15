<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function ingestToken(User $user): string
{
    return $user->createTeamToken('memry-usage', contextFor($user)->teamId, ['usage:write'])->plainTextToken;
}

function claudeCodeExport(string $start = '1760000000000000000', string $end = '1760000060000000000'): array
{
    return otlp(
        sumMetric('claude_code.token.usage', [
            tokenPoint('input', '120', 'claude-opus-4-7', $start, $end),
            tokenPoint('output', '45', 'claude-opus-4-7', $start, $end),
            tokenPoint('cacheRead', '9000', 'claude-sonnet-4-6', $start, $end),
        ]),
        sumMetric('claude_code.cost.usage', [[
            'attributes' => attrs(['model' => 'claude-opus-4-7', 'query_source' => 'main', 'session.id' => 'sess-1', 'user.email' => 'someone@else.test']),
            'startTimeUnixNano' => $start,
            'timeUnixNano' => $end,
            'asDouble' => 0.0421,
        ]]),
    );
}

function rawIngest(string $token, string $body, string $contentType = 'application/json'): TestResponse
{
    return test()->call('POST', '/api/otlp/v1/metrics', [], [], [], [
        'CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}",
    ], $body);
}

function usageTotals(): array
{
    return [
        'rows' => DB::table('usage_points')->count(),
        'tokens' => (string) DB::table('usage_points')->where('metric', 'tokens')->sum('value'),
        'cost' => (string) DB::table('usage_points')->where('metric', 'cost')->sum('value'),
    ];
}

test('a usage token stores the points of a claude code export', function () {
    $user = User::factory()->create();

    $this->withToken(ingestToken($user))
        ->postJson('/api/otlp/v1/metrics', claudeCodeExport())
        ->assertOk()
        ->assertExactJson([]);

    $rows = DB::table('usage_points')->orderBy('id')->get();
    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and($rows->pluck('team_id')->unique()->all())->toBe([contextFor($user)->teamId])
        ->and(usageTotals())->toBe(['rows' => 4, 'tokens' => '9165.000000', 'cost' => '0.042100']);

    $input = $rows->firstWhere('token_type', 'input');
    expect($input->metric)->toBe('tokens')
        ->and($input->model)->toBe('claude-opus-4-7')
        ->and($input->query_source)->toBe('main')
        ->and($input->session_id)->toBe('sess-1')
        ->and($input->start_unix_nano)->toBe(1760000000000000000)
        ->and($input->end_unix_nano)->toBe(1760000060000000000);
});

test('the same export posted twice is stored once and the totals do not change', function () {
    $token = ingestToken(User::factory()->create());

    $this->withToken($token)->postJson('/api/otlp/v1/metrics', claudeCodeExport())->assertOk();
    $once = usageTotals();

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/otlp/v1/metrics', claudeCodeExport())->assertOk()->assertExactJson([]);

    expect(usageTotals())->toBe($once)->and($once['rows'])->toBe(4);
});

test('a retried export with new points stores only the new ones', function () {
    $token = ingestToken(User::factory()->create());
    $this->withToken($token)->postJson('/api/otlp/v1/metrics', claudeCodeExport())->assertOk();

    app('auth')->forgetGuards();
    $retry = claudeCodeExport();
    $retry['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0]['sum']['dataPoints'][] = tokenPoint('cacheCreation', '300');
    $this->withToken($token)->postJson('/api/otlp/v1/metrics', $retry)->assertOk();

    expect(usageTotals()['rows'])->toBe(5);
});

test('two windows of the same series are both stored', function () {
    $token = ingestToken(User::factory()->create());

    $this->withToken($token)->postJson('/api/otlp/v1/metrics', claudeCodeExport('1760000000000000000', '1760000060000000000'))->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/otlp/v1/metrics', claudeCodeExport('1760000060000000000', '1760000120000000000'))->assertOk();

    expect(usageTotals())->toBe(['rows' => 8, 'tokens' => '18330.000000', 'cost' => '0.084200']);
});

test('points with no optional attributes are deduplicated too', function () {
    $token = ingestToken(User::factory()->create());
    $bare = otlp(sumMetric('claude_code.cost.usage', [[
        'attributes' => attrs(['model' => 'm']), 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asDouble' => 1.5,
    ]]));

    $this->withToken($token)->postJson('/api/otlp/v1/metrics', $bare)->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/otlp/v1/metrics', $bare)->assertOk();

    expect(usageTotals()['rows'])->toBe(1);
});

test('identity attributes in the payload are never trusted', function () {
    $user = User::factory()->create();
    $victim = User::factory()->create(['email' => 'victim@example.test']);
    $export = claudeCodeExport();
    $identity = attrs(['user.email' => $victim->email, 'user.id' => (string) $victim->id, 'user.account_uuid' => 'x', 'organization.id' => 'org-9', 'team_id' => (string) contextFor($victim)->teamId, 'user_id' => (string) $victim->id]);
    $export['resourceMetrics'][0]['resource']['attributes'] = [...$export['resourceMetrics'][0]['resource']['attributes'], ...$identity];
    foreach ($export['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] as &$metric) {
        foreach ($metric['sum']['dataPoints'] as &$point) {
            $point['attributes'] = [...$point['attributes'], ...$identity];
        }
    }
    unset($metric, $point);

    $this->withToken(ingestToken($user))->postJson('/api/otlp/v1/metrics', $export)->assertOk();

    expect(DB::table('usage_points')->where('user_id', $victim->id)->count())->toBe(0)
        ->and(DB::table('usage_points')->where('user_id', $user->id)->count())->toBe(4)
        ->and(DB::table('usage_points')->where('team_id', contextFor($victim)->teamId)->count())->toBe(0);
});

test('a request without a token is unauthenticated', function () {
    $this->postJson('/api/otlp/v1/metrics', claudeCodeExport())->assertUnauthorized();

    expect(usageTotals()['rows'])->toBe(0);
});

test('a token without the usage ability is forbidden and stores nothing', function () {
    $user = User::factory()->create();
    $token = $user->createTeamToken('limited', contextFor($user)->teamId, ['memories:read'])->plainTextToken;

    $this->withToken($token)->postJson('/api/otlp/v1/metrics', claudeCodeExport())->assertForbidden();

    expect(usageTotals()['rows'])->toBe(0);
});

test('a full-access token is accepted too', function () {
    $user = User::factory()->create();
    $token = $user->createTeamToken('memry-cli', contextFor($user)->teamId)->plainTextToken;

    $this->withToken($token)->postJson('/api/otlp/v1/metrics', claudeCodeExport())->assertOk();

    expect(usageTotals()['rows'])->toBe(4);
});

test('a denied context is forbidden and stores nothing', function () {
    $user = User::factory()->create();
    $strangersTeam = contextFor(User::factory()->create())->teamId;
    $token = $user->createTeamToken('memry-usage', $strangersTeam, ['usage:write'])->plainTextToken;

    $this->withToken($token)->postJson('/api/otlp/v1/metrics', claudeCodeExport())->assertForbidden();

    expect(usageTotals()['rows'])->toBe(0);
});

test('cumulative points are skipped and reported as a partial success', function () {
    $user = User::factory()->create();
    $export = otlp(
        sumMetric('claude_code.token.usage', [tokenPoint('input', '10'), tokenPoint('output', '20')], 2),
        sumMetric('claude_code.token.usage', [tokenPoint('cacheRead', '5')]),
    );

    $this->withToken(ingestToken($user))->postJson('/api/otlp/v1/metrics', $export)
        ->assertOk()
        ->assertJsonPath('partialSuccess.rejectedDataPoints', '2')
        ->assertJsonPath('partialSuccess.errorMessage', 'memry expects delta temporality; 2 cumulative data points were skipped. Set OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta.');

    expect(usageTotals()['rows'])->toBe(1);
});

test('only application/json is accepted', function (string $contentType) {
    $token = ingestToken(User::factory()->create());

    rawIngest($token, json_encode(claudeCodeExport()), $contentType)->assertStatus(415);

    expect(usageTotals()['rows'])->toBe(0);
})->with(['application/x-protobuf', 'text/plain', 'application/jsonx']);

test('a content type with a charset parameter is accepted', function () {
    $token = ingestToken(User::factory()->create());

    rawIngest($token, json_encode(claudeCodeExport()), 'application/json; charset=utf-8')->assertOk();
});

test('a body over 1 MB is rejected before decoding', function () {
    $token = ingestToken(User::factory()->create());
    // Not even valid JSON: a 413 proves the size check ran before any decoding.
    $body = str_repeat('x', 1024 * 1024 + 1);

    rawIngest($token, $body)->assertStatus(413);
});

test('malformed json or a wrong shape is a 400', function (string $body) {
    $token = ingestToken(User::factory()->create());

    rawIngest($token, $body)->assertStatus(400)->assertJsonStructure(['message']);

    expect(usageTotals()['rows'])->toBe(0);
})->with([
    'not json' => '{"resourceMetrics": [',
    'empty body' => '',
    'a scalar' => '"hello"',
    'a list' => '[1,2]',
    'wrong shape' => '{"resourceMetrics": "x"}',
]);

test('more than 5000 points is a 413', function () {
    $token = ingestToken(User::factory()->create());
    $point = ['attributes' => [['key' => 'type', 'value' => ['stringValue' => 'input']]], 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '1'];
    $export = otlp(sumMetric('claude_code.token.usage', array_fill(0, 5001, $point)));

    $this->withToken($token)->postJson('/api/otlp/v1/metrics', $export)->assertStatus(413);

    expect(usageTotals()['rows'])->toBe(0);
});

test('the otlp rate limit is 120 requests per minute per user', function () {
    $token = ingestToken(User::factory()->create());

    foreach (range(1, 120) as $_) {
        app('auth')->forgetGuards();
        $this->withToken($token)->postJson('/api/otlp/v1/metrics', [])->assertOk();
    }

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/otlp/v1/metrics', [])->assertStatus(429);
});

test('absent optional attributes are stored as empty strings so the unique index covers them', function () {
    $token = ingestToken(User::factory()->create());
    $bare = otlp(sumMetric('claude_code.cost.usage', [[
        'attributes' => attrs(['model' => 'm']), 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asDouble' => 1.5,
    ]]));

    $this->withToken($token)->postJson('/api/otlp/v1/metrics', $bare)->assertOk();

    $row = DB::table('usage_points')->sole();
    expect($row->token_type)->toBe('')->and($row->query_source)->toBe('')->and($row->session_id)->toBe('');
});

test('series that differ only by another attribute are all stored and summed', function () {
    $token = ingestToken(User::factory()->create());
    $point = fn (string $extraKey, string $extraValue) => [
        'attributes' => attrs(['type' => 'input', 'model' => 'claude-opus-4-7', 'query_source' => 'main', 'session.id' => 's', $extraKey => $extraValue]),
        'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '100',
    ];
    $export = otlp(sumMetric('claude_code.token.usage', [
        $point('speed', 'fast'), $point('speed', 'normal'), $point('skill.name', 'tdd'), $point('skill.name', 'sdd'),
    ]));

    $this->withToken($token)->postJson('/api/otlp/v1/metrics', $export)->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/otlp/v1/metrics', $export)->assertOk();

    expect(usageTotals())->toBe(['rows' => 4, 'tokens' => '400.000000', 'cost' => '0']);
});

test('the same export sent through two teams of one user is stored once per team', function () {
    $user = User::factory()->create();
    $teamA = contextFor($user)->teamId;
    $teamB = DB::table('teams')->insertGetId(['owner_id' => User::factory()->create()->id, 'slug' => 'team-b', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('team_user')->insert(['team_id' => $teamB, 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);

    foreach ([$teamA, $teamB] as $teamId) {
        app('auth')->forgetGuards();
        $token = $user->createTeamToken("usage-{$teamId}", $teamId, ['usage:write'])->plainTextToken;
        $this->withToken($token)->postJson('/api/otlp/v1/metrics', claudeCodeExport())->assertOk();
    }

    expect(usageTotals()['rows'])->toBe(8)
        ->and(DB::table('usage_points')->where('team_id', $teamB)->count())->toBe(4);
});

test('invalid values are skipped and reported, never a server error', function () {
    $user = User::factory()->create();
    $export = otlp(
        sumMetric('claude_code.token.usage', [
            [...tokenPoint('input', '1'), 'asInt' => 'abc'],
            [...tokenPoint('output', '1'), 'asInt' => [1]],
            [...tokenPoint('cacheRead', '1'), 'asInt' => '-5'],
            tokenPoint('cacheCreation', '7'),
        ]),
        sumMetric('claude_code.cost.usage', [
            ['attributes' => attrs(['model' => 'm']), 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asDouble' => 'NaN'],
            ['attributes' => attrs(['model' => 'm']), 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asDouble' => 'Infinity'],
        ]),
        sumMetric('claude_code.token.usage', [tokenPoint('input', '1')], 2),
    );

    $this->withToken(ingestToken($user))->postJson('/api/otlp/v1/metrics', $export)
        ->assertOk()
        ->assertJsonPath('partialSuccess.rejectedDataPoints', '6')
        ->assertJsonPath('partialSuccess.errorMessage', 'memry expects delta temporality; 1 cumulative data points were skipped. Set OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta. 5 data points with an invalid value were skipped; values must be non-negative numbers.');

    expect(usageTotals())->toBe(['rows' => 1, 'tokens' => '7.000000', 'cost' => '0']);
});

test('the int64 maximum is stored without overflow', function () {
    $this->withToken(ingestToken(User::factory()->create()))
        ->postJson('/api/otlp/v1/metrics', otlp(sumMetric('claude_code.token.usage', [tokenPoint('input', '9223372036854775807')])))
        ->assertOk()->assertExactJson([]);

    expect((string) DB::table('usage_points')->sole()->value)->toBe('9223372036854775807.000000');
});

test('a malformed metric name or structure is a 400, never a 500', function (string $metric) {
    $token = ingestToken(User::factory()->create());
    $body = '{"resourceMetrics":[{"scopeMetrics":[{"metrics":['.$metric.']}]}]}';

    rawIngest($token, $body)->assertStatus(400)->assertJsonStructure(['message']);

    expect(usageTotals()['rows'])->toBe(0);
})->with([
    'name array' => '{"name":[],"sum":{}}',
    'name object' => '{"name":{"a":1},"sum":{}}',
    'name number' => '{"name":5,"sum":{}}',
    'sum string' => '{"name":"claude_code.token.usage","sum":"x"}',
    'temporality array' => '{"name":"claude_code.token.usage","sum":{"aggregationTemporality":[],"dataPoints":[]}}',
]);

test('rejected data points still count against the 5000 cap', function () {
    $token = ingestToken(User::factory()->create());
    $export = otlp(sumMetric('claude_code.token.usage', array_fill(0, 5001, tokenPoint('input', '1')), 2));

    $this->withToken($token)->postJson('/api/otlp/v1/metrics', $export)->assertStatus(413);
});
