<?php

namespace App\Domain\Chat\Actions;

use App\Domain\Chat\Enums\ChatAuthor;
use App\Domain\Chat\Models\ChatConversation;
use App\Domain\Chat\Models\ChatMessage;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadCaptureForm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One message from a website visitor.
 *
 * The rules are the lead capture form's rules, because this is the same risk
 * wearing a different interface — an unauthenticated write from somebody else's
 * website:
 *
 * - **The owner and the source come from the widget, never the payload.** A
 *   conversation that could choose its owner could route itself to somebody who
 *   would not look at it.
 * - **A lead appears when there is something to reach**, not on the first
 *   "hello". A conversation with no address and no number is kept as a
 *   conversation; making it a lead would be making somebody delete it later.
 * - **A conversation that already has a lead updates it** rather than making
 *   another, however many messages arrive. The session id is what ties them
 *   together, and it is unique.
 */
class RecordChatMessageAction
{
    public function __construct(
        private readonly CreateLeadAction $createLead,
        private readonly UpdateLeadAction $updateLead,
    ) {}

    /**
     * @param  array<string, mixed>  $visitor  Name, email, phone and page as the
     *                                         widget has them; each optional and
     *                                         each able to arrive late.
     */
    public function handle(LeadCaptureForm $widget, string $sessionId, string $body, array $visitor = []): ChatConversation
    {
        return DB::transaction(function () use ($widget, $sessionId, $body, $visitor): ChatConversation {
            $now = Carbon::now();

            $conversation = ChatConversation::query()->firstOrNew(['session_id' => $sessionId]);

            if (! $conversation->exists) {
                $conversation->fill([
                    'lead_capture_form_id' => $widget->id,
                    'started_at' => $now,
                    'page_url' => $this->trimmed($visitor['page_url'] ?? null),
                ]);
            }

            // Details can arrive at any point — plenty of people type three
            // questions before they give a name — so each field is filled in
            // the first time it is offered and never blanked afterwards.
            foreach (['name', 'email', 'phone'] as $key) {
                $value = $this->trimmed($visitor[$key] ?? null);

                if ($value !== null && ($conversation->{'visitor_'.$key} === null || $conversation->{'visitor_'.$key} === '')) {
                    $conversation->{'visitor_'.$key} = $value;
                }
            }

            $conversation->last_message_at = $now;
            $conversation->save();

            ChatMessage::query()->create([
                'chat_conversation_id' => $conversation->id,
                'author' => ChatAuthor::Visitor->value,
                'body' => $body,
                'sent_at' => $now,
            ]);

            $conversation->load('messages');

            $this->syncLead($widget, $conversation);

            return $conversation;
        });
    }

    private function syncLead(LeadCaptureForm $widget, ChatConversation $conversation): void
    {
        if (! $conversation->isIdentifiable()) {
            return;
        }

        $owner = $widget->owner;

        if ($owner === null) {
            return;
        }

        [$first, $last] = $this->splitName($conversation->visitor_name);

        $attributes = [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $conversation->visitor_email,
            'phone' => $conversation->visitor_phone,
            'description' => $conversation->transcript(),
        ];

        if ($conversation->lead_id !== null) {
            $lead = Lead::query()->find($conversation->lead_id);

            if ($lead !== null) {
                // The transcript grows with the conversation; the rest is only
                // filled in, never overwritten, because a rep may have
                // corrected it since.
                // No 'assignees' key: that leaves the lead's existing
                // assignees exactly as they are, the same way the fields this
                // update does not carry are left alone.
                $this->updateLead->__invoke($lead, LeadData::fromArray([
                    ...$lead->only(['first_name', 'last_name', 'email', 'phone', 'company_name', 'job_title']),
                    ...array_filter($attributes, fn ($value): bool => $value !== null && $value !== ''),
                    'source' => $widget->source()->value,
                ]));

                return;
            }
        }

        $lead = $this->createLead->__invoke(LeadData::fromArray([
            ...$attributes,
            // From the widget, never the payload. The widget's one owner
            // becomes the new lead's sole assignee, unprioritised — the same
            // seed an embedded capture form gives.
            'source' => $widget->source()->value,
            'assignees' => [['user_id' => $widget->owner_id, 'priority' => null]],
        ]), $owner);

        $conversation->forceFill(['lead_id' => $lead->id])->save();
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            // A lead needs a name of some sort, and "Website visitor" is at
            // least true. Inventing one from the email local part reads as a
            // real name and is usually wrong.
            return ['Website visitor', null];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = array_shift($parts);

        return [(string) $first, $parts === [] ? null : implode(' ', $parts)];
    }

    private function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
