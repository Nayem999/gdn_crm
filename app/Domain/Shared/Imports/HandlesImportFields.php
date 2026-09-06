<?php

namespace App\Domain\Shared\Imports;

/**
 * The parts of ImportSource every module implements the same way.
 */
trait HandlesImportFields
{
    /**
     * @return array<int, ImportField>
     */
    public function requiredFields(): array
    {
        return array_values(array_filter(
            $this->fields(),
            fn (ImportField $field) => $field->required
        ));
    }

    /**
     * The rules for the fields a mapping actually covers.
     *
     * A field nobody mapped is not validated: "required" means "required if you
     * said you were going to supply it", and a module cannot demand a column a
     * file does not have.
     *
     * @param  array<int, string>  $mappedFields
     * @return array<string, array<int, string>>
     */
    public function rulesFor(array $mappedFields): array
    {
        $rules = [];

        foreach ($this->fields() as $key => $field) {
            if (in_array($key, $mappedFields, true)) {
                $rules[$key] = $field->rules;
            }
        }

        return $rules;
    }
}
