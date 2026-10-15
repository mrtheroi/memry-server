<?php

namespace App\Usage\Domain;

/**
 * One delta data point of a usage series over a time window. Identity (user, series, window) is
 * what makes a retried export idempotent (`seriesHash` covers the complete attribute set); `value` is a decimal string so no precision is lost.
 */
final readonly class UsagePoint
{
    public function __construct(
        public UsageMetric $metric,
        public string $model,
        public ?string $tokenType,
        public ?string $querySource,
        public ?string $sessionId,
        public string $startUnixNano,
        public string $endUnixNano,
        public string $value,
        public string $seriesHash,
    ) {}
}
