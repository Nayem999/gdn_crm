<?php

namespace App\Domain\Webhooks\Models;

use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somewhere to tell when something happens.
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property array<int, string> $events
 * @property bool $is_active
 */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'created_by',
    ];

    /**
     * The secret never reaches anything that serialises this model.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            // Encrypted at rest: a receiver has to verify signatures with it,
            // so it cannot be hashed — but a database backup should not carry
            // every integration's secret away in plain text.
            'secret' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class)->orderByDesc('id');
    }

    public function wants(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events ?? [], true);
    }
}
