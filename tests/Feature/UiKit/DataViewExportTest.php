<?php

use App\Domain\Shared\Actions\RunDataViewExport;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Jobs\GenerateDataViewExport;
use App\Models\User;
use App\Notifications\ExportFailed;
use App\Notifications\ExportReady;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Fixtures\DataViewHarness;
use Tests\Fixtures\DataViewHarnessExportSource;
use Tests\Fixtures\DataViewRecord;

beforeEach(function () {
    DataViewRecord::createTable();
    DataViewRecord::query()->delete();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    foreach ([
        ['name' => 'Acme Corporation', 'stage' => 'new', 'value' => 1000],
        ['name' => 'Beta Industries', 'stage' => 'won', 'value' => 5000],
        ['name' => 'Gamma Holdings', 'stage' => 'lost', 'value' => 250],
    ] as $attributes) {
        DataViewRecord::query()->create($attributes);
    }
});

test('an export carries the visible columns, in the order the user arranged them', function () {
    $component = Livewire::test(DataViewHarness::class)
        ->call('toggleColumn', 'closes_on')
        ->call('togglePin', 'value');

    $request = $component->instance()->exportRequestForTesting(ExportFormat::Csv);

    expect($request->columnKeys())->toBe(['value', 'name', 'stage'])
        ->and($request->headings())->toBe(['Value', 'Name', 'Stage']);
});

test('an export inherits the current search, sort and filters', function () {
    $component = Livewire::test(DataViewHarness::class)
        ->set('search', 'Acme')
        ->call('sort', 'value')
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'stage')
        ->set('filters.conditions.0.operator', 'equals')
        ->set('filters.conditions.0.value', 'new');

    $request = $component->instance()->exportRequestForTesting(ExportFormat::Excel);

    expect($request->search)->toBe('Acme')
        ->and($request->sortBy)->toBe('value')
        ->and($request->sortDirection)->toBe('asc')
        ->and($request->filters['conditions'][0]['value'])->toBe('new');

    // And those really do narrow the exported rows.
    expect((new DataViewHarnessExportSource)->exportQuery($request)->pluck('name')->all())
        ->toBe(['Acme Corporation']);
});

test('exporting selected rows only covers exactly that selection', function () {
    $chosen = DataViewRecord::query()->where('stage', 'won')->first();

    $component = Livewire::test(DataViewHarness::class)
        ->call('toggleSelection', $chosen->id)
        ->set('exportSelectedOnly', true);

    $request = $component->instance()->exportRequestForTesting(ExportFormat::Csv);

    expect($request->onlySelected)->toBeTrue()
        ->and($request->selectedIds)->toBe([$chosen->id])
        ->and((new DataViewHarnessExportSource)->exportQuery($request)->pluck('name')->all())
        ->toBe(['Beta Industries']);
});

test('selected-rows-only is ignored when nothing is selected', function () {
    $component = Livewire::test(DataViewHarness::class)->set('exportSelectedOnly', true);

    $request = $component->instance()->exportRequestForTesting(ExportFormat::Csv);

    expect($request->onlySelected)->toBeFalse()
        ->and((new DataViewHarnessExportSource)->exportQuery($request)->count())->toBe(3);
});

test('a small export downloads immediately and is not queued', function () {
    Queue::fake();
    Excel::fake();

    Livewire::test(DataViewHarness::class)->call('export', ExportFormat::Csv->value);

    Queue::assertNothingPushed();
    Excel::assertDownloaded('data-view-records-'.now()->format('Y-m-d-His').'.csv');
});

test('every format produces a download', function (ExportFormat $format) {
    Excel::fake();

    Livewire::test(DataViewHarness::class)
        ->call('export', $format->value)
        ->assertOk();

    Excel::assertDownloaded('data-view-records-'.now()->format('Y-m-d-His').'.'.$format->extension());
})->with(ExportFormat::cases());

test('an unknown format is refused without touching the queue', function () {
    Queue::fake();
    Excel::fake();

    Livewire::test(DataViewHarness::class)->call('export', 'exe')->assertOk();

    Queue::assertNothingPushed();
});

test('an export larger than the threshold is queued and the user told', function () {
    Queue::fake();

    // One row over the line is enough to prove where the line is.
    $rows = [];
    for ($index = 0; $index < RunDataViewExport::QUEUE_THRESHOLD + 1 - 3; $index++) {
        $rows[] = ['name' => 'Bulk '.$index, 'stage' => 'new', 'value' => 1, 'created_at' => now(), 'updated_at' => now()];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DataViewRecord::query()->insert($chunk);
    }

    expect(DataViewRecord::query()->count())->toBe(RunDataViewExport::QUEUE_THRESHOLD + 1);

    Livewire::test(DataViewHarness::class)
        ->call('export', ExportFormat::Csv->value)
        ->assertDispatched('notify');

    Queue::assertPushed(GenerateDataViewExport::class);
});

test('exactly the threshold still downloads inline', function () {
    Queue::fake();
    Excel::fake();

    $rows = [];
    for ($index = 0; $index < RunDataViewExport::QUEUE_THRESHOLD - 3; $index++) {
        $rows[] = ['name' => 'Bulk '.$index, 'stage' => 'new', 'value' => 1, 'created_at' => now(), 'updated_at' => now()];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DataViewRecord::query()->insert($chunk);
    }

    Livewire::test(DataViewHarness::class)->call('export', ExportFormat::Csv->value);

    Queue::assertNothingPushed();
});

test('the queued job writes the file and notifies the user', function () {
    Storage::fake('local');
    Notification::fake();

    $request = Livewire::test(DataViewHarness::class)
        ->instance()
        ->exportRequestForTesting(ExportFormat::Csv);

    (new GenerateDataViewExport($request))->handle();

    Storage::disk('local')->assertExists('exports/'.$this->user->id.'/'.$request->filename());

    Notification::assertSentTo($this->user, ExportReady::class, function (ExportReady $notification) {
        return $notification->rows === 3 && $notification->module === 'data-view-records';
    });
});

test('the queued file holds the headings and the filtered rows', function () {
    Storage::fake('local');
    Notification::fake();

    $request = Livewire::test(DataViewHarness::class)
        ->set('search', 'Beta')
        ->instance()
        ->exportRequestForTesting(ExportFormat::Csv);

    (new GenerateDataViewExport($request))->handle();

    $csv = Storage::disk('local')->get('exports/'.$this->user->id.'/'.$request->filename());

    expect($csv)->toContain('Name', 'Stage', 'Value')
        ->and($csv)->toContain('Beta Industries')
        ->and($csv)->not->toContain('Acme Corporation');
});

test('a failed queued export tells the user without leaking why', function () {
    Notification::fake();

    $request = Livewire::test(DataViewHarness::class)
        ->instance()
        ->exportRequestForTesting(ExportFormat::Csv);

    (new GenerateDataViewExport($request))->failed(new RuntimeException('connection string: user:secret@db'));

    Notification::assertSentTo($this->user, ExportFailed::class, function (ExportFailed $notification) {
        $payload = json_encode($notification->toArray($this->user));

        return ! str_contains($payload, 'secret');
    });
});

test('a screen with no export source hides the menu and refuses to export', function () {
    Queue::fake();
    Excel::fake();

    $component = new class extends DataViewHarness
    {
        public function dataViewExportSource(): ?DataViewExportSource
        {
            return null;
        }
    };

    expect($component->canExport())->toBeFalse()
        ->and($component->export(ExportFormat::Csv->value))->toBeNull();

    Queue::assertNothingPushed();
});
