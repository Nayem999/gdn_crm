<?php

use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Enums\ViewMode;

test('skeleton renders a shape for every view mode', function (ViewMode $mode) {
    $this->blade('<x-skeleton :shape="$shape" />', ['shape' => $mode->skeleton()])
        ->assertSee('animate-pulse', false)
        ->assertSee('Loading');
})->with(ViewMode::cases());

test('empty state invites a first record when nothing is filtered', function () {
    $this->blade('<x-empty-state heading="No leads yet" description="Add your first lead." />')
        ->assertSee('No leads yet')
        ->assertSee('Add your first lead.')
        ->assertDontSee('Clear');
});

test('empty state speaks differently when a filter hid everything', function () {
    $this->blade('<x-empty-state heading="No leads yet" :filtered="true" />')
        ->assertSee('No matching records')
        ->assertSee('clearing your filters')
        ->assertDontSee('No leads yet');
});

test('empty state renders its actions slot', function () {
    $this->blade('<x-empty-state><x-slot:actions><button>New lead</button></x-slot:actions></x-empty-state>')
        ->assertSee('New lead');
});

test('error state announces itself and can offer a retry', function () {
    $this->blade('<x-error-state retry="reload" />')
        ->assertSee('role="alert"', false)
        ->assertSee('Something went wrong')
        ->assertSee('Try again')
        ->assertSee('wire:click="reload"', false);
});

test('error state omits the retry button when there is nothing to retry', function () {
    $this->blade('<x-error-state />')->assertDontSee('Try again');
});

test('icon chip renders its icon, label and colour', function () {
    $this->blade('<x-icon-chip icon="mail" color="emerald" label="Emailed" />')
        ->assertSee('Emailed')
        ->assertSee('emerald', false)
        ->assertSee('<svg', false);
});

test('status chip still accepts a bare colour', function () {
    $this->blade('<x-status-chip color="amber">Pending</x-status-chip>')
        ->assertSee('Pending')
        ->assertSee('amber', false);
});

test('status chip reads its colour and label straight from an enum', function () {
    $this->blade('<x-status-chip :status="$status" />', ['status' => DataAccessLevel::Team])
        ->assertSee(DataAccessLevel::Team->label())
        ->assertSee(DataAccessLevel::Team->color(), false);
});

test('status chip lets slot content win over the enum label', function () {
    $this->blade('<x-status-chip :status="$status">Custom</x-status-chip>', ['status' => DataAccessLevel::Team])
        ->assertSee('Custom')
        ->assertDontSee(DataAccessLevel::Team->label());
});

test('status chip can show a leading dot', function () {
    $this->blade('<x-status-chip color="rose" dot>Lost</x-status-chip>')
        ->assertSee('rounded-full bg-rose-500', false);
});

test('bulk actions bar stays hidden until something is selected', function () {
    $this->blade('<x-bulk-actions :count="0"><button>Delete</button></x-bulk-actions>')
        ->assertDontSee('Delete');
});

test('bulk actions bar counts the selection and offers to clear it', function () {
    $this->blade('<x-bulk-actions :count="3"><button>Delete</button></x-bulk-actions>')
        ->assertSee('3 records selected')
        ->assertSee('Delete')
        ->assertSee('wire:click="clearSelection"', false);
});

test('bulk actions bar offers select-all only when more rows match', function () {
    $this->blade('<x-bulk-actions :count="25" :total="140" :show-select-all="true" />')
        ->assertSee('Select all 140')
        ->assertSee('wire:click="selectAllMatchingFilters"', false);

    $this->blade('<x-bulk-actions :count="25" :total="25" :show-select-all="true" />')
        ->assertDontSee('Select all');
});

test('column manager lists every column with its checked and pinned state', function () {
    $this->blade('<x-column-manager :columns="$columns" :visible="$visible" :pinned="$pinned" />', [
        'columns' => [
            Column::locked('name', 'Name'),
            Column::make('stage', 'Stage'),
            Column::optional('owner', 'Owner'),
        ],
        'visible' => ['name', 'stage'],
        'pinned' => ['name'],
    ])
        ->assertSee('Name')
        ->assertSee('Stage')
        ->assertSee('Owner')
        ->assertSee("wire:click=\"toggleColumn('stage')\"", false)
        ->assertSee("wire:click=\"togglePin('name')\"", false)
        ->assertSee('wire:click="resetColumns"', false)
        ->assertSee('Unpin Name')
        ->assertSee('Pin Stage');
});

test('column manager will not let a locked column be switched off or dragged', function () {
    $rendered = (string) $this->blade('<x-column-manager :columns="$columns" :visible="$visible" />', [
        'columns' => [Column::locked('name', 'Name')],
        'visible' => ['name'],
    ]);

    expect($rendered)->toContain('disabled')
        ->and($rendered)->not->toContain('Reorder Name');
});

test('export menu offers every format plus printing', function () {
    $rendered = (string) $this->blade('<x-export-menu />');

    foreach (ExportFormat::cases() as $format) {
        expect($rendered)
            ->toContain("wire:click=\"export('{$format->value}')\"")
            ->and($rendered)->toContain('Download as '.$format->label());
    }

    expect($rendered)->toContain('window.print()')
        ->and($rendered)->toContain('current filters, sort order and visible columns');
});

test('export menu offers a selected-rows-only choice only when rows are selected', function () {
    $this->blade('<x-export-menu :selection-count="4" />')
        ->assertSee('Selected rows only')
        ->assertSee('wire:model.live="exportSelectedOnly"', false);

    $this->blade('<x-export-menu :selection-count="0" />')->assertDontSee('Selected rows only');
});

test('print layout prints a titled sheet with metadata', function () {
    $this->blade(
        '<x-print-layout title="Pipeline" subtitle="Q1" :meta="$meta">Body here</x-print-layout>',
        ['meta' => ['Owner' => 'Dana', 'Stage' => 'Won']]
    )
        ->assertSee('print-sheet', false)
        ->assertSee('Pipeline')
        ->assertSee('Q1')
        ->assertSee('Owner')
        ->assertSee('Dana')
        ->assertSee('Body here')
        ->assertSee(config('app.name'));
});

test('print layout renders its footer slot', function () {
    // Named slots go after the default content: Blade leaks an output buffer
    // when a named slot precedes it. See .ai/rules/views.md.
    $this->blade('<x-print-layout title="T">Body<x-slot:footer>Confidential</x-slot:footer></x-print-layout>')
        ->assertSee('Confidential')
        ->assertSee('Body');
});

test('kit components with slots leave no output buffer behind', function (string $template) {
    $before = ob_get_level();

    (string) $this->blade($template);

    expect(ob_get_level())->toBe($before);
})->with([
    '<x-print-layout title="T">Body<x-slot:footer>F</x-slot:footer></x-print-layout>',
    '<x-empty-state>Body<x-slot:actions><button>New</button></x-slot:actions></x-empty-state>',
    '<x-error-state>Extra detail</x-error-state>',
    '<x-bulk-actions :count="2"><button>Delete</button></x-bulk-actions>',
    '<x-status-chip color="teal">Chip</x-status-chip>',
    '<x-icon-chip icon="mail" label="Emailed" />',
    '<x-skeleton shape="rows" />',
]);

test('filter chips summarise the search and each condition, each removable', function () {
    $this->blade('<x-filter-chips :chips="$chips" search="acme" />', [
        'chips' => [
            ['label' => 'Stage is Won', 'index' => 0, 'group' => null],
            ['label' => 'Value greater than 100', 'index' => 1, 'group' => 2],
        ],
    ])
        ->assertSee('acme')
        ->assertSee('Stage is Won')
        ->assertSee('Value greater than 100')
        ->assertSee('wire:click="removeCondition(0)"', false)
        ->assertSee('wire:click="removeCondition(1, 2)"', false)
        ->assertSee("wire:click=\"\$set('search', '')\"", false);
});

test('filter chips render nothing at all when there is no filter', function () {
    expect(trim((string) $this->blade('<x-filter-chips :chips="[]" search="" />')))->toBe('');
});

test('the kit draws its glyphs from the lucide set', function () {
    $this->blade('<x-icon name="lucide-search" class="h-4 w-4" />')
        ->assertSee('<svg', false)
        ->assertSee('h-4 w-4', false);
});

test('no view anywhere uses a plain select', function () {
    // "No plain <select> anywhere in the application. Build one <x-select>
    // component used by every form, filter, and modal." The component itself is
    // the one place a native <select> may appear — Tom Select needs an element
    // to take over.
    $component = realpath(resource_path('views/components/select.blade.php'));
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || $file->getRealPath() === $component) {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getRealPath()), '<select')) {
            $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getRealPath());
        }
    }

    expect($offenders)->toBe([], 'Use <x-select> instead: '.implode(', ', $offenders));
});
