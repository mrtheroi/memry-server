<?php

namespace App\Team\Domain;

use InvalidArgumentException;

final readonly class ProjectAccess
{
    /** @param list<int> $ids */
    private function __construct(private bool $all, private array $ids) {}

    public static function all(): self
    {
        return new self(true, []);
    }

    public static function only(int ...$ids): self
    {
        foreach ($ids as $id) {
            if ($id < 1) {
                throw new InvalidArgumentException("Project ids must be positive, got {$id}.");
            }
        }

        return new self(false, array_values($ids));
    }

    public static function none(): self
    {
        return new self(false, []);
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    /** @return list<int> */
    public function ids(): array
    {
        return $this->ids;
    }
}
