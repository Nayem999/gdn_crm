<?php

namespace App\Domain\CustomFields;

use App\Domain\CustomFields\Models\CustomField;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;

/**
 * Validates submitted custom field answers against their definitions.
 *
 * One implementation, used by every way an answer can arrive — a form today, a
 * queued import and the public capture form later. Two copies would eventually
 * disagree, and the one that was laxer would be the one that wrote the data.
 *
 * Rules come from the type (see `CustomFieldType::rules`). The one thing this
 * adds is the **lookup existence check**, which the type cannot make on its own:
 * whether a record exists, and whether this person may reach it, depends on the
 * registry and on the viewer.
 */
class CustomFieldValidator
{
    /**
     * The rules for one module's active fields, keyed as the form nests them.
     *
     * @param  iterable<int, CustomField>  $fields
     * @param  string  $prefix  The form key the answers sit under.
     * @param  array<string, mixed>|null  $conditionValues  What the form holds
     *                                                      now, for deciding which fields are on
     *                                                      screen. Null means treat all as shown.
     * @return array<string, array<int, string>>
     */
    public function rules(iterable $fields, string $prefix = 'customFields', ?array $conditionValues = null): array
    {
        $rules = [];

        foreach ($fields as $field) {
            // A field whose condition does not hold is still type-checked — a
            // value can survive in the payload after the condition turned
            // against it — but it is never required.
            $rules[$prefix.'.'.$field->key] = $conditionValues !== null && ! $field->isVisible($conditionValues)
                ? $field->hiddenRules()
                : $field->rules();

            $itemRules = $field->itemRules();

            if ($itemRules !== []) {
                $rules[$prefix.'.'.$field->key.'.*'] = $itemRules;
            }
        }

        return $rules;
    }

    /**
     * Readable names, so an error reads "The Industry sector field is
     * required" rather than naming the storage key.
     *
     * @param  iterable<int, CustomField>  $fields
     * @return array<string, string>
     */
    public function attributes(iterable $fields, string $prefix = 'customFields'): array
    {
        $attributes = [];

        foreach ($fields as $field) {
            $attributes[$prefix.'.'.$field->key] = strtolower($field->label);
            $attributes[$prefix.'.'.$field->key.'.*'] = strtolower($field->label);
        }

        return $attributes;
    }

    /**
     * Validate a set of answers on their own, outside a Livewire form.
     *
     * @param  iterable<int, CustomField>  $fields
     * @param  array<string, mixed>  $values
     */
    public function make(iterable $fields, array $values, string $prefix = 'customFields'): ValidatorContract
    {
        $fields = is_array($fields) ? $fields : iterator_to_array($fields);

        return Validator::make(
            [$prefix => $values],
            $this->rules($fields, $prefix),
            [],
            $this->attributes($fields, $prefix),
        );
    }

    /**
     * The lookup answers that do not name a record this person can reach.
     *
     * Separate from the rules above because it needs the viewer: an id that
     * exists is not an id this person may use, and a lookup that accepted any
     * integer would be a way to confirm the existence of records outside
     * somebody's access level.
     *
     * @param  iterable<int, CustomField>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, string> field key => message
     */
    public function lookupErrors(iterable $fields, array $values, User $viewer): array
    {
        $errors = [];

        foreach ($fields as $field) {
            if (! $field->type()->isLookup()) {
                continue;
            }

            $submitted = $values[$field->key] ?? null;

            if ($submitted === null || $submitted === '' || $submitted === 0 || $submitted === '0') {
                continue;
            }

            $module = (string) $field->lookup_module;

            if (! CustomFieldRegistry::has($module)) {
                $errors[$field->key] = 'This field points at a module that no longer exists.';

                continue;
            }

            if (CustomFieldRegistry::resolve($module, (int) $submitted, $viewer) === null) {
                $errors[$field->key] = 'Choose a '.strtolower(CustomFieldRegistry::label($module)).' record you can see.';
            }
        }

        return $errors;
    }
}
