<?php

use App\Usage\Application\OtlpMetricsParser;
use App\Usage\Domain\InvalidOtlpPayload;
use App\Usage\Domain\TooManyPoints;
use App\Usage\Domain\UsageMetric;

it('parses claude code token and cost delta points', function () {
    $parsed = (new OtlpMetricsParser)(otlp(
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
    $parsed = (new OtlpMetricsParser)(otlp(
        sumMetric('claude_code.session.count', [['attributes' => [], 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '1']]),
        ['name' => 'claude_code.active_time.total', 'gauge' => ['dataPoints' => []]],
        sumMetric('claude_code.token.usage', [tokenPoint('output', '7')]),
    ));

    expect($parsed->points)->toHaveCount(1)
        ->and($parsed->points[0]->tokenType)->toBe('output');
});

it('skips cumulative points and counts them as rejected', function () {
    $parsed = (new OtlpMetricsParser)(otlp(
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

    $parsed = (new OtlpMetricsParser)(otlp(
        sumMetric('claude_code.token.usage', [tokenPoint('input', '0'), tokenPoint('output', '9007199254740993')]),
        sumMetric('claude_code.cost.usage', [$double(0), $double(0.0000001), $double(2), $double(0.5)]),
    ));

    expect(array_map(fn ($p) => $p->value, $parsed->points))->toBe(['9007199254740993', '2.000000', '0.500000']);
});

it('caps attribute lengths, defaults a missing model and skips token points of an unknown type', function () {
    $long = str_repeat('é', 300);
    $noModel = ['attributes' => attrs(['type' => 'input']), 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '3'];

    $parsed = (new OtlpMetricsParser)(otlp(sumMetric('claude_code.token.usage', [
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
    expect((new OtlpMetricsParser)([])->points)->toBe([])
        ->and((new OtlpMetricsParser)(['resourceMetrics' => []])->points)->toBe([]);
});

it('rejects a payload of the wrong shape', function (array $payload) {
    (new OtlpMetricsParser)($payload);
})->with([
    'top level list' => [[1, 2]],
    'resourceMetrics not a list' => [['resourceMetrics' => 'x']],
    'scopeMetrics not a list' => [['resourceMetrics' => [['scopeMetrics' => 'x']]]],
    'metrics not a list' => [['resourceMetrics' => [['scopeMetrics' => [['metrics' => 'x']]]]]],
    'dataPoints not a list' => [otlp(['name' => 'claude_code.token.usage', 'sum' => ['aggregationTemporality' => 1, 'dataPoints' => 'x']])],
    'resource not an object' => [['resourceMetrics' => ['x']]],
    'data point not an object' => [otlp(sumMetric('claude_code.token.usage', ['x']))],
    'attributes not a list' => [otlp(sumMetric('claude_code.token.usage', [['attributes' => 'x', 'startTimeUnixNano' => '1', 'timeUnixNano' => '2', 'asInt' => '1']]))],
])->throws(InvalidOtlpPayload::class);

it('keeps only data points with a valid time window', function () {
    $withTime = fn (mixed $start, mixed $end) => ['attributes' => attrs(['type' => 'input', 'model' => 'm']), 'startTimeUnixNano' => $start, 'timeUnixNano' => $end, 'asInt' => '1'];

    $parsed = (new OtlpMetricsParser)(otlp(sumMetric('claude_code.token.usage', [
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

    (new OtlpMetricsParser)(otlp(sumMetric('claude_code.token.usage', $points)));
})->throws(TooManyPoints::class);

it('accepts exactly 5000 points', function () {
    $points = array_fill(0, 5000, tokenPoint('input', '1'));

    expect((new OtlpMetricsParser)(otlp(sumMetric('claude_code.token.usage', $points)))->points)->toHaveCount(5000);
});
