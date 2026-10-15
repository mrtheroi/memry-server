<?php

namespace App\Usage\Application;

use App\Usage\Domain\InvalidOtlpPayload;
use App\Usage\Domain\ParsedUsage;
use App\Usage\Domain\TooManyPoints;
use App\Usage\Domain\UsageMetric;
use App\Usage\Domain\UsagePoint;
use stdClass;

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

    // The largest valid value of one data point per metric. No real delta comes near these; anything above
    // is invalid, which keeps every read-time sum far from overflowing (int64 for tokens).
    public const MAX_TOKENS = 1_000_000_000_000;

    public const MAX_COST_USD = 1_000_000_000;

    private const MAX_VALUE = ['tokens' => self::MAX_TOKENS, 'cost' => self::MAX_COST_USD];

    /**
     * @param  mixed  $payload  the body decoded with json_decode(..., false): JSON objects are stdClass and
     *                          JSON arrays are lists, so an empty `{}` is never mistaken for an empty `[]`
     */
    public function __invoke(mixed $payload): ParsedUsage
    {
        if (! $payload instanceof stdClass) {
            throw InvalidOtlpPayload::expected('a JSON object');
        }

        $payload = (array) $payload;

        $points = [];
        $rejected = 0;
        $invalid = 0;
        $seen = 0;

        foreach ($this->listAt($payload, 'resourceMetrics') as $resource) {
            foreach ($this->listAt($resource, 'scopeMetrics') as $scope) {
                foreach ($this->listAt($scope, 'metrics') as $metric) {
                    $name = $this->stringAt($metric, 'name');
                    $sum = $this->arrayAt($metric, 'sum');
                    $kind = self::METRICS[$name] ?? null;
                    if ($kind === null || $sum === null) {
                        continue;
                    }

                    $delta = in_array($this->scalarAt($sum, 'aggregationTemporality'), self::DELTA, true);
                    foreach ($this->listAt($sum, 'dataPoints') as $dataPoint) {
                        // Every data point of a known metric counts against the cap, whatever happens to it next.
                        ++$seen <= self::MAX_POINTS || throw TooManyPoints::over(self::MAX_POINTS);

                        if (! $delta) {
                            $rejected++;

                            continue;
                        }

                        $value = $this->value($dataPoint, $kind);
                        if ($value === false) {
                            $invalid++;

                            continue;
                        }

                        if ($value === null) {
                            continue;
                        }

                        $point = $this->point($kind, $name, $dataPoint, $value);
                        if ($point !== null) {
                            $points[] = $point;
                        }
                    }
                }
            }
        }

        return new ParsedUsage($points, $rejected, $invalid);
    }

    /**
     * The list of objects under $key (as arrays); absent or null is an empty list, anything else, including a
     * JSON object, is a bad shape.
     *
     * @param  array<mixed>  $node
     * @return list<array<mixed>>
     */
    private function listAt(array $node, string $key): array
    {
        $items = $node[$key] ?? [];

        if (! is_array($items) || ! array_is_list($items) || array_filter($items, fn ($item) => ! $item instanceof stdClass)) {
            throw InvalidOtlpPayload::expected("a list of objects at {$key}");
        }

        return array_map(fn (stdClass $item) => (array) $item, $items);
    }

    /**
     * The JSON object under $key (as an array), or null when absent; a list or scalar is a bad shape.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>|null
     */
    private function arrayAt(array $node, string $key): ?array
    {
        $value = $node[$key] ?? null;

        return match (true) {
            $value === null => null,
            $value instanceof stdClass => (array) $value,
            default => throw InvalidOtlpPayload::expected("an object at {$key}"),
        };
    }

    /** @param array<mixed> $node */
    private function stringAt(array $node, string $key): ?string
    {
        $value = $node[$key] ?? null;

        return $value === null || is_string($value) ? $value : throw InvalidOtlpPayload::expected("a string at {$key}");
    }

    /**
     * A string or integer (for example an enum given by name or number), or null when absent.
     *
     * @param  array<mixed>  $node
     */
    private function scalarAt(array $node, string $key): string|int|null
    {
        $value = $node[$key] ?? null;

        return $value === null || is_string($value) || is_int($value)
            ? $value
            : throw InvalidOtlpPayload::expected("a string or integer at {$key}");
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
        foreach ($this->listAt($dataPoint, 'attributes') as $attribute) {
            $key = $this->stringAt($attribute, 'key') ?? throw InvalidOtlpPayload::expected('a string at key');
            $attributes[$key] = $this->attributeValue($this->arrayAt($attribute, 'value'));
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
    private function attributeValue(?array $value): ?string
    {
        if ($value === null || $value === []) {
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
     * string/int, not a finite number, negative, too large for the numeric(38,6) column, above the
     * metric's limit, or a token count that is not a whole number.
     *
     * @param  array<mixed>  $dataPoint
     */
    private function value(array $dataPoint, UsageMetric $kind): string|false|null
    {
        $value = $this->rawValue($dataPoint);

        if (! is_string($value)) {
            return $value;
        }

        // Tokens are whole by nature: a fractional count (an asDouble like 1.5) is invalid, never truncated later.
        $fractional = $kind === UsageMetric::Tokens && ! preg_match('/^\d+(\.0+)?$/', $value);

        return $fractional || (float) $value > self::MAX_VALUE[$kind->value] ? false : $value;
    }

    /**
     * @param  array<mixed>  $dataPoint
     */
    private function rawValue(array $dataPoint): string|false|null
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
