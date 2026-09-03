<?php

namespace App\Domain\Shared\Enums;

enum ViewMode: string
{
    case Table = 'table';
    case Kanban = 'kanban';
    case Grid = 'grid';
    case List = 'list';

    public function label(): string
    {
        return match ($this) {
            self::Table => 'Table',
            self::Kanban => 'Kanban',
            self::Grid => 'Grid',
            self::List => 'List',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Table => 'table',
            self::Kanban => 'columns-3',
            self::Grid => 'layout-grid',
            self::List => 'list',
        };
    }

    /**
     * The skeleton shape to show while this view loads.
     */
    public function skeleton(): string
    {
        return match ($this) {
            self::Table, self::List => 'rows',
            self::Kanban => 'kanban',
            self::Grid => 'cards',
        };
    }
}
