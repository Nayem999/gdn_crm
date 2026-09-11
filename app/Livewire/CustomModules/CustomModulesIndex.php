<?php

namespace App\Livewire\CustomModules;

use App\Domain\CustomModules\Actions\DeleteCustomModuleAction;
use App\Domain\CustomModules\Actions\SaveCustomModuleAction;
use App\Domain\CustomModules\DTOs\CustomModuleData;
use App\Domain\CustomModules\Models\CustomModule;
use App\Domain\Shared\UI\ChipPalette;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Define the modules. Their records live on their own screens.
 */
#[Title('Custom modules')]
class CustomModulesIndex extends Component
{
    use AuthorizesRequests;

    public bool $editing = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $pluralName = '';

    public string $titleLabel = 'Name';

    public string $icon = 'box';

    public string $color = 'slate';

    public string $description = '';

    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', CustomModule::class);
    }

    /**
     * @return Collection<int, CustomModule>
     */
    #[Computed]
    public function modules(): Collection
    {
        return CustomModule::query()->ordered()->withCount('records')->get();
    }

    /**
     * @return array<string, string>
     */
    public function colorOptions(): array
    {
        $options = [];

        foreach (ChipPalette::colors() as $color) {
            $options[$color] = ucfirst($color);
        }

        return $options;
    }

    public function add(): void
    {
        $this->authorize('create', CustomModule::class);
        $this->resetEditor();
        $this->editing = true;
    }

    public function edit(int $moduleId): void
    {
        $module = CustomModule::query()->whereKey($moduleId)->first();

        if ($module === null) {
            return;
        }

        $this->authorize('update', $module);

        $this->editingId = $module->id;
        $this->name = $module->name;
        $this->pluralName = $module->plural_name;
        $this->titleLabel = $module->title_label;
        $this->icon = $module->icon;
        $this->color = $module->color;
        $this->description = (string) $module->description;
        $this->isActive = (bool) $module->is_active;
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetEditor();
        $this->resetValidation();
    }

    public function save(): void
    {
        $module = $this->editingId === null
            ? null
            : CustomModule::query()->whereKey($this->editingId)->first();

        if ($this->editingId !== null && $module === null) {
            return;
        }

        $this->authorize($module === null ? 'create' : 'update', $module ?? CustomModule::class);

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:48'],
            'pluralName' => ['nullable', 'string', 'max:48'],
            'titleLabel' => ['required', 'string', 'max:48'],
            'icon' => ['required', 'string', 'max:48'],
            'color' => ['required', 'string', Rule::in(ChipPalette::colors())],
            'description' => ['nullable', 'string', 'max:500'],
        ], [], ['pluralName' => 'plural name', 'titleLabel' => 'title label']);

        $saved = app(SaveCustomModuleAction::class)(CustomModuleData::fromArray([
            'name' => $this->name,
            'plural_name' => $this->pluralName,
            'title_label' => $this->titleLabel,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
            'is_active' => $this->isActive,
        ]), $module);

        $this->resetEditor();
        unset($this->modules);

        $this->dispatch('notify', type: 'success', message: $saved->plural_name.' saved.');
    }

    public function toggleActive(int $moduleId): void
    {
        $module = CustomModule::query()->whereKey($moduleId)->first();

        if ($module === null) {
            return;
        }

        $this->authorize('update', $module);

        app(SaveCustomModuleAction::class)(
            CustomModuleData::fromArray([
                ...$module->only(['name', 'plural_name', 'title_label', 'icon', 'color', 'description']),
                'is_active' => ! $module->is_active,
            ]),
            $module,
        );

        unset($this->modules);
    }

    public function delete(int $moduleId): void
    {
        $module = CustomModule::query()->whereKey($moduleId)->first();

        if ($module === null) {
            return;
        }

        $this->authorize('delete', $module);

        $name = $module->plural_name;

        app(DeleteCustomModuleAction::class)($module);

        unset($this->modules);

        $this->dispatch('notify', type: 'success', message: $name.' and everything in it were removed.');
    }

    private function resetEditor(): void
    {
        $this->editing = false;
        $this->editingId = null;
        $this->name = '';
        $this->pluralName = '';
        $this->titleLabel = 'Name';
        $this->icon = 'box';
        $this->color = 'slate';
        $this->description = '';
        $this->isActive = true;
    }

    public function render(): View
    {
        return view('livewire.custom-modules.custom-modules-index');
    }
}
