<?php

namespace App\Domain\Shared\SavedViews;

use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Filters\FilterGroup;

/**
 * What a saved view remembers about a list screen.
 *
 * A value object rather than a loose array, because the same five things are
 * captured on save, validated on load, and compared when deciding whether the
 * screen still matches the view it was opened from.
 *
 * Nothing here is trusted on the way back in. A stored view can be older than
 * the screen: a column may have been renamed, a filter field removed, a view
 * mode retired. `applyTo()` therefore hands each piece through the screen's own
 * registries — the same ones that already refuse anything the browser sends —
 * so an out-of-date view degrades to the parts that still exist rather than
 * putting a column or a filter back that the module no longer has.
 */
final readonly class SavedViewState
{
    /**
     * @param  array<int, string>  $visibleColumns
     * @param  array<int, string>  $pinnedColumns
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public string $viewMode = ViewMode::Table->value,
        public string $search = '',
        public string $sortBy = '',
        public string $sortDirection = 'asc',
        public int $perPage = 25,
        public array $visibleColumns = [],
        public array $pinnedColumns = [],
        public array $filters = FilterGroup::EMPTY,
    ) {}

    public static function fromArray(mixed $stored): self
    {
        $stored = is_array($stored) ? $stored : [];

        return new self(
            viewMode: (string) ($stored['viewMode'] ?? ViewMode::Table->value),
            search: (string) ($stored['search'] ?? ''),
            sortBy: (string) ($stored['sortBy'] ?? ''),
            sortDirection: ($stored['sortDirection'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            perPage: (int) ($stored['perPage'] ?? 25),
            visibleColumns: self::strings($stored['visibleColumns'] ?? []),
            pinnedColumns: self::strings($stored['pinnedColumns'] ?? []),
            filters: is_array($stored['filters'] ?? null) ? $stored['filters'] : FilterGroup::EMPTY,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'viewMode' => $this->viewMode,
            'search' => $this->search,
            'sortBy' => $this->sortBy,
            'sortDirection' => $this->sortDirection,
            'perPage' => $this->perPage,
            'visibleColumns' => $this->visibleColumns,
            'pinnedColumns' => $this->pinnedColumns,
            'filters' => $this->filters,
        ];
    }

    /**
     * Whether two arrangements are the same, so a screen can say it has drifted
     * from the view it was opened on.
     */
    public function matches(self $other): bool
    {
        return $this->toArray() == $other->toArray();
    }

    /**
     * @return array<int, string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }
}
