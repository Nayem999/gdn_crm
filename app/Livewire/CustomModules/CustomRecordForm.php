<?php

namespace App\Livewire\CustomModules;

use App\Domain\CustomFields\Concerns\WithCustomFieldForm;
use App\Domain\CustomModules\CustomModuleRegistry;
use App\Domain\CustomModules\Models\CustomModule;
use App\Domain\CustomModules\Models\CustomRecord;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create and edit a record in a generated module.
 *
 * One component for every generated module. The only field of its own is the
 * title; everything else is a 4.1 custom field, rendered and validated by the
 * same trait the five built-in forms use.
 */
class CustomRecordForm extends Component
{
    use AuthorizesRequests;
    use WithCustomFieldForm;

    #[Locked]
    public string $moduleKey = '';

    #[Locked]
    public ?int $recordId = null;

    public string $name = '';

    public ?string $owner_id = null;

    public function mount(string $module, ?int $record = null): void
    {
        $this->moduleKey = $module;
        $this->module();

        $existing = $record === null ? null : $this->findRecord($record);

        if ($existing !== null) {
            $this->authorize('update', $existing);

            $this->recordId = $existing->id;
            $this->name = $existing->name;
            $this->owner_id = (string) $existing->owner_id;
            $this->loadCustomFields($existing);

            return;
        }

        // An id that named nothing this person can reach is not a create form
        // in disguise.
        if ($record !== null) {
            abort(404);
        }

        $this->authorize('create', CustomRecord::class);

        $this->owner_id = (string) auth()->id();
        $this->loadCustomFields();
    }

    public function module(): CustomModule
    {
        $module = app(CustomModuleRegistry::class)->find($this->moduleKey);

        if ($module === null) {
            abort(404);
        }

        return $module;
    }

    /**
     * The module whose custom fields this form shows.
     */
    public function customFieldModule(): string
    {
        return $this->moduleKey;
    }

    public function isEditing(): bool
    {
        return $this->recordId !== null;
    }

    public function save(): void
    {
        $record = $this->recordId === null ? null : $this->findRecord($this->recordId);

        if ($this->recordId !== null && $record === null) {
            abort(404);
        }

        $record === null
            ? $this->authorize('create', CustomRecord::class)
            : $this->authorize('update', $record);

        $this->validate([
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ], [], ['name' => strtolower($this->module()->title_label)]);

        $this->validateCustomFields($this->customFieldViewer());

        $record ??= new CustomRecord;

        $record->forceFill([
            'custom_module_id' => $this->module()->getKey(),
            'name' => $this->name,
            'owner_id' => (int) $this->owner_id,
        ])->save();

        $record->saveCustomFields($this->customFields);

        session()->flash('status', $record->name.' was saved.');

        $this->redirectRoute('custom-modules.index', ['module' => $this->moduleKey], navigate: true);
    }

    /**
     * A record on **this** module, through the viewer's own scope. Scoping by
     * module is what stops an id from another generated module being edited
     * through this one's form.
     */
    private function findRecord(int $recordId): ?CustomRecord
    {
        return CustomRecord::query()
            ->visibleTo(auth()->user())
            ->forModule($this->module())
            ->whereKey($recordId)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    public function ownerOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function render(): View
    {
        return view('livewire.custom-modules.custom-record-form', [
            'module' => $this->module(),
        ]);
    }
}
