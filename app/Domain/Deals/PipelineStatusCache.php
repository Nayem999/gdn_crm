<?php

namespace App\Domain\Deals;

/**
 * One read of each module's configured status set per request.
 *
 * `PipelineModules::statuses()` is asked by the board, the filter builder and
 * every status chip, and each call is two queries — the module's default
 * pipeline, then its stages. Without a memo a list screen runs a dozen
 * identical pairs per render, which a query-count test on leads caught the
 * moment 4.4 wired it in.
 *
 * Registered as a container singleton, so the memo lives exactly as long as one
 * request (and one test), and is flushed by the actions that change a pipeline
 * — a renamed stage has to show on the very next render, not eventually. Same
 * arrangement as CustomFieldSchema.
 */
class PipelineStatusCache
{
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $byModule = [];

    /**
     * @param  callable(): array<int, array<string, mixed>>  $read
     * @return array<int, array<string, mixed>>
     */
    public function remember(string $module, callable $read): array
    {
        return $this->byModule[$module] ??= $read();
    }

    public function flush(?string $module = null): void
    {
        if ($module === null) {
            $this->byModule = [];

            return;
        }

        unset($this->byModule[$module]);
    }
}
