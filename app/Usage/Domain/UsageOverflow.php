<?php

namespace App\Usage\Domain;

use RuntimeException;

/** An aggregated token count does not fit a 64-bit integer; reported instead of a silently wrong number. */
final class UsageOverflow extends RuntimeException
{
    public static function tokens(string $sum): self
    {
        return new self("A token sum of {$sum} does not fit a 64-bit integer.");
    }
}
