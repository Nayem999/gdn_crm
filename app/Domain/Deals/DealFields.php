<?php

namespace App\Domain\Deals;

use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;

/**
 * One place that says what the deals list can show, sort and filter by.
 *
 * The screen and the export both read it, so a column can never be exportable
 * but unsortable, or filterable on screen and not in a queued export.
 */
final class DealFields
{
    /**
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        return [
            Column::locked('name', 'Deal'),
            new Column('account', 'Account', sortable: false),
            Column::make('stage', 'Stage'),
            new Column('value', 'Value', numeric: true),
            // Derived from value and the stage's probability, so there is no
            // column to sort by — see the note on sortColumn() below.
            new Column('weighted_value', 'Weighted', sortable: false, numeric: true),
            Column::make('expected_close_date', 'Expected close'),
            new Column('owner', 'Owner', sortable: false),
            new Column('pipeline', 'Pipeline', sortable: false, hiddenByDefault: true),
            new Column('contact', 'Contact', sortable: false, hiddenByDefault: true),
            Column::optional('close_reason', 'Close reason'),
            Column::optional('closed_at', 'Closed'),
            Column::optional('created_at', 'Created'),
        ];
    }

    /**
     * @return array<string, FilterField>
     */
    public static function filters(): array
    {
        $fields = [
            FilterField::text('name', 'Deal name'),
            FilterField::select('stage', 'Stage', self::stageOptions()),
            FilterField::number('value', 'Value'),
            FilterField::date('expected_close_date', 'Expected close'),
            FilterField::select('pipeline_id', 'Pipeline', self::pipelineOptions()),
            FilterField::select('close_reason', 'Close reason', DealCloseReason::options()),
            FilterField::date('closed_at', 'Closed'),
            FilterField::date('created_at', 'Created'),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        return $keyed;
    }

    /**
     * Every stage key configured anywhere, labelled by name.
     *
     * Across pipelines on purpose: the filter is offered before a pipeline is
     * chosen, and a key configured on two pipelines is one filter option, not
     * two. Where two pipelines disagree about a key's name, the first wins —
     * the same key meaning two different things is a configuration mistake.
     *
     * @return array<string, string>
     */
    public static function stageOptions(): array
    {
        $options = [];

        foreach (Pipeline::query()->with('stages')->ordered()->get() as $pipeline) {
            foreach ($pipeline->stages as $stage) {
                $options[$stage->key] ??= $stage->name;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function pipelineOptions(): array
    {
        /** @var array<string, string> $options */
        $options = Pipeline::query()->ordered()->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id) => [(string) $id => $name])
            ->all();

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function outcomeOptions(): array
    {
        return StageOutcome::options();
    }

    /**
     * The database column behind a sort key, or null when that key is not one
     * the list sorts by. Nothing from the browser reaches an ORDER BY without
     * passing through here.
     *
     * `weighted_value` is deliberately absent: it is value × the stage's
     * probability, and the probability lives on another table that this query
     * does not join. Sorting it in SQL would need that join and would then
     * disagree with the figure on screen for a deal whose pipeline has no
     * matching stage. It is a display column.
     */
    public static function sortColumn(string $key): ?string
    {
        foreach (self::columns() as $column) {
            if ($column->key === $key && $column->sortable) {
                return 'deals.'.$column->sortColumn();
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        return ['name', 'description'];
    }
}
