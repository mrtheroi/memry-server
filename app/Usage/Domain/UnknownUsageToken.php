<?php

namespace App\Usage\Domain;

use RuntimeException;

/** The token id to keep is not one of the caller's usage tokens. */
final class UnknownUsageToken extends RuntimeException
{
    public static function toKeep(): self
    {
        return new self('The token to keep is not one of your usage tokens.');
    }
}
