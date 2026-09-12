<?php

namespace App\Domain\Mail\Webhooks;

use Illuminate\Http\Request;

/**
 * How one provider reports what happened to a message.
 *
 * Every provider does this differently — one event or an array of them, epochs
 * or formatted dates, four names for a bounce — and this is the only place that
 * knows about any of it.
 */
interface MailWebhook
{
    /**
     * The provider key, matching the one messages were sent under.
     */
    public function provider(): string;

    /**
     * The events in this request. An unrecognised event is dropped rather than
     * guessed at: a provider adding a new event type should be a no-op here,
     * not a mis-filed bounce.
     *
     * @return array<int, EmailEventData>
     */
    public function parse(Request $request): array;

    /**
     * Whether the request really came from the provider, beyond the secret in
     * the URL.
     *
     * Only some providers sign their webhooks. One that does not returns true
     * here and leans on the URL token — which is stated plainly rather than
     * dressed up as verification.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function verify(Request $request, array $credentials): bool;
}
