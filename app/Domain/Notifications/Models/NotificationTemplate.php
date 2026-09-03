<?php

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin-edited message for one event on one channel.
 *
 * @property int $id
 * @property string $event
 * @property string $channel
 * @property string|null $subject
 * @property string $body
 */
class NotificationTemplate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['event', 'channel', 'subject', 'body'];
}
