<?php

namespace App\Http\Controllers;

use App\Domain\Chat\Actions\RecordChatMessageAction;
use App\Domain\Leads\Models\LeadCaptureForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A message from the website chat widget.
 *
 * The second unauthenticated write path in the application, and it carries the
 * same guards as the first: the token in the URL is the only credential, the
 * owner and source come from the widget rather than the payload, and the route
 * is rate limited before any of this runs.
 *
 * It answers JSON rather than a page because the widget lives on somebody
 * else's site and needs an answer it can act on.
 */
class ChatCaptureController extends Controller
{
    public function __invoke(Request $request, string $token, RecordChatMessageAction $record): JsonResponse
    {
        $widget = LeadCaptureForm::query()->where('token', $token)->first();

        // A form token is not a chat token. Rendering one as the other would
        // mean a public form could be driven through an interface that never
        // ran its honeypot or its timing check.
        if ($widget === null || ! $widget->isChat()) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        if (! $widget->is_active) {
            // Said plainly rather than silently: a visitor typing a question
            // deserves to know nobody is listening.
            return response()->json(['message' => 'Chat is closed right now.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'page_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $conversation = $record->handle(
            $widget,
            $validated['session_id'],
            $validated['message'],
            [
                'name' => $validated['name'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'page_url' => $validated['page_url'] ?? null,
            ],
        );

        // What the widget needs and nothing else. Whether a lead exists is
        // useful — it is how the widget knows to stop asking for an address —
        // and the lead's id is not the visitor's business.
        return response()->json([
            'session_id' => $conversation->session_id,
            'identified' => $conversation->lead_id !== null,
        ]);
    }
}
