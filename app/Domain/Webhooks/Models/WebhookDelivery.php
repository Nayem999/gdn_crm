<?php

namespace App\Domain\Webhooks\Models;

use App\Domain\Webhooks\Enums\WebhookDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing we tried to tell one endpoint.
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property WebhookDeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $error
 * @property Carbon|null $delivered_at
 * @property Carbon|null $last_attempt_at
 */
class WebhookDelivery extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'webhook_endpoint_id',
        'event',
        'payload',
        'status',
        'attempts',
        'response_status',
        'error',
        'delivered_at',
        'last_attempt_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookDeliveryStatus::class,
            'attempts' => 'integer',
            'response_status' => 'integer',
            'delivered_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
