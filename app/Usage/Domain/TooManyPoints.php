<?php

namespace App\Usage\Domain;

use RuntimeException;

/** One export request carries more data points of interest than memry accepts. */
final class TooManyPoints extends RuntimeException
{
    public static function over(int $limit): self
    {
        return new self("An export request may carry at most {$limit} usage data points.");
    }
}
