<?php

namespace App\Http\Controllers\Usage;

use App\Http\Controllers\Controller;
use App\Team\Domain\AccessContext;
use App\Usage\Application\OtlpMetricsParser;
use App\Usage\Application\RecordUsage;
use App\Usage\Domain\InvalidOtlpPayload;
use App\Usage\Domain\TooManyPoints;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

class OtlpMetricsController extends Controller
{
    private const MAX_BODY_BYTES = 1024 * 1024;

    public function __invoke(Request $request, AccessContext $context, OtlpMetricsParser $parse, RecordUsage $record): JsonResponse
    {
        if ($context->isDenied()) {
            return $this->error('A team is required to record usage.', 403);
        }

        // Only the OTLP/HTTP JSON encoding; protobuf (the exporter's default) gets 415.
        if (strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) !== 'application/json') {
            return $this->error('Unsupported media type: set OTEL_EXPORTER_OTLP_PROTOCOL=http/json.', 415);
        }

        // The size is checked on the raw bytes, before anything is decoded.
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->error('The request body may not exceed 1 MB.', 413);
        }

        try {
            $parsed = $parse(json_decode($body, false, 32, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return $this->error('The request body is not valid JSON.', 400);
        } catch (InvalidOtlpPayload $e) {
            return $this->error($e->getMessage(), 400);
        } catch (TooManyPoints $e) {
            return $this->error($e->getMessage(), 413);
        }

        $record($context, $parsed->points);

        $messages = array_filter([
            $parsed->rejectedNonDelta > 0
                ? "memry expects delta temporality; {$parsed->rejectedNonDelta} cumulative data points were skipped. "
                    .'Set OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta.'
                : null,
            $parsed->rejectedInvalid > 0
                ? "{$parsed->rejectedInvalid} data points with an invalid value were skipped; values must be non-negative numbers; "
                    .'token values must be whole numbers up to '.OtlpMetricsParser::MAX_TOKENS
                    .' and cost values at most '.OtlpMetricsParser::MAX_COST_USD.'.'
                : null,
        ]);

        if ($messages === []) {
            return response()->json((object) []);
        }

        // OTLP ExportMetricsServiceResponse; rejectedDataPoints is an int64, so a JSON string.
        return response()->json(['partialSuccess' => [
            'rejectedDataPoints' => (string) ($parsed->rejectedNonDelta + $parsed->rejectedInvalid),
            'errorMessage' => implode(' ', $messages),
        ]]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
