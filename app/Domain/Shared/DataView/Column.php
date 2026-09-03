<?php

namespace App\Domain\Shared\DataView;

/**
 * One column a list screen can show.
 */
readonly class Column
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $sortable = true,
        /** Always visible and never reorderable, e.g. the record's name. */
        public bool $locked = false,
        /** Hidden until the user turns it on in the column manager. */
        public bool $hiddenByDefault = false,
        public ?string $sortColumn = null,
        /** Right-align numeric columns. */
        public bool $numeric = false,
    ) {}

    public function sortColumn(): string
    {
        return $this->sortColumn ?? $this->key;
    }

    public static function make(string $key, string $label): self
    {
        return new self($key, $label);
    }

    public static function locked(string $key, string $label): self
    {
        return new self($key, $label, locked: true);
    }

    public static function optional(string $key, string $label): self
    {
        return new self($key, $label, hiddenByDefault: true);
    }

    public static function numeric(string $key, string $label): self
    {
        return new self($key, $label, numeric: true);
    }
}
