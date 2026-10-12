<?php

namespace App\Team\Domain;

use RuntimeException;

/**
 * The acting context may not write to the target project. Raised before any write.
 */
final class AccessDenied extends RuntimeException
{
    public static function forProject(?string $project): self
    {
        return new self($project === null ? 'Access denied.' : "Access denied to project {$project}.");
    }

    public static function systemContext(): self
    {
        return new self('A system context cannot write memories.');
    }
}
