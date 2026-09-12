<?php

namespace App\Http\Controllers;

use App\Domain\Mail\Actions\RecordEmailEventAction;
use App\Domain\Mail\MailConfiguration;
use App\Domain\Mail\Webhooks\MailWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where providers report what happened to a message.
 *
 * Unauthenticated by necessity — the caller is Mailgun, not a person — so three
 * things stand in for a session:
 *
 * 1. **An unguessable path.** The token is derived from the application key and
 *    compared in constant time. Half of these providers do not sign anything and
 *    tell you to keep the URL secret; this is that.
 * 2. **The provider's own signature**, where there is one. Mailgun's HMAC and
 *    SendGrid's ECDSA are both checked when a key is configured.
 * 3. **Nothing the payload says is trusted to name anything but a message.** An
 *    event for a message this application did not send is dropped, not created.
 *
 * It always answers 200 to a request that got past the token. A provider that
 * sees an error retries, for hours, and an unrecognised event type is not a
 * reason to be sent the same thing two hundred more times.
 */
class MailWebhookController extends Controller
{
    public function __construct(
        private readonly RecordEmailEventAction $record,
        private readonly MailConfiguration $configuration,
    ) {}

    public function __invoke(Request $request, string $provider, string $token): JsonResponse
    {
        if (! MailWebhooks::tokenMatches($token)) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $handler = MailWebhooks::for($provider);

        if ($handler === null) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $credentials = $this->configuration->credentialsForKey($provider);

        if (! $handler->verify($request, $credentials)) {
            return response()->json(['message' => 'Signature mismatch.'], Response::HTTP_FORBIDDEN);
        }

        $applied = 0;

        foreach ($handler->parse($request) as $event) {
            $applied += $this->record->handle($provider, $event);
        }

        return response()->json(['applied' => $applied]);
    }
}
