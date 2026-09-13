<?php

namespace App\Domain\Reports;

/**
 * A table a report may reach into, declared rather than requested.
 *
 * Only joins on a source's list can be applied, and they are applied only when
 * a chosen dimension or measure asks for one — a report grouped by stage does
 * not join the users table to fetch a name nobody is showing.
 *
 * Always a LEFT join. An INNER one would silently drop the deals with no
 * account, which is exactly the group somebody is looking for when they run the
 * report.
 */
readonly class ReportJoin
{
    public function __construct(
        public string $key,
        public string $table,
        public string $localColumn,
        public string $foreignColumn = 'id',
        public ?string $alias = null,
    ) {}

    /**
     * The name the joined table is referred to by in a column expression.
     */
    public function name(): string
    {
        return $this->alias ?? $this->table;
    }

    /**
     * What goes in the FROM clause: the table, aliased when it needs to be.
     */
    public function target(): string
    {
        return $this->alias === null ? $this->table : $this->table.' as '.$this->alias;
    }
}
