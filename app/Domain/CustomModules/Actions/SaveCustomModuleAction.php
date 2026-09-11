<?php

namespace App\Domain\CustomModules\Actions;

use App\Domain\CustomModules\CustomModuleRegistry;
use App\Domain\CustomModules\DTOs\CustomModuleData;
use App\Domain\CustomModules\Models\CustomModule;

/**
 * Creates or updates a module definition.
 *
 * The only writer of `key`, and it writes it once. A URL, every custom field
 * row on the module and any saved view all refer to a module by key, so
 * reassigning one would silently repoint all of them.
 */
class SaveCustomModuleAction
{
    public function __invoke(CustomModuleData $data, ?CustomModule $module = null): CustomModule
    {
        if ($module === null) {
            $taken = CustomModule::query()->pluck('key')->all();

            $module = new CustomModule;
            $module->forceFill([
                ...$data->toAttributes(),
                'key' => CustomModule::keyFrom($data->name, $taken),
                'position' => (int) CustomModule::query()->max('position') + 1,
            ])->save();
        } else {
            $module->forceFill($data->toAttributes())->save();
        }

        // The registry is memoised per request, and a new or renamed module has
        // to appear in the navigation on the very next render.
        app(CustomModuleRegistry::class)->flush();

        return $module->fresh() ?? $module;
    }
}
