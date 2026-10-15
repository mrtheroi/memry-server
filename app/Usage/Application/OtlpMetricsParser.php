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

    /** @param array<mixed> $payload */
    public function __invoke(array $payload): ParsedUsage
    {
        $points = [];
        $rejected = 0;

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

                        $point = $this->point($kind, $dataPoint);
                        if ($point !== null) {
                            $points[] = $point;
                            count($points) <= self::MAX_POINTS || throw TooManyPoints::over(self::MAX_POINTS);
                        }
                    }
                }
            }
        }

        return new ParsedUsage($points, $rejected);
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
    private function point(UsageMetric $kind, array $dataPoint): ?UsagePoint
    {
        $value = $this->value($dataPoint);
        if ($value === null) {
            return null;
        }

        $start = $this->nanos($dataPoint['startTimeUnixNano'] ?? null);
        $end = $this->nanos($dataPoint['timeUnixNano'] ?? null);
        if ($start === null || $end === null) {
            return null;
        }

        $attributes = [];
        foreach ($this->objects($dataPoint, 'attributes') as $attribute) {
            $attributes[$attribute['key'] ?? ''] = $attribute['value']['stringValue'] ?? null;
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
        );
    }

    /**
     * The value as a decimal string, or null for a zero (nothing to count).
     *
     * @param  array<mixed>  $dataPoint
     */
    private function value(array $dataPoint): ?string
    {
        $value = isset($dataPoint['asInt'])
            ? (string) $dataPoint['asInt']
            : number_format((float) ($dataPoint['asDouble'] ?? 0), 6, '.', '');

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
