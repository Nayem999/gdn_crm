<?php

namespace App\Domain\CustomFields\Concerns;

use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\CustomFields\CustomFieldSchema;
use App\Domain\CustomFields\CustomFieldValidator;
use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Custom field answers on a module's own create/edit form.
 *
 * A form using this declares its module, calls `loadCustomFields()` in mount
 * and `validateCustomFields()` before saving, and hands `$this->customFields`
 * to the record afterwards. The four lines are the same in every form, which is
 * the point: a new field appears on all five without any of them changing.
 *
 * The answers live in one public array property rather than a property per
 * field, because the fields are not known until runtime — a Livewire component
 * cannot declare a property for something an administrator has not invented yet.
 */
trait WithCustomFieldForm
{
    /**
     * The answers being edited, keyed by field key.
     *
     * @var array<string, mixed>
     */
    public array $customFields = [];

    /**
     * The module whose fields this form shows. Implemented by the component.
     */
    abstract public function customFieldModule(): string;

    /**
     * The active definitions for this form, in order.
     *
     * @return Collection<int, CustomField>
     */
    public function customFieldDefinitions(): Collection
    {
        return app(CustomFieldSchema::class)->forModule($this->customFieldModule());
    }

    public function hasCustomFields(): bool
    {
        return $this->customFieldDefinitions()->isNotEmpty();
    }

    /**
     * The definitions grouped into the sections the form draws, in order.
     *
     * Sections appear in the order their first field does, so reordering fields
     * reorders sections — there is no separate section ordering to keep in step
     * with the field ordering.
     *
     * @return array<string, array<int, CustomField>>
     */
    public function customFieldSections(): array
    {
        $sections = [];

        foreach ($this->customFieldDefinitions() as $field) {
            $sections[$field->sectionName()][] = $field;
        }

        return $sections;
    }

    /**
     * What a visibility condition is evaluated against: the form's own
     * properties, plus the custom answers under `cf:` keys.
     *
     * Built here rather than in the component so every form answers the
     * question the same way, and so a condition can name a standard field
     * (`status`) and a custom one (`cf:industry_sector`) in one vocabulary.
     *
     * @return array<string, mixed>
     */
    public function customFieldConditionValues(): array
    {
        $values = [];

        foreach (get_object_vars($this) as $name => $value) {
            if ($name !== 'customFields') {
                $values[$name] = $value;
            }
        }

        foreach ($this->customFields as $key => $value) {
            $values['cf:'.$key] = $value;
        }

        return $values;
    }

    /**
     * Whether one field is currently on screen.
     */
    public function isCustomFieldVisible(CustomField $field): bool
    {
        return $field->isVisible($this->customFieldConditionValues());
    }

    /**
     * Fill the form: a record's stored answers when editing, the field's own
     * default when creating.
     *
     * Every active field gets a key, even an unanswered one. Livewire binds
     * `customFields.industry_sector` to an input, and a key that is not there
     * when the component boots is a key the model never learns about.
     */
    public function loadCustomFields(?Model $record = null): void
    {
        $stored = $record !== null && method_exists($record, 'customFieldValuesByKey')
            ? $record->customFieldValuesByKey()
            : [];

        $values = [];

        foreach ($this->customFieldDefinitions() as $field) {
            $values[$field->key] = $stored[$field->key]
                ?? $this->defaultFor($field);
        }

        // Every active field gets a key whether it is currently visible or not:
        // a condition can turn in its favour while the form is open, and a key
        // Livewire never knew about cannot be bound to when it does.

        $this->customFields = $values;
    }

    /**
     * Validate the answers, and put any lookup problems on the right field.
     *
     * Rules come from CustomFieldValidator, so a form and a queued import are
     * checked by the same code. The lookup check is separate because it needs
     * the viewer — an id that exists is not an id this person may use.
     */
    public function validateCustomFields(User $viewer): void
    {
        $fields = $this->customFieldDefinitions();

        if ($fields->isEmpty()) {
            return;
        }

        $validator = app(CustomFieldValidator::class);
        $conditionValues = $this->customFieldConditionValues();

        // A hidden field is not required. The person cannot see it, so
        // insisting on it makes the form unsubmittable with no visible error —
        // and the server has to decide this, not the browser, or the two
        // disagree the moment somebody has JavaScript off.
        $this->validate(
            $validator->rules($fields, conditionValues: $conditionValues),
            [],
            $validator->attributes($fields),
        );

        $visible = $fields->filter(fn (CustomField $field): bool => $field->isVisible($conditionValues));

        $lookupErrors = $validator->lookupErrors($visible, $this->customFields, $viewer);

        if ($lookupErrors === []) {
            return;
        }

        // Thrown rather than added to the bag, so a caller that only knows
        // "validate() throws on failure" stops here exactly as it would for a
        // failed rule. Livewire turns it back into errors on the right keys.
        throw ValidationException::withMessages(
            array_combine(
                array_map(static fn (string $key): string => 'customFields.'.$key, array_keys($lookupErrors)),
                array_values($lookupErrors),
            )
        );
    }

    /**
     * The choices a lookup field offers, scoped to what this person may see.
     *
     * @return array<int, string>
     */
    public function customFieldLookupOptions(CustomField $field, User $viewer): array
    {
        $module = (string) $field->lookup_module;
        $query = CustomFieldRegistry::visibleQuery($module, $viewer);

        if ($query === null) {
            return [];
        }

        $options = [];

        // Bounded: a picker is a dropdown, not an export. Beyond this the field
        // needs a searchable remote source, which is 4.6's generator work.
        foreach ($query->limit(200)->get() as $record) {
            $options[(int) $record->getKey()] = CustomFieldRegistry::recordLabel($record);
        }

        return $options;
    }

    /**
     * The signed-in person, typed.
     *
     * A lookup's choices and its validation both depend on who is asking, and
     * a form that shrugged at a missing user would validate a lookup against
     * nobody's access level.
     */
    public function customFieldViewer(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    /**
     * A new record's starting answer for one field.
     *
     * @return string|float|bool|array<int, string>|int|null
     */
    private function defaultFor(CustomField $field): string|float|bool|array|int|null
    {
        $type = $field->type();
        $default = $field->default_value;

        if ($type->isMultiple()) {
            return [];
        }

        if ($type === CustomFieldType::Checkbox) {
            return $default !== null && $default !== '' && $default !== '0';
        }

        return $default ?? '';
    }
}
