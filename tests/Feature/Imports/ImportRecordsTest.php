<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Notifier;
use App\Domain\Shared\Actions\RunImportAction;
use App\Domain\Shared\Imports\ImportRegistry;
use App\Domain\Shared\Imports\ImportStatus;
use App\Domain\Shared\Models\DuplicateKey;
use App\Domain\Shared\Models\ImportRun;
use App\Jobs\RunImport;
use App\Livewire\Imports\ImportRecords;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function importer(array $permissions = ['leads.view', 'leads.import', 'leads.create']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * A CSV on the local disk, returned as its stored path.
 *
 * @param  array<int, array<int, string>>  $rows
 * @param  array<int, string>  $headers
 */
function csvAt(array $headers, array $rows, string $name = 'leads.csv'): string
{
    $path = 'imports/test/'.uniqid().'-'.$name;
    Storage::disk('local')->put($path, csvContents($headers, $rows));

    return $path;
}

/**
 * @param  array<int, array<int, string>>  $rows
 * @param  array<int, string>  $headers
 */
function csvUpload(array $headers, array $rows, string $name = 'leads.csv'): UploadedFile
{
    // A faked upload, not a real one: Livewire's test helper reads the `name`
    // property that only fakes carry.
    return UploadedFile::fake()->createWithContent($name, csvContents($headers, $rows));
}

/**
 * @param  array<int, array<int, string>>  $rows
 * @param  array<int, string>  $headers
 */
function csvContents(array $headers, array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $headers);

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    rewind($handle);
    $contents = (string) stream_get_contents($handle);
    fclose($handle);

    return $contents;
}

/**
 * @param  array<int, string>  $mapping
 */
function runFor(User $user, string $path, array $mapping, string $module = 'leads'): ImportRun
{
    return ImportRun::create([
        'module' => $module,
        'user_id' => $user->id,
        'original_filename' => 'file.csv',
        'path' => $path,
        'mapping' => $mapping,
        'status' => ImportStatus::Pending->value,
    ]);
}

beforeEach(function () {
    Storage::fake('local');
});

// -- Access ---------------------------------------------------------------------

test('a guest is sent to sign in', function () {
    $this->get(route('imports.create', ['module' => 'leads']))->assertRedirect(route('login'));
});

test('importing needs its own permission, not just the right to create one record', function () {
    $this->actingAs(importer(['leads.view', 'leads.create']))
        ->get(route('imports.create', ['module' => 'leads']))
        ->assertForbidden();
});

test('somebody with the permission gets the screen', function () {
    $this->actingAs(importer())
        ->get(route('imports.create', ['module' => 'leads']))
        ->assertOk()
        ->assertSee('Import leads');
});

test('the import permissions are in the catalogue, so the roles matrix can grant them', function (string $module) {
    expect(PermissionCatalogue::has($module.'.import'))->toBeTrue();
})->with(ImportRegistry::keys());

test('a module the registry does not list cannot be reached', function (string $module) {
    $this->actingAs(importer(['leads.view', 'leads.import', 'users.view']))
        ->get('/import/'.$module)
        ->assertNotFound();
})->with(['users', 'settings', 'deals']);

test('all three modules resolve and render', function (string $module) {
    $this->actingAs(importer([$module.'.view', $module.'.import']))
        ->get(route('imports.create', ['module' => $module]))
        ->assertOk();
})->with(ImportRegistry::keys());

// -- Reading the file --------------------------------------------------------------

test('the heading row is read and each column offered for mapping', function () {
    $file = csvUpload(['First name', 'Surname', 'Email'], [['Dara', 'Okafor', 'dara@acme.test']]);

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile')
        ->assertHasNoErrors()
        ->assertSet('step', ImportRecords::STEP_MAP);

    expect($component->get('headers'))->toBe(['First name', 'Surname', 'Email']);
});

test('headings that match a field are mapped without anybody doing it', function () {
    $file = csvUpload(['First name', 'Last Name', 'e-mail', 'Nothing we know'], [['Dara', 'Okafor', 'a@b.test', 'x']]);

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile');

    $mapping = $component->get('mapping');

    expect($mapping[0])->toBe('first_name')
        ->and($mapping[1])->toBe('last_name')
        ->and($mapping[2])->toBe('email')
        // Nothing sensible to guess, so it is left for the operator.
        ->and($mapping[3])->toBe('');
});

test('a file that is not a spreadsheet is refused', function () {
    $file = UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf');

    Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile')
        ->assertHasErrors('file');
});

test('the uploaded file is kept off any public disk', function () {
    $file = csvUpload(['First name', 'Last name'], [['Dara', 'Okafor']]);

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile');

    // Somebody's customer list: private disk, under their own folder.
    expect($component->get('path'))->toStartWith('imports/')
        ->and(Storage::disk('local')->exists($component->get('path')))->toBeTrue();
});

// -- The mapping ---------------------------------------------------------------------

test('a required field nobody mapped stops the import', function () {
    $file = csvUpload(['First name', 'Email'], [['Dara', 'dara@acme.test']]);

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile');

    expect($component->instance()->missingRequired())->toBe(['Last name'])
        ->and($component->instance()->canImport())->toBeFalse();

    $component->call('import')->assertDispatched('notify', type: 'error');

    expect(Lead::query()->count())->toBe(0);
});

test('one field mapped to two columns stops the import rather than picking one', function () {
    $file = csvUpload(['First name', 'Last name', 'Also last name'], [['Dara', 'Okafor', 'Okafor']]);

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile')
        ->set('mapping.2', 'last_name');

    expect($component->instance()->duplicatedFields())->toBe(['Last name'])
        ->and($component->instance()->canImport())->toBeFalse();
});

test('a mapping naming a field the module does not offer is dropped', function () {
    $file = csvUpload(['First name', 'Last name', 'Secret'], [['Dara', 'Okafor', 'x']]);

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile')
        // Editing the payload to name a column the module never offered.
        ->set('mapping.2', 'status');

    expect($component->instance()->mappedFields())->not->toContain('status');
});

test('a column can be left out of the import', function () {
    $file = csvUpload(['First name', 'Last name', 'Internal note'], [['Dara', 'Okafor', 'ignore me']]);

    Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile')
        ->set('mapping.2', '')
        ->call('import');

    expect(Lead::query()->count())->toBe(1)
        ->and(Lead::query()->first()->description)->toBeNull();
});

// -- The preview ---------------------------------------------------------------------

test('the preview says what will happen without writing anything', function () {
    $file = csvUpload(
        ['First name', 'Last name', 'Email'],
        [
            ['Dara', 'Okafor', 'dara@acme.test'],
            ['', 'Nameless', 'b@acme.test'],
            ['Sam', 'Bare', 'not-an-email'],
        ]
    );

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile');

    $preview = $component->instance()->preview();

    expect($preview['total'])->toBe(3)
        ->and($preview['valid'])->toBe(1)
        ->and($preview['errors'])->toHaveCount(2)
        // Nothing is written by looking.
        ->and(Lead::query()->count())->toBe(0);
});

test('the preview numbers rows the way the file does', function () {
    $file = csvUpload(
        ['First name', 'Last name'],
        [['Dara', 'Okafor'], ['', 'Nameless']]
    );

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile');

    // Heading is row 1, first record row 2, the bad one row 3.
    expect($component->instance()->preview()['errors'][0]->row)->toBe(3);
});

test('the preview names the field a person would recognise', function () {
    $file = csvUpload(['First name', 'Last name'], [['Dara', '']]);

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile');

    expect($component->instance()->preview()['errors'][0]->joined())->toContain('last name');
});

// -- Valid rows import ----------------------------------------------------------------

test('valid rows land and invalid ones are reported', function () {
    $file = csvUpload(
        ['First name', 'Last name', 'Email', 'Company'],
        [
            ['Dara', 'Okafor', 'dara@acme.test', 'Acme Industries'],
            ['', 'Nameless', 'b@acme.test', 'Acme'],
            ['Sam', 'Bare', 'nonsense', 'Bare Co'],
            ['Ada', 'Byron', 'ada@acme.test', 'Acme'],
        ]
    );

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile')
        ->call('import')
        ->assertSet('step', ImportRecords::STEP_RESULT);

    $run = $component->instance()->run();

    expect($run->status())->toBe(ImportStatus::Completed)
        ->and($run->total_rows)->toBe(4)
        ->and($run->imported_rows)->toBe(2)
        ->and($run->failed_rows)->toBe(2)
        ->and(Lead::query()->count())->toBe(2)
        ->and(Lead::query()->pluck('last_name')->all())->toEqualCanonicalizing(['Okafor', 'Byron']);

    expect(array_map(fn ($e) => $e->row, $run->rowErrors()))->toBe([3, 4]);
});

test('an imported record obeys the same rules a typed-in one does', function () {
    $file = csvUpload(
        ['First name', 'Last name', 'Email', 'Company', 'Source'],
        [['Dara', 'Okafor', 'dara@acme.test', 'Acme Industries', 'referral']]
    );

    $user = importer();

    Livewire::actingAs($user)
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile')
        ->call('import');

    $lead = Lead::query()->firstOrFail();

    expect($lead->status())->toBe(LeadStatus::New)
        ->and($lead->owner_id)->toBe($user->id)
        ->and($lead->source()?->value)->toBe('referral')
        // The create action ran, so the duplicate fingerprints exist too.
        ->and(DuplicateKey::query()->where('keyable_id', $lead->id)->count())->toBeGreaterThan(0);
});

test('a value outside the allowed set is refused rather than stored', function () {
    $file = csvUpload(
        ['First name', 'Last name', 'Source'],
        [['Dara', 'Okafor', 'carrier pigeon']]
    );

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile')
        ->call('import');

    expect(Lead::query()->count())->toBe(0)
        ->and($component->instance()->run()->failed_rows)->toBe(1);
});

test('a blank line in the file is not a record', function () {
    $path = csvAt(['First name', 'Last name'], [['Dara', 'Okafor']]);
    Storage::disk('local')->put($path, Storage::disk('local')->get($path)."\n\n");

    $run = app(RunImportAction::class)(runFor(importer(), $path, [0 => 'first_name', 1 => 'last_name']));

    expect($run->total_rows)->toBe(1)
        ->and($run->imported_rows)->toBe(1);
});

test('one bad row does not take the rest of the file with it', function () {
    $rows = [];

    for ($i = 1; $i <= 20; $i++) {
        $rows[] = ['Person'.$i, 'Surname'.$i];
    }

    $rows[9] = ['', 'No first name'];

    $run = app(RunImportAction::class)(
        runFor(importer(), csvAt(['First name', 'Last name'], $rows), [0 => 'first_name', 1 => 'last_name'])
    );

    // Every row is its own transaction, so one refusal costs one row.
    expect($run->imported_rows)->toBe(19)
        ->and($run->failed_rows)->toBe(1);
});

// -- The error report ---------------------------------------------------------------

test('the refused rows can be downloaded as a file to work through', function () {
    $file = csvUpload(['First name', 'Last name'], [['Dara', 'Okafor'], ['', 'Nameless']]);

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile')
        ->call('import');

    $response = $component->instance()->downloadErrors();

    expect($response)->not->toBeNull();

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('Row,Problem')
        ->and($csv)->toContain('Nameless');
});

test('there is nothing to download when every row landed', function () {
    $file = csvUpload(['First name', 'Last name'], [['Dara', 'Okafor']]);

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', $file)
        ->call('readFile')
        ->call('import');

    expect($component->instance()->downloadErrors())->toBeNull();
});

test('the stored error list is capped, and says so', function () {
    $run = runFor(importer(), csvAt(['First name'], [['x']]), [0 => 'first_name']);
    $run->forceFill([
        'failed_rows' => 900,
        'errors' => array_fill(0, ImportRun::MAX_REPORTED_ERRORS, ['row' => 2, 'messages' => ['nope']]),
    ])->save();

    expect($run->errorsTruncated())->toBeTrue()
        ->and($run->rowErrors())->toHaveCount(ImportRun::MAX_REPORTED_ERRORS);
});

// -- Large files queue ------------------------------------------------------------------

test('a file over the threshold goes to the queue instead of making somebody wait', function () {
    Queue::fake();

    $rows = [];

    for ($i = 0; $i <= ImportRecords::QUEUE_THRESHOLD; $i++) {
        $rows[] = ['Person'.$i, 'Surname'.$i];
    }

    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', csvUpload(['First name', 'Last name'], $rows))
        ->call('readFile')
        ->call('import');

    Queue::assertPushed(RunImport::class);

    // Nothing imported yet, and the screen says so.
    expect(Lead::query()->count())->toBe(0)
        ->and($component->instance()->run()->status())->toBe(ImportStatus::Pending);
});

test('a small file is imported there and then', function () {
    Queue::fake();

    Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', csvUpload(['First name', 'Last name'], [['Dara', 'Okafor']]))
        ->call('readFile')
        ->call('import');

    Queue::assertNotPushed(RunImport::class);

    expect(Lead::query()->count())->toBe(1);
});

test('the queued job does the work and tells the person', function () {
    $user = importer();
    $run = runFor($user, csvAt(['First name', 'Last name'], [['Dara', 'Okafor']]), [0 => 'first_name', 1 => 'last_name']);

    (new RunImport($run->id))->handle(app(RunImportAction::class), app(Notifier::class));

    expect($run->fresh()->status())->toBe(ImportStatus::Completed)
        ->and(Lead::query()->count())->toBe(1)
        ->and(NotificationLog::query()
            ->where('event', 'import.finished')->count())->toBeGreaterThan(0);
});

test('a job that gives up leaves the run marked failed rather than importing for ever', function () {
    $run = runFor(importer(), csvAt(['First name'], [['Dara']]), [0 => 'first_name']);
    $run->forceFill(['status' => ImportStatus::Running->value])->save();

    (new RunImport($run->id))->failed(new RuntimeException('the worker died'));

    expect($run->fresh()->status())->toBe(ImportStatus::Failed)
        ->and($run->fresh()->failure_reason)->toBe('the worker died');
});

test('a file that has gone missing is reported, not thrown at the browser', function () {
    $run = runFor(importer(), 'imports/nothing/here.csv', [0 => 'first_name']);

    $run = app(RunImportAction::class)($run);

    expect($run->status())->toBe(ImportStatus::Failed)
        ->and($run->failure_reason)->toContain('no longer there');
});

// -- The other two modules -------------------------------------------------------------

test('contacts import, with the account rules that apply to one', function () {
    $account = Account::factory()->create();
    $user = importer(['contacts.view', 'contacts.import', 'contacts.create']);

    $file = csvUpload(
        ['First name', 'Last name', 'Account id'],
        [['Dana', 'Scully', (string) $account->id], ['Fox', 'Mulder', '999999']],
        'contacts.csv'
    );

    $component = Livewire::actingAs($user)
        ->test(ImportRecords::class, ['module' => 'contacts'])
        ->set('file', $file)
        ->call('readFile')
        ->call('import');

    expect(Contact::query()->count())->toBe(1)
        ->and(Contact::query()->first()->account_id)->toBe($account->id)
        // The first person at an account still becomes its primary.
        ->and(Contact::query()->first()->is_primary)->toBeTrue()
        ->and($component->instance()->run()->failed_rows)->toBe(1);
});

test('accounts import', function () {
    $user = importer(['accounts.view', 'accounts.import', 'accounts.create']);

    Livewire::actingAs($user)
        ->test(ImportRecords::class, ['module' => 'accounts'])
        ->set('file', csvUpload(['Account name', 'Industry'], [['Acme Industries', 'technology']], 'accounts.csv'))
        ->call('readFile')
        ->call('import');

    expect(Account::query()->count())->toBe(1)
        ->and(Account::query()->first()->industry()?->value)->toBe('technology');
});

test('every field a module offers to import is a real column with rules', function (string $module) {
    $source = ImportRegistry::find($module);

    expect($source->key())->toBe($module)
        ->and($source->fields())->not->toBe([])
        ->and($source->requiredFields())->not->toBe([]);

    foreach ($source->fields() as $key => $field) {
        expect($field->key)->toBe($key)
            ->and($field->label)->not->toBeEmpty()
            ->and($field->rules)->not->toBe([]);
    }
})->with(ImportRegistry::keys());

test('rules are only applied to fields the mapping covers', function () {
    $source = ImportRegistry::find('leads');

    // "Required" means "required if you said you would supply it": a module
    // cannot demand a column the file does not have.
    expect($source->rulesFor(['first_name']))->toHaveKey('first_name')
        ->and($source->rulesFor(['first_name']))->not->toHaveKey('last_name');
});

// -- The screen follows the UI standard --------------------------------------------------

test('the mapping screen uses the searchable select, not a plain dropdown', function () {
    $html = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', csvUpload(['First name', 'Last name'], [['Dara', 'Okafor']]))
        ->call('readFile')
        ->html();

    expect(substr_count($html, '<select'))->toBe(substr_count($html, 'tomSelectField('))
        ->and(substr_count($html, '<select'))->toBe(2);
});

test('starting over forgets the file rather than leaving it lying about', function () {
    $component = Livewire::actingAs(importer())
        ->test(ImportRecords::class, ['module' => 'leads'])
        ->set('file', csvUpload(['First name', 'Last name'], [['Dara', 'Okafor']]))
        ->call('readFile');

    $path = $component->get('path');

    $component->call('startOver')
        ->assertSet('step', ImportRecords::STEP_UPLOAD)
        ->assertSet('path', null);

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

test('a run belongs to the person who started it', function () {
    $mine = importer();
    $theirs = importer();

    $run = runFor($theirs, csvAt(['First name'], [['Dara']]), [0 => 'first_name']);

    $component = Livewire::actingAs($mine)->test(ImportRecords::class, ['module' => 'leads']);

    // The browser cannot reach runId at all, and even set server-side the
    // lookup is scoped: a run id is not a capability.
    $component->instance()->runId = $run->id;

    expect($component->instance()->run())->toBeNull();
});

test('the identity of a run cannot be edited from the browser', function () {
    $component = Livewire::actingAs(importer())->test(ImportRecords::class, ['module' => 'leads']);

    // module, path and runId decide what is read and written; none of them may
    // come back changed from the client.
    foreach (['module', 'path', 'runId', 'headers', 'originalFilename'] as $property) {
        expect(fn () => $component->set($property, 'tampered'))
            ->toThrow(Exception::class, 'Cannot update locked property');
    }
});
