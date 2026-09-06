<?php

namespace App\Livewire\Imports;

use App\Domain\Shared\Actions\RunImportAction;
use App\Domain\Shared\Imports\ImportReader;
use App\Domain\Shared\Imports\ImportRegistry;
use App\Domain\Shared\Imports\ImportRowError;
use App\Domain\Shared\Imports\ImportSource;
use App\Domain\Shared\Imports\ImportStatus;
use App\Domain\Shared\Models\ImportRun;
use App\Jobs\RunImport;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload a file, say which column is which, see what will happen, then import.
 *
 * The preview is the point of the screen: an import that reports its mistakes
 * afterwards is a mess to undo, so every row is validated before anything is
 * written and the counts are shown first.
 */
#[Title('Import')]
class ImportRecords extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    /**
     * Rows above this go to the queue rather than making somebody wait.
     */
    public const QUEUE_THRESHOLD = 500;

    public const STEP_UPLOAD = 'upload';

    public const STEP_MAP = 'map';

    public const STEP_RESULT = 'result';

    #[Locked]
    public string $module;

    public string $step = self::STEP_UPLOAD;

    public ?TemporaryUploadedFile $file = null;

    /**
     * Where the accepted upload was stored, relative to the local disk.
     */
    #[Locked]
    public ?string $path = null;

    #[Locked]
    public ?string $originalFilename = null;

    /**
     * @var array<int, string>
     */
    #[Locked]
    public array $headers = [];

    /**
     * Column index => field key. Blank means "do not import this column".
     *
     * Typed loosely because it comes straight from the browser: mappedFields()
     * is what turns it into something the importer will act on.
     *
     * @var array<int|string, mixed>
     */
    public array $mapping = [];

    #[Locked]
    public ?int $runId = null;

    public function mount(string $module): void
    {
        if (! ImportRegistry::has($module)) {
            abort(404);
        }

        $this->module = $module;
        $this->guardPermission();
    }

    public function source(): ImportSource
    {
        $source = ImportRegistry::find($this->module);

        if ($source === null) {
            abort(404);
        }

        return $source;
    }

    // -- Step one: the file ----------------------------------------------------

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:'.implode(',', ImportReader::allowedExtensions()),
            ],
        ];
    }

    /**
     * Named readFile, not upload: Livewire's own $wire.upload() is the file
     * upload primitive, and a component method of that name is shadowed on
     * the client — wire:submit still reaches it, anything in Alpine does not.
     */
    public function readFile(): void
    {
        $this->guardPermission();
        $this->validate();

        $this->originalFilename = $this->file->getClientOriginalName();
        // Kept on the private disk: an uploaded file is somebody's customer
        // list, and nothing about it belongs anywhere public.
        $this->path = $this->file->storeAs(
            'imports/'.$this->currentUser()->id,
            (string) str()->uuid().'.'.$this->file->getClientOriginalExtension(),
            'local'
        );

        try {
            $this->headers = app(ImportReader::class)->headers($this->path);
        } catch (RuntimeException $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        if ($this->headers === []) {
            $this->addError('file', 'That file has no heading row to map.');

            return;
        }

        $this->mapping = $this->guessMapping();
        $this->step = self::STEP_MAP;
    }

    /**
     * Match each heading to a field by name, so a file with sensible headings
     * needs no mapping at all.
     *
     * @return array<int, string>
     */
    private function guessMapping(): array
    {
        $fields = $this->source()->fields();
        $byLabel = [];

        foreach ($fields as $key => $field) {
            $byLabel[$this->normalise($field->label)] = $key;
            $byLabel[$this->normalise($key)] = $key;
        }

        $mapping = [];

        foreach ($this->headers as $index => $heading) {
            $mapping[$index] = $byLabel[$this->normalise($heading)] ?? '';
        }

        return $mapping;
    }

    private function normalise(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($value));
    }

    // -- Step two: the mapping --------------------------------------------------

    /**
     * @return array<string, string>
     */
    public function fieldOptions(): array
    {
        $options = ['' => 'Do not import'];

        foreach ($this->source()->fields() as $key => $field) {
            $options[$key] = $field->label.($field->required ? ' (required)' : '');
        }

        return $options;
    }

    /**
     * Field keys the mapping covers, with anything unknown or duplicated left
     * out — a column can only be mapped once.
     *
     * @return array<int, string>
     */
    public function mappedFields(): array
    {
        $fields = $this->source()->fields();
        $seen = [];

        foreach ($this->mapping as $column => $field) {
            if (is_string($field) && $field !== '' && array_key_exists($field, $fields)) {
                $seen[(int) $column] = $field;
            }
        }

        return $seen;
    }

    /**
     * Required fields nobody mapped, which is the one thing that stops a file.
     *
     * @return array<int, string>
     */
    public function missingRequired(): array
    {
        $mapped = array_values($this->mappedFields());

        return array_values(array_map(
            fn ($field) => $field->label,
            array_filter(
                $this->source()->requiredFields(),
                fn ($field) => ! in_array($field->key, $mapped, true)
            )
        ));
    }

    /**
     * Fields mapped to more than one column, which would silently discard one.
     *
     * @return array<int, string>
     */
    public function duplicatedFields(): array
    {
        $counts = array_count_values(array_values($this->mappedFields()));
        $fields = $this->source()->fields();

        return array_map(
            fn (string $key) => $fields[$key]->label,
            array_keys(array_filter($counts, fn (int $count) => $count > 1))
        );
    }

    public function canImport(): bool
    {
        return $this->path !== null
            && $this->missingRequired() === []
            && $this->duplicatedFields() === [];
    }

    // -- The preview -------------------------------------------------------------

    /**
     * @return array{total: int, valid: int, errors: array<int, ImportRowError>}|null
     */
    public function preview(): ?array
    {
        if ($this->path === null || ! $this->canImport()) {
            return null;
        }

        try {
            return app(RunImportAction::class)->preview($this->source(), $this->path, $this->mappedFields());
        } catch (RuntimeException $exception) {
            return null;
        }
    }

    // -- Step three: importing -----------------------------------------------------

    public function import(): void
    {
        $this->guardPermission();

        if ($this->path === null || ! $this->canImport()) {
            $this->dispatch('notify', type: 'error', message: 'Map every required field first.');

            return;
        }

        $run = ImportRun::create([
            'module' => $this->module,
            'user_id' => $this->currentUser()->id,
            'original_filename' => (string) $this->originalFilename,
            'path' => $this->path,
            'mapping' => $this->mappedFields(),
            'status' => ImportStatus::Pending->value,
        ]);

        $this->runId = $run->id;
        $this->step = self::STEP_RESULT;

        $rows = app(ImportReader::class)->countDataRows($this->path);

        if ($rows > self::QUEUE_THRESHOLD) {
            RunImport::dispatch($run->id);

            return;
        }

        app(RunImportAction::class)($run);
    }

    public function run(): ?ImportRun
    {
        if ($this->runId === null) {
            return null;
        }

        // Scoped to the person who started it: a run id is not a capability.
        return ImportRun::query()
            ->where('user_id', $this->currentUser()->id)
            ->whereKey($this->runId)
            ->first();
    }

    /**
     * The refused rows as a file somebody can work through and re-upload.
     */
    public function downloadErrors(): ?StreamedResponse
    {
        $run = $this->run();

        if ($run === null || $run->rowErrors() === []) {
            return null;
        }

        $name = 'import-errors-'.$run->id.'.csv';

        return response()->streamDownload(function () use ($run) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Row', 'Problem', 'Row starts with']);

            foreach ($run->rowErrors() as $error) {
                fputcsv($handle, [$error->row, $error->joined(), $error->summary]);
            }

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function startOver(): void
    {
        // The uploaded file has done its job and is somebody's customer list.
        if ($this->path !== null && $this->runId === null) {
            Storage::disk('local')->delete($this->path);
        }

        $this->reset(['file', 'path', 'originalFilename', 'headers', 'mapping', 'runId']);
        $this->step = self::STEP_UPLOAD;
    }

    public function render(): View
    {
        return view('livewire.imports.import-records', [
            'source' => $this->source(),
            'fields' => $this->fieldOptions(),
            'run' => $this->run(),
        ]);
    }

    /**
     * Importing is its own permission per module: it creates records in bulk,
     * which is more than being allowed to add one.
     */
    private function guardPermission(): void
    {
        abort_unless($this->currentUser()->can($this->source()->permission()), 403);
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
