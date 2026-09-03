<?php

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One cell of the admin matrix, stored only when it differs from the registry
 * default.
 *
 * @property int $id
 * @property string $event
 * @property string $recipient_type
 * @property string $channel
 * @property bool $enabled
 */
class NotificationSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['event', 'recipient_type', 'channel', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
