<?php

namespace App\Http\Controllers;

use App\Domain\Mail\Actions\RecordEmailEventAction;
use App\Domain\Mail\Enums\EmailEventType;
use App\Domain\Mail\Models\EmailMessage;
use App\Domain\Mail\Tracking\EmailTracking;
use App\Domain\Mail\Webhooks\EmailEventData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The pixel and the rewritten links.
 *
 * Both routes are signed, which does two jobs: it stops somebody inflating
 * another company's open figures, and — for the click route, which takes a
 * destination — it stops the application becoming an open redirect wearing the
 * company's own domain. A phishing link that starts with a real CRM's hostname
 * is worth a great deal to whoever sends it.
 *
 * Neither route ever fails visibly. An unknown tracking id still returns the
 * pixel, and a click still goes where it was going: the person clicking is a
 * customer, and a broken image or an error page is a worse outcome than a
 * statistic nobody recorded.
 */
class EmailTrackingController extends Controller
{
    public function __construct(private readonly RecordEmailEventAction $record) {}

    public function open(Request $request, string $tracking): Response
    {
        $this->apply($tracking, EmailEventType::Opened);

        return response(EmailTracking::PIXEL, 200, [
            'Content-Type' => 'image/gif',
            // Without this, a client that caches the image reports one open for
            // a message somebody read twenty times.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function click(Request $request, string $tracking): RedirectResponse
    {
        $destination = (string) $request->query('url', '');

        // Belt and braces: the signature already guarantees nobody changed
        // this, but a template author can write a javascript: URL and the
        // signature would faithfully protect it.
        if (! preg_match('#^https?://#i', $destination)) {
            abort(404);
        }

        $this->apply($tracking, EmailEventType::Clicked, $destination);

        return redirect()->away($destination);
    }

    private function apply(string $tracking, EmailEventType $type, ?string $url = null): void
    {
        $messages = EmailMessage::query()->where('tracking_id', $tracking)->get();

        foreach ($messages as $message) {
            $this->record->applyTo($message->provider, new EmailEventData(
                messageId: (string) $message->message_id,
                type: $type,
                // To the second. Two fetches in the same second are one event,
                // which is what a client that requests an image twice on one
                // render should count as.
                occurredAt: Carbon::now()->startOfSecond(),
                url: $url,
                payload: ['source' => 'tracking'],
                recipient: $message->to_email,
            ), $message);
        }
    }
}
