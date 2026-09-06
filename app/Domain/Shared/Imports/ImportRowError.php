<?php

namespace App\Domain\Shared\Imports;

/**
 * One row a file could not contribute, and why.
 *
 * Carries the row number as a person reading the file would count it, so the
 * error report points at a line they can actually go and look at.
 */
readonly class ImportRowError
{
    /**
     * @param  array<int, string>  $messages
     */
    public function __construct(
        public int $row,
        public array $messages,
        public ?string $summary = null,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            row: (int) ($state['row'] ?? 0),
            messages: array_map(fn (mixed $m) => (string) $m, (array) ($state['messages'] ?? [])),
            summary: isset($state['summary']) ? (string) $state['summary'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['row' => $this->row, 'messages' => $this->messages, 'summary' => $this->summary];
    }

    public function joined(): string
    {
        return implode(' ', $this->messages);
    }
}
