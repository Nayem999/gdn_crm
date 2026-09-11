<?php

namespace App\Domain\CustomFields\Actions;

use App\Domain\CustomFields\Models\CustomField;

/**
 * Puts one module's fields in the order the browser submitted.
 *
 * The submitted order is **intersected with what actually exists on that
 * module**, unknown ids are dropped, and anything the browser did not mention
 * is appended behind what it did. A stale page therefore cannot shuffle rows it
 * never showed, and cannot pull a field out of another module — the same rule
 * ReorderPipelineStagesAction follows, and scoping the query by module is the
 * load-bearing half of it.
 */
class ReorderCustomFieldsAction
{
    /**
     * @param  array<int, int|string>  $orderedIds
     * @return int how many fields were repositioned
     */
    public function __invoke(string $module, array $orderedIds): int
    {
        $existing = CustomField::query()
            ->forModule($module)
            ->ordered()
            ->pluck('id')
            ->all();

        $submitted = [];

        foreach ($orderedIds as $id) {
            $id = (int) $id;

            if (in_array($id, $existing, true) && ! in_array($id, $submitted, true)) {
                $submitted[] = $id;
            }
        }

        $final = [...$submitted, ...array_values(array_diff($existing, $submitted))];

        foreach ($final as $position => $id) {
            CustomField::query()->whereKey($id)->update(['position' => $position + 1]);
        }

        return count($final);
    }
}
