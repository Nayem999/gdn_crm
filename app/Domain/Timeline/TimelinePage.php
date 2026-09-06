<?php

namespace App\Domain\Timeline;

/**
 * A slice of a record's timeline, and whether there is more behind it.
 */
final readonly class TimelinePage
{
    /**
     * @param  array<int, TimelineEntry>  $entries
     */
    public function __construct(
        public array $entries,
        public bool $hasMore,
        public int $noteCount,
        public int $documentCount,
    ) {}

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
