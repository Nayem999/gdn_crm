<?php

namespace App\Domain\Shared;

use Closure;

/**
 * A value worked out once per request.
 *
 * Bound as a singleton, so its lifetime is the container's: one web request,
 * one queued job, one test. That is the whole point — a `static` property would
 * leak between tests and between jobs in a long-running worker, and Laravel's
 * `once()` helper is not flushed between tests either.
 *
 * It holds its values in a plain array rather than binding them into the
 * container directly, because `Container::make()` checks its instances with
 * `isset()` — so a memoised **null** is indistinguishable from nothing at all,
 * and the container goes looking for a class of that name instead. "There is no
 * default pipeline" is a real answer and has to be storable.
 */
class RequestMemo
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public function remember(string $key, Closure $resolve): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $resolve();
        }

        return $this->values[$key];
    }

    /**
     * Drop one key, or everything under a prefix.
     *
     * The prefix form is what an invalidating model event uses: a pipeline
     * changing makes every module's memoised default wrong, and listing the
     * modules here would be a list to keep in step.
     */
    public function forget(string $prefix): void
    {
        foreach (array_keys($this->values) as $key) {
            if ($key === $prefix || str_starts_with($key, $prefix)) {
                unset($this->values[$key]);
            }
        }
    }

    public function flush(): void
    {
        $this->values = [];
    }
}
