<?php

namespace App\Livewire\Settings;

use App\Domain\Settings\Actions\SaveSettingsAction;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingField;
use App\Domain\Settings\SettingsManager;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edits one registry group.
 *
 * A secret's stored value is never loaded into component state, so it cannot
 * reach the browser through the rendered HTML or Livewire's snapshot. The form
 * only ever holds a *replacement* the administrator has just typed.
 */
class SettingsGroup extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $group = '';

    /** @var array<string, mixed> */
    public array $values = [];

    /**
     * Secret keys the administrator has chosen to replace, so the form shows an
     * input instead of the dots.
     *
     * @var array<int, string>
     */
    public array $replacing = [];

    public function mount(string $group): void
    {
        abort_unless(SettingsRegistry::hasGroup($group), 404);

        $this->authorize('viewAny', Setting::class);

        $this->group = $group;

        $settings = app(SettingsManager::class);

        foreach ($this->fields() as $key => $field) {
            // Secrets start empty and stay empty unless replaced.
            $this->values[$key] = $field->secret ? null : $settings->get($group.'.'.$key);
        }
    }

    /**
     * @return array<string, SettingField>
     */
    public function fields(): array
    {
        return SettingsRegistry::fields($this->group);
    }

    /**
     * Secret fields are dropped entirely for someone without the permission, so
     * they are not rendered, not submitted and not saved.
     *
     * @return array<string, SettingField>
     */
    public function visibleFields(): array
    {
        if ($this->canManageSecrets()) {
            return $this->fields();
        }

        return array_filter($this->fields(), fn (SettingField $field) => ! $field->secret);
    }

    public function canManageSecrets(): bool
    {
        return auth()->user()?->can('settings.secrets') ?? false;
    }

    public function canUpdate(): bool
    {
        return auth()->user()?->can('settings.update') ?? false;
    }

    /**
     * Whether a secret already has a stored value, without revealing it.
     */
    public function hasStoredSecret(string $key): bool
    {
        return app(SettingsManager::class)->isSet($this->group.'.'.$key);
    }

    public function isReplacing(string $key): bool
    {
        return in_array($key, $this->replacing, true);
    }

    public function replace(string $key): void
    {
        $this->authorizeSecret($key);

        $this->replacing = array_values(array_unique([...$this->replacing, $key]));
        $this->values[$key] = null;
    }

    public function cancelReplace(string $key): void
    {
        $this->replacing = array_values(array_diff($this->replacing, [$key]));
        $this->values[$key] = null;
        $this->resetValidation('values.'.$key);
    }

    public function clearSecret(string $key): void
    {
        $this->authorizeSecret($key);

        app(SaveSettingsAction::class)->clearSecret($this->group, $key);

        $this->replacing = array_values(array_diff($this->replacing, [$key]));
        $this->values[$key] = null;

        $this->dispatch('settings-saved', group: $this->group);
    }

    public function save(): void
    {
        $this->authorize('updateAny', Setting::class);

        $this->validate($this->rules(), [], $this->validationAttributes());

        $changed = app(SaveSettingsAction::class)->handle(
            $this->group,
            $this->submittedValues(),
            $this->canManageSecrets()
        );

        // A replaced secret goes back to showing dots once it is stored.
        $this->replacing = [];

        foreach ($this->fields() as $key => $field) {
            if ($field->secret) {
                $this->values[$key] = null;
            }
        }

        $this->dispatch('settings-saved', group: $this->group, changed: count($changed));
    }

    public function render(): View
    {
        $declared = SettingsRegistry::group($this->group);

        return view('livewire.settings.settings-group', [
            'groupLabel' => $declared['label'],
            'groupDescription' => $declared['description'],
        ])->title($declared['label'].' settings');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        $rules = [];

        foreach ($this->visibleFields() as $key => $field) {
            // A stored secret that is not being replaced is not being submitted,
            // so "required" would fail on a form the administrator never touched.
            if ($field->secret && ! $this->isReplacing($key)) {
                continue;
            }

            $rules['values.'.$key] = $field->rules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function validationAttributes(): array
    {
        $attributes = [];

        foreach ($this->fields() as $key => $field) {
            $attributes['values.'.$key] = strtolower($field->label);
        }

        return $attributes;
    }

    /**
     * Only the keys this actor is allowed to write, so a tampered payload
     * carrying a secret key cannot get one saved.
     *
     * @return array<string, mixed>
     */
    private function submittedValues(): array
    {
        return array_intersect_key($this->values, $this->visibleFields());
    }

    private function authorizeSecret(string $key): void
    {
        $field = $this->fields()[$key] ?? null;

        if ($field === null || ! $field->secret) {
            throw ValidationException::withMessages(['values.'.$key => 'That is not a stored credential.']);
        }

        $this->authorize('manageSecrets', Setting::class);
    }
}
