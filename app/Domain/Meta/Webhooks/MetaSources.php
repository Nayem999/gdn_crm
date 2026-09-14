<?php

namespace App\Domain\Meta\Webhooks;

use App\Domain\Ingestion\Enums\DataSourceType;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Meta\Enums\MetaChannel;

/**
 * The data source each Meta channel delivers through.
 *
 * Meta's webhooks ride the ingestion pipeline rather than a second gateway of
 * their own, which means every delivery gets the log, the replay, the health
 * panel and the retry button that were built in 8.9 — and means a failing Meta
 * integration is diagnosed in the same place as a failing anything else.
 *
 * These rows are **provisioned, not configured**. They are created on first use
 * and marked with a provider, and the data-source screens leave a provider's
 * rows alone: letting somebody point Meta's source at another module by hand
 * would be a way to make an unauthenticated webhook write into any table the
 * pipeline can reach.
 *
 * They carry no key and no signing secret of ours, because Meta does not use
 * them — it signs with the app secret, checked by MetaWebhookSignature before
 * anything is captured.
 */
final class MetaSources
{
    public const PROVIDER = 'meta';

    /**
     * The source for a channel, created if this is the first delivery.
     */
    public static function for(MetaChannel $channel): DataSource
    {
        $source = DataSource::query()
            ->withTrashed()
            ->where('provider', self::PROVIDER)
            ->where('name', $channel->sourceName())
            ->first();

        if ($source !== null) {
            // A source somebody deleted while Meta was still connected comes
            // back rather than silently swallowing deliveries: the alternative
            // is leads that arrive at Meta's end and exist nowhere here.
            if ($source->trashed()) {
                $source->restore();
            }

            return $source;
        }

        $source = new DataSource;

        $source->forceFill([
            'name' => $channel->sourceName(),
            'description' => 'Deliveries from Meta. Managed by the Meta integration — connect or disconnect it under Settings → Meta.',
            'provider' => self::PROVIDER,
            'type' => DataSourceType::Push->value,
            'target_module' => $channel->targetModule(),
            // Meta authenticates with its own signature over the app secret, so
            // there is no key of ours to require and no secret of ours to
            // rotate. Both false rather than absent, so the guard does not ask
            // for something that will never arrive.
            'requires_key' => false,
            'requires_signature' => false,
            'is_active' => true,
            'is_sandbox' => false,
        ])->save();

        return $source->refresh();
    }

    /**
     * Whether this source belongs to an integration rather than to an
     * administrator. The data-source screens read it to know what to leave
     * alone.
     */
    public static function isManaged(DataSource $source): bool
    {
        return $source->provider !== null;
    }

    /**
     * Which channel a managed source is for, or null when it is not ours.
     */
    public static function channelFor(DataSource $source): ?MetaChannel
    {
        if ($source->provider !== self::PROVIDER) {
            return null;
        }

        foreach (MetaChannel::cases() as $channel) {
            if ($channel->sourceName() === $source->name) {
                return $channel;
            }
        }

        return null;
    }
}
