<?php

namespace App\Domain\Tenancy\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One customer's workspace.
 *
 * Deliberately thin. A tenant is an identity and a switch — who owns these
 * rows, and may they be served — and nothing about how the business works.
 * Everything that varies per customer (their pipelines, their Meta
 * credentials, their numbering) already lives in the tables that now carry a
 * tenant_id, and pulling any of it up here would make this the second place
 * to look for a setting.
 *
 * It does **not** use BelongsToTenant. This is the row a tenant is, not a row
 * a tenant owns; scoping it to itself would make it invisible.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
