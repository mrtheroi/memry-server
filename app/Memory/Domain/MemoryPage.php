<?php

namespace App\Memory\Domain;

/**
 * One page of a cursor-paginated memory list. The cursors are opaque: hand them back to the same query to move.
 */
final readonly class MemoryPage
{
    /** @param list<Observation> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public int $perPage,
    ) {}
}
