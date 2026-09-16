<?php

namespace App\Jobs;

use App\Domain\Meta\Conversions\Actions\SendConversionAction;
use App\Domain\Meta\Models\MetaConversionEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reporting one outcome, off the request that produced it.
 *
 * On the queue because nobody closing a deal should wait for Meta, and because
 * the failure worth surviving is Meta being briefly unreachable. Three attempts
 * rather than the webhook's six: the event keeps its row and its retry button,
 * so a longer automatic fight adds nothing a person cannot start again.
 */
class SendMetaConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 600];
    }

    public function __construct(public readonly int $eventId) {}

    public function handle(SendConversionAction $send): void
    {
        $event = MetaConversionEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        $send($event);
    }
}
