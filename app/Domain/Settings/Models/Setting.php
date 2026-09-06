<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Enums\SettingType;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored setting row.
 *
 * The `value` column holds text: the serialised form for ordinary settings, and
 * ciphertext for secrets. Nothing here decrypts — that is SettingsManager's job,
 * so a secret cannot leak by someone serialising a model.
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property string|null $value
 * @property string $type
 * @property bool $is_secret
 */
class Setting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'is_secret',
    ];

    /**
     * Keep the stored value out of anything that serialises a model — an API
     * resource, a log context, a dd() in a response. Read it deliberately
     * through SettingsManager instead.
     *
     * @var list<string>
     */
    protected $hidden = ['value'];

    /**
     * Columns that share a name with a method on this model.
     *
     * Without a default the key can be missing on a freshly created instance,
     * and Laravel then takes the property read for a relation and calls the
     * method. See .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'string',
    ];

    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
        ];
    }

    public function type(): SettingType
    {
        return SettingType::tryFrom((string) $this->getAttributeValue('type')) ?? SettingType::String;
    }

    public function name(): string
    {
        return $this->group.'.'.$this->key;
    }
}
