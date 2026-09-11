<?php

namespace App\Domain\CustomModules;

use App\Domain\CustomModules\Models\CustomModule;
use Illuminate\Support\Collection;

/**
 * The generated modules, read once per request.
 *
 * `CustomFieldRegistry` asks whether a module key names one on nearly every
 * call, and a list screen calls that several times per render. Without a memo
 * each is a query, which is the same trap `CustomFieldSchema` and
 * `PipelineStatusCache` already solved — this is the third, and the last
 * registry that needed it.
 *
 * A container singleton, so the memo lives exactly as long as one request, and
 * flushed by the actions that change a module definition.
 */
class CustomModuleRegistry
{
    /**
     * @var Collection<string, CustomModule>|null
     */
    private ?Collection $modules = null;

    /**
     * Active modules, keyed by the prefixed key the rest of the application
     * knows them by.
     *
     * @return Collection<string, CustomModule>
     */
    public function all(): Collection
    {
        return $this->modules ??= CustomModule::query()
            ->active()
            ->ordered()
            ->get()
            ->keyBy(fn (CustomModule $module): string => $module->moduleKey());
    }

    public function find(string $moduleKey): ?CustomModule
    {
        return $this->all()->get($moduleKey);
    }

    public function has(string $moduleKey): bool
    {
        return $this->find($moduleKey) !== null;
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->all()
            ->mapWithKeys(fn (CustomModule $module): array => [$module->moduleKey() => $module->plural_name])
            ->all();
    }

    public function flush(): void
    {
        $this->modules = null;
    }
}
