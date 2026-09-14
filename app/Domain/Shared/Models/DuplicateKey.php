<?php

namespace App\Domain\Shared\Models;

use Database\Factories\DuplicateKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One normalised fingerprint of one record.
 *
 * Written only by SyncDuplicateKeysAction. Nothing here is shown to anybody —
 * it exists so a duplicate check is an indexed lookup rather than a scan.
 *
 * @property int $id
 * @property string $keyable_type
 * @property int $keyable_id
 * @property string $kind
 * @property string $value
 */
class DuplicateKey extends Model
{
    /** @use HasFactory<DuplicateKeyFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['keyable_type', 'keyable_id', 'kind', 'value'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function keyable(): MorphTo
    {
        return $this->morphTo();
    }
}
