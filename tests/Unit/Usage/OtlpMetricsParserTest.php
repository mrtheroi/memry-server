<?php

use App\Usage\Domain\InvalidOtlpPayload;
use App\Usage\Domain\TooManyPoints;
use App\Usage\Domain\UsageMetric;

it('parses claude code token and cost delta points', function () {
    $parsed = parseOtlp(otlp(
        sumMetric('claude_code.token.usage', [tokenPoint('input', '120'), tokenPoint('cacheRead', '4500', 'claude-sonnet-4-6')]),
        sumMetric('claude_code.cost.usage', [[
            'attributes' => attrs(['model' => 'claude-opus-4-7', 'query_source' => 'main', 'session.id' => 'sess-1']),
            'startTimeUnixNano' => '1760000000000000000',
            'timeUnixNano' => '1760000060000000000',
            'asDouble' => 0.0123,
        ]]),
    ));

    expect($parsed->points)->toHaveCount(3)
        ->and($parsed->rejectedNonDelta)->toBe(0);

    [$input, $cacheRead, $cost] = $parsed->points;
    expect($input->metric)->toBe(UsageMetric::Tokens)
        ->and($input->tokenType)->toBe('input')
        ->and($input->model)->toBe('claude-opus-4-7')
        ->and($input->querySource)->toBe('main')
        ->and($input->sessionId)->toBe('sess-1')
        ->and($input->startUnixNano)->toBe('1760000000000000000')
        ->and($input->endUnixNano)->toBe('1760000060000000000')
        ->and($input->value)->toBe('120')
        ->and($cacheRead->model)->toBe('claude-sonnet-4-6')
        ->and($cacheRead->value)->toBe('4500')
        ->and($cost->metric)->toBe(UsageMetric::Cost)
        ->and($cost->tokenType)->toBeNull()
        ->and($cost->value)->toBe('0.012300');
});

it('ignores metrics it does not know', function () {
    $parsed = parseOtlp(otlp(
        sumMetric('claude_code.session.count', [['attributes' => [], 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '1']]),
        ['name' => 'claude_code.active_time.total', 'gauge' => ['dataPoints' => []]],
        sumMetric('claude_code.token.usage', [tokenPoint('output', '7')]),
    ));

    expect($parsed->points)->toHaveCount(1)
        ->and($parsed->points[0]->tokenType)->toBe('output');
});

it('skips cumulative points and counts them as rejected', function () {
    $parsed = parseOtlp(otlp(
        sumMetric('claude_code.token.usage', [tokenPoint('input', '10'), tokenPoint('output', '20')], 2),
        sumMetric('claude_code.cost.usage', [tokenPoint('input', '1')], 'AGGREGATION_TEMPORALITY_CUMULATIVE'),
        sumMetric('claude_code.token.usage', [tokenPoint('input', '5')], 'AGGREGATION_TEMPORALITY_DELTA'),
    ));

    expect($parsed->points)->toHaveCount(1)
        ->and($parsed->points[0]->value)->toBe('5')
        ->and($parsed->rejectedNonDelta)->toBe(3);
});

it('skips zero values and parses asInt strings and asDouble numbers', function () {
    $double = fn (float|int $v) => ['attributes' => attrs(['model' => 'm']), 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asDouble' => $v];

    $parsed = parseOtlp(otlp(
        sumMetric('claude_code.token.usage', [tokenPoint('input', '0'), tokenPoint('output', '9007199254740993')]),
        sumMetric('claude_code.cost.usage', [$double(0), $double(0.0000001), $double(2), $double(0.5)]),
    ));

    expect(array_map(fn ($p) => $p->value, $parsed->points))->toBe(['9007199254740993', '2.000000', '0.500000']);
});

it('caps attribute lengths, defaults a missing model and skips token points of an unknown type', function () {
    $long = str_repeat('é', 300);
    $noModel = ['attributes' => attrs(['type' => 'input']), 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '3'];

    $parsed = parseOtlp(otlp(sumMetric('claude_code.token.usage', [
        tokenPoint('input', '1', $long),
        $noModel,
        tokenPoint('bogus', '4'),
        ['attributes' => attrs(['type' => 'input', 'model' => 'm', 'session.id' => $long, 'query_source' => $long]), 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '5'],
    ])));

    expect($parsed->points)->toHaveCount(3)
        ->and(mb_strlen($parsed->points[0]->model))->toBe(200)
        ->and($parsed->points[1]->model)->toBe('unknown')
        ->and(mb_strlen($parsed->points[2]->sessionId))->toBe(200)
        ->and(mb_strlen($parsed->points[2]->querySource))->toBe(200);
});

it('accepts an empty export request', function () {
    expect(parseOtlp(new stdClass)->points)->toBe([])
        ->and(parseOtlp(['resourceMetrics' => []])->points)->toBe([]);
});

it('rejects a payload of the wrong shape', function (array $payload) {
    parseOtlp($payload);
})->with([
    'top level list' => [[1, 2]],
    'resourceMetrics not a list' => [['resourceMetrics' => 'x']],
    'scopeMetrics not a list' => [['resourceMetrics' => [['scopeMetrics' => 'x']]]],
    'metrics not a list' => [['resourceMetrics' => [['scopeMetrics' => [['metrics' => 'x']]]]]],
    'dataPoints not a list' => [otlp(['name' => 'claude_code.token.usage', 'sum' => ['aggregationTemporality' => 1, 'dataPoints' => 'x']])],
    'resource not an object' => [['resourceMetrics' => ['x']]],
    'data point not an object' => [otlp(sumMetric('claude_code.token.usage', ['x']))],
    'name array' => [otlp(['name' => [], 'sum' => ['aggregationTemporality' => 1, 'dataPoints' => []]])],
    'name object' => [otlp(['name' => ['a' => 1], 'sum' => []])],
    'name number' => [otlp(['name' => 5, 'sum' => []])],
    'sum string' => [otlp(['name' => 'claude_code.token.usage', 'sum' => 'x'])],
    'dataPoints object' => [otlp(['name' => 'claude_code.token.usage', 'sum' => ['aggregationTemporality' => 1, 'dataPoints' => ['a' => 1]]])],
    'aggregationTemporality array' => [otlp(sumMetric('claude_code.token.usage', [tokenPoint('input', '1')], []))],
    'aggregationTemporality bool' => [otlp(sumMetric('claude_code.token.usage', [tokenPoint('input', '1')], true))],
    'attribute key number' => [otlp(sumMetric('claude_code.token.usage', [[...tokenPoint('input', '1'), 'attributes' => [['key' => 5, 'value' => ['stringValue' => 'x']]]]]))],
    'attribute key array' => [otlp(sumMetric('claude_code.token.usage', [[...tokenPoint('input', '1'), 'attributes' => [['key' => [], 'value' => ['stringValue' => 'x']]]]]))],
    'attribute value string' => [otlp(sumMetric('claude_code.token.usage', [[...tokenPoint('input', '1'), 'attributes' => [['key' => 'a', 'value' => 'x']]]]))],
    'attribute not an object' => [otlp(sumMetric('claude_code.token.usage', [[...tokenPoint('input', '1'), 'attributes' => ['x']]]))],
    'scope not an object' => [['resourceMetrics' => [['scopeMetrics' => ['x']]]]],
    'attributes not a list' => [otlp(sumMetric('claude_code.token.usage', [['attributes' => 'x', 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '1']]))],
])->throws(InvalidOtlpPayload::class);

it('keeps only data points with a valid time window', function () {
    $withTime = fn (mixed $start, mixed $end) => ['attributes' => attrs(['type' => 'input', 'model' => 'm']), 'startTimeUnixNano' => $start, 'timeUnixNano' => $end, 'asInt' => '1'];

    $parsed = parseOtlp(otlp(sumMetric('claude_code.token.usage', [
        $withTime('1760000000000000000', '1760000060000000000'),
        $withTime(1760000000000000000, 1760000060000000000),
        $withTime('abc', '2'),
        $withTime('1', '99999999999999999999'),
        $withTime(null, '2'),
        ['attributes' => [], 'asInt' => '1'],
    ])));

    expect($parsed->points)->toHaveCount(2)
        ->and($parsed->points[1]->startUnixNano)->toBe('1760000000000000000')
        ->and($parsed->points[1]->endUnixNano)->toBe('1760000060000000000');
});

it('refuses more than 5000 points in one request', function () {
    $points = array_fill(0, 5001, tokenPoint('input', '1'));

    parseOtlp(otlp(sumMetric('claude_code.token.usage', $points)));
})->throws(TooManyPoints::class);

it('accepts exactly 5000 points', function () {
    $points = array_fill(0, 5000, tokenPoint('input', '1'));

    expect(parseOtlp(otlp(sumMetric('claude_code.token.usage', $points)))->points)->toHaveCount(5000);
});

it('hashes the whole attribute set of the data point as the series identity', function () {
    $point = fn (array $extra, array $resource = ['service.name' => 'claude-code']) => [
        'resourceMetrics' => [[
            'resource' => ['attributes' => attrs($resource)],
            'scopeMetrics' => [['metrics' => [sumMetric('claude_code.token.usage', [[
                'attributes' => attrs(['type' => 'input', 'model' => 'm', ...$extra]),
                'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '5',
            ]])]]],
        ]],
    ];
    $hash = fn (array $payload) => parseOtlp($payload)->points[0]->seriesHash;

    $base = $hash($point([]));

    expect($base)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash($point(['speed' => 'fast'])))->not->toBe($base)
        ->and($hash($point(['skill.name' => 'tdd'])))->not->toBe($hash($point(['skill.name' => 'sdd'])))
        ->and($hash($point(['speed' => 'fast', 'effort' => 'high'])))->toBe($hash($point(['effort' => 'high', 'speed' => 'fast'])))
        ->and($hash($point([], ['user.email' => 'a@b.test'])))->toBe($base);
});

it('normalizes attribute values of every OTLP type to strings in the identity', function () {
    $withValue = fn (array $value) => parseOtlp(otlp(sumMetric('claude_code.token.usage', [[
        'attributes' => [['key' => 'type', 'value' => ['stringValue' => 'input']], ['key' => 'flag', 'value' => $value]],
        'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '5',
    ]])))->points[0]->seriesHash;

    expect($withValue(['intValue' => '7']))->toBe($withValue(['stringValue' => '7']))
        ->and($withValue(['boolValue' => true]))->toBe($withValue(['stringValue' => 'true']))
        ->and($withValue(['doubleValue' => 1.5]))->toBe($withValue(['stringValue' => '1.5']))
        ->and($withValue(['stringValue' => 'a']))->not->toBe($withValue(['stringValue' => 'b']));
});

it('rejects and counts data points with an invalid value instead of failing', function (array $value) {
    $parsed = parseOtlp(otlp(sumMetric('claude_code.token.usage', [
        [...tokenPoint('input', '1'), 'asInt' => null, ...$value],
        tokenPoint('output', '3'),
    ])));

    expect($parsed->points)->toHaveCount(1)
        ->and($parsed->rejectedInvalid)->toBe(1)
        ->and($parsed->rejectedNonDelta)->toBe(0);
})->with([
    'non numeric asInt' => [['asInt' => 'abc']],
    'float string asInt' => [['asInt' => '1.5']],
    'asInt array' => [['asInt' => [1]]],
    'asInt object' => [['asInt' => ['a' => 1]]],
    'asInt bool' => [['asInt' => true]],
    'asInt beyond int64' => [['asInt' => '9223372036854775808']],
    'negative asInt' => [['asInt' => '-5']],
    'negative asDouble' => [['asInt' => null, 'asDouble' => -0.5]],
    'NaN asDouble' => [['asInt' => null, 'asDouble' => 'NaN']],
    'Infinity asDouble' => [['asInt' => null, 'asDouble' => 'Infinity']],
    'string asDouble' => [['asInt' => null, 'asDouble' => '1.5']],
    'huge asDouble' => [['asInt' => null, 'asDouble' => 1.0e300]],
    'asDouble array' => [['asInt' => null, 'asDouble' => [1.5]]],
    'no value at all' => [['asInt' => null]],
]);

it('accepts the int64 maximum, and an asInt given as a JSON number', function () {
    $parsed = parseOtlp(otlp(sumMetric('claude_code.token.usage', [
        tokenPoint('input', '9223372036854775807'),
        [...tokenPoint('output', '1'), 'asInt' => 42],
    ])));

    expect($parsed->points[0]->value)->toBe('9223372036854775807')
        ->and($parsed->points[1]->value)->toBe('42')
        ->and($parsed->rejectedInvalid)->toBe(0);
});

it('counts every data point of a known metric against the cap, valid or not', function (callable $makePoint, int|string $temporality) {
    $points = array_fill(0, 5001, $makePoint());

    parseOtlp(otlp(sumMetric('claude_code.token.usage', $points, $temporality)));
})->with([
    'cumulative' => [fn () => tokenPoint('input', '1'), 2],
    'zero' => [fn () => tokenPoint('input', '0'), 1],
    'invalid value' => [fn () => [...tokenPoint('input', '1'), 'asInt' => 'abc'], 1],
    'bad timestamp' => [fn () => tokenPoint('input', '1', 'm', 'abc', 'abc'), 1],
    'unknown token type' => [fn () => tokenPoint('bogus', '1'), 1],
])->throws(TooManyPoints::class);

it('accepts exactly 5000 data points of a mix of valid and skipped ones', function () {
    $points = [...array_fill(0, 2000, tokenPoint('input', '1')), ...array_fill(0, 2000, tokenPoint('input', '0')), ...array_fill(0, 1000, tokenPoint('bogus', '1'))];

    $parsed = parseOtlp(otlp(sumMetric('claude_code.token.usage', $points)));

    expect($parsed->points)->toHaveCount(2000);
});

it('does not count the data points of unknown metrics against the cap', function () {
    $points = array_fill(0, 6000, tokenPoint('input', '1'));

    expect(parseOtlp(otlp(sumMetric('some.other.metric', $points)))->points)->toBe([]);
});
