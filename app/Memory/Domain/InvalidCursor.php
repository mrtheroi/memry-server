<?php

namespace App\Memory\Domain;

use RuntimeException;

final class InvalidCursor extends RuntimeException
{
    public static function make(): self
    {
        return new self('The cursor is not valid.');
    }
}
