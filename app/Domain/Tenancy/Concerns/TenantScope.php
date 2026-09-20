<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The scope itself, kept beside the trait because it is not useful apart from
 * it.
 *
 * When no workspace is set the query is narrowed to **nothing** rather than
 * widened to everything. That is the choice that decides what a mistake costs:
 * a missing tenant then shows an empty screen, which somebody reports in
 * minutes, instead of every customer's data, which nobody reports until it is
 * on the internet.
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->isUnscoped()) {
            return;
        }

        $id = $tenancy->id();

        if ($id === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $id);
    }
}
