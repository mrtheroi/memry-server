<?php

namespace App\Memory\Domain;

use RuntimeException;

/**
 * The caller can read the memory but is not allowed to delete it.
 */
final class MemoryDeletionDenied extends RuntimeException
{
    public static function notYours(): self
    {
        return new self('You can only delete your own memories.');
    }
}
