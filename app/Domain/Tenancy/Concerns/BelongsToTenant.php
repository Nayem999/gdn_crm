<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Every row of this model belongs to one workspace, and only that workspace
 * can see it.
 *
 * A **global** scope, unlike `ScopesByAccessLevel` beside it, and the
 * difference is the whole point. Record visibility is a business rule somebody
 * chose: it is asked for with `visibleTo()`, and forgetting it shows a
 * salesperson a colleague's lead — embarrassing, recoverable. Tenancy is not a
 * business rule. Forgetting it shows one paying customer another's pipeline,
 * and there is no recovering from that, so it cannot be something a query has
 * to opt into.
 *
 * Three guarantees, and they are meant to be boring:
 *
 * - **Reads are scoped**, including relationship loads, counts and exports,
 *   because a global scope applies to the query builder itself.
 * - **Writes are stamped**, so no call site has to remember, and a row created
 *   in a job or a webhook lands in the right workspace or nowhere.
 * - **Writing across workspaces throws.** Setting a tenant_id that is not the
 *   current one is nearly always a bug, and the one legitimate case
 *   (provisioning, an admin tool) goes through Tenancy::withoutScope() and
 *   says so out loud.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenancy = app(Tenancy::class);

            if ($model->getAttribute('tenant_id') !== null) {
                // Already set. Allowed only when it matches the workspace we
                // are acting for, or when tenancy is deliberately off — the
                // in-between case, a request for one customer writing a row
                // for another, is the leak this exists to stop.
                if (! $tenancy->isUnscoped() && $model->getAttribute('tenant_id') !== $tenancy->id()) {
                    throw new RuntimeException(sprintf(
                        'Refusing to write a %s into workspace %s while acting for workspace %s.',
                        class_basename($model),
                        (string) $model->getAttribute('tenant_id'),
                        (string) ($tenancy->id() ?? 'none'),
                    ));
                }

                return;
            }

            if ($tenancy->isUnscoped()) {
                // Deliberately cross-tenant work that did not name a workspace
                // for this row. There is no sensible default: guessing would
                // put the row in whichever tenant happened to be set last.
                throw new RuntimeException(sprintf(
                    'A %s was created with no workspace while tenancy was switched off. Set tenant_id explicitly.',
                    class_basename($model),
                ));
            }

            $model->setAttribute('tenant_id', $tenancy->require()->id);
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
