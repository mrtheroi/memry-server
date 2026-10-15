<?php

namespace App\Usage\Domain;

use RuntimeException;

/** The body decoded as JSON but is not an OTLP metrics export request. */
final class InvalidOtlpPayload extends RuntimeException
{
    public static function expected(string $what): self
    {
        return new self("Invalid OTLP payload: expected {$what}.");
    }
}
