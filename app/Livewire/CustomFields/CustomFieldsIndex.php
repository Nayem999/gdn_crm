<?php

namespace App\Livewire\CustomFields;

use App\Domain\CustomFields\Actions\DeleteCustomFieldAction;
use App\Domain\CustomFields\Actions\ReorderCustomFieldsAction;
use App\Domain\CustomFields\Actions\SaveCustomFieldAction;
use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\CustomFields\DTOs\CustomFieldData;
use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The fields an administrator has added to each module.
 *
 * One screen with a module selector rather than five screens: the fields differ
 * only by which module they hang off, and a person setting up a CRM moves
 * between them constantly.
 *
 * Everything the browser sends is checked against the registry or against the
 * rows that exist before it reaches the database — the module against
 * `CustomFieldRegistry`, a field id against that module's own fields, the type
 * against its enum.
 */
#[Title('Custom fields')]
class CustomFieldsIndex extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'module', except: 'leads')]
    public string $module = 'leads';

    // -- The editor -----------------------------------------------------------

    public bool $editing = false;

    /** The field being edited, or null when adding a new one. */
    public ?int $editingId = null;

    public string $label = '';

    public string $type = 'text';

    public string $help = '';

    public bool $isRequired = false;

    public bool $isActive = true;

    public string $lookupModule = '';

    public string $defaultValue = '';

    /**
     * The choices being edited, as {key, label} rows. A new one has an empty
     * key; the DTO gives it a permanent one on save.
     *
     * @var array<int, array{key: string, label: string}>
     */
    public array $options = [];

    public function mount(): void
    {
        $this->authorize('viewAny', CustomField::class);

        if (! CustomFieldRegistry::has($this->module)) {
            $this->module = CustomFieldRegistry::keys()[0];
        }
    }

    // -- Reading --------------------------------------------------------------

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function fields(): Collection
    {
        return CustomField::query()
            ->forModule($this->module)
            ->ordered()
            ->withCount('values')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function moduleOptions(): array
    {
        return CustomFieldRegistry::options();
    }

    /**
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        return CustomFieldType::options();
    }

    public function currentType(): CustomFieldType
    {
        return CustomFieldType::tryFrom($this->type) ?? CustomFieldType::Text;
    }

    public function canManage(): bool
    {
        return auth()->user()?->can('create', CustomField::class) ?? false;
    }

    public function selectModule(string $module): void
    {
        if (! CustomFieldRegistry::has($module)) {
            return;
        }

        $this->module = $module;
        $this->cancel();
        unset($this->fields);
    }

    // -- The editor -----------------------------------------------------------

    public function add(): void
    {
        $this->authorize('create', CustomField::class);

        $this->resetEditor();
        $this->editing = true;
    }

    public function edit(int $fieldId): void
    {
        $field = $this->field($fieldId);

        if ($field === null) {
            return;
        }

        $this->authorize('update', $field);

        $this->editingId = $field->id;
        $this->label = $field->label;
        $this->type = $field->type()->value;
        $this->help = (string) $field->help;
        $this->isRequired = (bool) $field->is_required;
        $this->isActive = (bool) $field->is_active;
        $this->lookupModule = (string) $field->lookup_module;
        $this->defaultValue = (string) $field->default_value;
        $this->options = $field->options ?? [];
        $this->editing = true;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetEditor();
        $this->resetValidation();
    }

    public function addOption(): void
    {
        $this->options[] = ['key' => '', 'label' => ''];
    }

    public function removeOption(int $index): void
    {
        unset($this->options[$index]);
        $this->options = array_values($this->options);
    }

    /**
     * The type drives which extras the form shows, so a change has to clear
     * what the previous type carried — otherwise switching a dropdown to a text
     * field and back silently keeps options nobody can see.
     */
    public function updatedType(): void
    {
        $type = $this->currentType();

        if (! $type->hasOptions()) {
            $this->options = [];
        } elseif ($this->options === []) {
            $this->addOption();
        }

        if (! $type->isLookup()) {
            $this->lookupModule = '';
        }
    }

    public function save(): void
    {
        $field = $this->editingId === null ? null : $this->field($this->editingId);

        if ($this->editingId !== null && $field === null) {
            return;
        }

        $this->authorize($field === null ? 'create' : 'update', $field ?? CustomField::class);

        $type = $this->currentType();

        $this->validate([
            'label' => ['required', 'string', 'min:2', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(CustomFieldType::options()))],
            'help' => ['nullable', 'string', 'max:500'],
            'defaultValue' => ['nullable', 'string', 'max:255'],
            'lookupModule' => [
                $type->isLookup() ? 'required' : 'nullable',
                'string',
                'in:'.implode(',', CustomFieldRegistry::keys()),
            ],
            // A dropdown with nothing to choose from is not a dropdown.
            'options' => [$type->hasOptions() ? 'required' : 'nullable', 'array'],
            'options.*.label' => [$type->hasOptions() ? 'required' : 'nullable', 'string', 'max:255'],
        ], [], [
            'lookupModule' => 'module to look up',
            'options.*.label' => 'choice',
        ]);

        $data = CustomFieldData::fromArray([
            'module' => $this->module,
            'label' => $this->label,
            'type' => $this->type,
            'help' => $this->help,
            'is_required' => $this->isRequired,
            'is_active' => $this->isActive,
            'options' => $this->options,
            'lookup_module' => $this->lookupModule,
            'default_value' => $this->defaultValue,
        ]);

        $saved = app(SaveCustomFieldAction::class)($data, $field);

        $this->resetEditor();
        unset($this->fields);

        $this->dispatch('notify', type: 'success', message: $saved->label.' saved.');
    }

    // -- Row actions ----------------------------------------------------------

    public function toggleActive(int $fieldId): void
    {
        $field = $this->field($fieldId);

        if ($field === null) {
            return;
        }

        $this->authorize('update', $field);

        $field->forceFill(['is_active' => ! $field->is_active])->save();

        unset($this->fields);

        $this->dispatch(
            'notify',
            type: 'success',
            message: $field->label.($field->is_active ? ' is back on the form.' : ' is hidden from the form.'),
        );
    }

    public function delete(int $fieldId): void
    {
        $field = $this->field($fieldId);

        if ($field === null) {
            return;
        }

        $this->authorize('delete', $field);

        $label = $field->label;

        app(DeleteCustomFieldAction::class)($field);

        unset($this->fields);

        $this->dispatch('notify', type: 'success', message: $label.' and its answers were removed.');
    }

    /**
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $this->authorize('create', CustomField::class);

        app(ReorderCustomFieldsAction::class)($this->module, $orderedIds);

        unset($this->fields);
    }

    public function moveUp(int $fieldId): void
    {
        $this->shift($fieldId, -1);
    }

    public function moveDown(int $fieldId): void
    {
        $this->shift($fieldId, 1);
    }

    private function shift(int $fieldId, int $direction): void
    {
        $ids = $this->fields()->pluck('id')->all();
        $at = array_search($fieldId, $ids, true);

        if ($at === false) {
            return;
        }

        $to = $at + $direction;

        if ($to < 0 || $to >= count($ids)) {
            return;
        }

        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];

        $this->reorder($ids);
    }

    /**
     * A field id, but only one on the module currently on screen. Scoping the
     * lookup by module is what stops a payload reaching into another one.
     */
    private function field(int $fieldId): ?CustomField
    {
        return CustomField::query()->forModule($this->module)->whereKey($fieldId)->first();
    }

    private function resetEditor(): void
    {
        $this->editing = false;
        $this->editingId = null;
        $this->label = '';
        $this->type = CustomFieldType::Text->value;
        $this->help = '';
        $this->isRequired = false;
        $this->isActive = true;
        $this->lookupModule = '';
        $this->defaultValue = '';
        $this->options = [];
    }

    public function render(): View
    {
        return view('livewire.custom-fields.custom-fields-index');
    }
}
