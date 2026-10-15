<?php

namespace App\Usage\Application;

use App\Usage\Domain\InvalidOtlpPayload;
use App\Usage\Domain\ParsedUsage;
use App\Usage\Domain\TooManyPoints;
use App\Usage\Domain\UsageMetric;
use App\Usage\Domain\UsagePoint;

/**
 * Reads the OTLP/HTTP JSON encoding of an ExportMetricsServiceRequest, keeping only the delta
 * data points of Claude Code's token and cost sums. Everything else is dropped silently.
 */
final class OtlpMetricsParser
{
    private const METRICS = [
        'claude_code.token.usage' => UsageMetric::Tokens,
        'claude_code.cost.usage' => UsageMetric::Cost,
    ];

    private const TOKEN_TYPES = ['input', 'output', 'cacheRead', 'cacheCreation'];

    private const DELTA = [1, 'AGGREGATION_TEMPORALITY_DELTA'];

    private const MAX_ATTRIBUTE_LENGTH = 200;

    public const MAX_POINTS = 5000;

    // Keeps a double within numeric(38,6), which has 32 integer digits.
    private const MAX_DOUBLE = 1.0e30;

    /** @param array<mixed> $payload */
    public function __invoke(array $payload): ParsedUsage
    {
        if ($payload !== [] && array_is_list($payload)) {
            throw InvalidOtlpPayload::expected('a JSON object');
        }

        $points = [];
        $rejected = 0;
        $invalid = 0;

        foreach ($this->objects($payload, 'resourceMetrics') as $resource) {
            foreach ($this->objects($resource, 'scopeMetrics') as $scope) {
                foreach ($this->objects($scope, 'metrics') as $metric) {
                    $kind = self::METRICS[$metric['name'] ?? null] ?? null;
                    if ($kind === null || ! isset($metric['sum']) || ! is_array($metric['sum'])) {
                        continue;
                    }

                    $delta = in_array($metric['sum']['aggregationTemporality'] ?? null, self::DELTA, true);
                    foreach ($this->objects($metric['sum'], 'dataPoints') as $dataPoint) {
                        if (! $delta) {
                            $rejected++;

                            continue;
                        }

                        $value = $this->value($dataPoint);
                        if ($value === false) {
                            $invalid++;

                            continue;
                        }

                        if ($value === null) {
                            continue;
                        }

                        $point = $this->point($kind, $metric['name'], $dataPoint, $value);
                        if ($point !== null) {
                            $points[] = $point;
                            count($points) <= self::MAX_POINTS || throw TooManyPoints::over(self::MAX_POINTS);
                        }
                    }
                }
            }
        }

        return new ParsedUsage($points, $rejected, $invalid);
    }

    /**
     * The list of objects under $key; an absent key is an empty list, anything else is a bad shape.
     *
     * @param  array<mixed>  $node
     * @return list<array<mixed>>
     */
    private function objects(array $node, string $key): array
    {
        $items = $node[$key] ?? [];

        if (! is_array($items) || ! array_is_list($items) || array_filter($items, fn ($item) => ! is_array($item))) {
            throw InvalidOtlpPayload::expected("a list of objects at {$key}");
        }

        return $items;
    }

    /** @param array<mixed> $dataPoint */
    private function point(UsageMetric $kind, string $metricName, array $dataPoint, string $value): ?UsagePoint
    {
        $start = $this->nanos($dataPoint['startTimeUnixNano'] ?? null);
        $end = $this->nanos($dataPoint['timeUnixNano'] ?? null);
        if ($start === null || $end === null) {
            return null;
        }

        $attributes = [];
        foreach ($this->objects($dataPoint, 'attributes') as $attribute) {
            $attributes[(string) ($attribute['key'] ?? '')] = $this->attributeValue($attribute['value'] ?? null);
        }

        $tokenType = $attributes['type'] ?? null;
        if ($kind === UsageMetric::Tokens && ! in_array($tokenType, self::TOKEN_TYPES, true)) {
            return null;
        }

        return new UsagePoint(
            $kind,
            $this->cap($attributes['model'] ?? null) ?? 'unknown',
            $kind === UsageMetric::Tokens ? $tokenType : null,
            $this->cap($attributes['query_source'] ?? null),
            $this->cap($attributes['session.id'] ?? null),
            $start,
            $end,
            $value,
            $this->seriesHash($metricName, $attributes),
        );
    }

    /**
     * The identity of a series: the metric plus the COMPLETE attribute set of the data point, so two
     * series that differ in any attribute (speed, skill.name, ...) are never collapsed. Resource
     * attributes are left out on purpose: they describe the exporter, are constant for it, and carry
     * `user.*` values that are never trusted; the user comes from the token.
     *
     * @param  array<string, string|null>  $attributes
     */
    private function seriesHash(string $metricName, array $attributes): string
    {
        ksort($attributes, SORT_STRING);

        return hash('sha256', json_encode([$metricName, $attributes], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** An OTLP AnyValue as a string: scalars by their JSON form, anything else as canonical JSON. */
    private function attributeValue(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $scalar = $value['stringValue'] ?? $value['intValue'] ?? $value['doubleValue'] ?? $value['boolValue'] ?? null;

        return match (true) {
            is_bool($scalar) => $scalar ? 'true' : 'false',
            is_scalar($scalar) => (string) $scalar,
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * The value of the data point's oneof (`asInt` or `asDouble`) as a decimal string; null for a zero
     * (nothing to count); false when it is missing or not a valid monotonic delta: not a decimal int64
     * string/int, not a finite number, negative, or too large for the numeric(38,6) column.
     *
     * @param  array<mixed>  $dataPoint
     */
    private function value(array $dataPoint): string|false|null
    {
        if (($dataPoint['asInt'] ?? null) !== null) {
            $raw = $dataPoint['asInt'];
            $int = is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1)
                ? filter_var($raw, FILTER_VALIDATE_INT)
                : false;

            return $int === false || $int < 0 ? false : ($int === 0 ? null : (string) $int);
        }

        $double = $dataPoint['asDouble'] ?? null;
        if (! is_int($double) && ! is_float($double)) {
            return false;
        }

        if (! is_finite($double) || $double < 0 || $double >= self::MAX_DOUBLE) {
            return false;
        }

        $value = number_format($double, 6, '.', '');

        return (float) $value === 0.0 ? null : $value;
    }

    /** An int64 Unix-nanosecond timestamp: a JSON string per OTLP, tolerating a JSON number. */
    private function nanos(mixed $timestamp): ?string
    {
        if (! is_string($timestamp) && ! is_int($timestamp)) {
            return null;
        }

        $timestamp = (string) $timestamp;

        // Rejects non-digits, leading zeros and anything past the signed 64-bit range.
        return ctype_digit($timestamp) && (string) (int) $timestamp === $timestamp ? $timestamp : null;
    }

    private function cap(mixed $attribute): ?string
    {
        return ! is_string($attribute) || $attribute === '' ? null : mb_substr($attribute, 0, self::MAX_ATTRIBUTE_LENGTH);
    }
}
