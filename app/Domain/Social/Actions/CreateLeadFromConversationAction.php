<?php

namespace App\Domain\Social\Actions;

use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Models\Lead;
use App\Domain\Social\Enums\MessageDirection;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Referrals\ReferralAttributionAction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turning a conversation into somebody the CRM knows about.
 *
 * Never automatic any more: an agent converts a chat from the inbox, which
 * opens the lead form prefilled from `draft()`, and saving it calls `link()`.
 * `__invoke` is the same thing in one step, for code that has no form.
 *
 * **The attribution is the point.** A lead created here says it came from
 * Messenger or WhatsApp and when, which is what makes the campaign figures in
 * 12.13 able to count it — and what 12.12 reads when it reports an outcome back
 * to Meta. A lead created without it is a lead that cost nothing and came from
 * nowhere.
 */
class CreateLeadFromConversationAction
{
    public function __construct(
        private readonly CreateLeadAction $createLead,
        private readonly ReferralAttributionAction $referralAttribution,
    ) {}

    /**
     * @param  Carbon|null  $capturedAt  When they first wrote. Their moment, not
     *                                   ours: a conversation recorded late still
     *                                   started when it started.
     */
    public function __invoke(SocialConversation $conversation, User $owner, ?Carbon $capturedAt = null): Lead
    {
        [$first, $last] = $this->splitName($conversation->displayName());
        $channel = $conversation->channel();

        $lead = ($this->createLead)(LeadData::fromArray([
            'first_name' => $first,
            'last_name' => $last,
            // From the channel, never from anything the customer typed.
            'source' => $channel->leadSource(),
            'assignees' => [['user_id' => $owner->getKey(), 'priority' => null]],
            'description' => sprintf(
                'Started a %s conversation. Reply in the social inbox.',
                $channel->label(),
            ),
        ]), $owner);

        $this->link($lead, $conversation, $capturedAt);

        return $lead;
    }

    /**
     * What the lead form starts with when converting this chat: the name on
     * their profile, their WhatsApp number, and an email or phone number they
     * typed in the conversation. Everything stays editable.
     *
     * @return array{first_name: string, last_name: string, phone: ?string, email: ?string, source: string, description: string}
     */
    public function draft(SocialConversation $conversation): array
    {
        $channel = $conversation->channel();
        [$first, $last] = $this->splitName((string) $conversation->participant_name, '');

        $said = $conversation->messages()
            ->where('direction', MessageDirection::Inbound->value)
            ->whereNotNull('body')
            ->pluck('body')
            ->implode("\n");

        preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $said, $email);
        preg_match('/\+?\d[\d\s\-]{7,}\d/', $said, $phone);

        return [
            'first_name' => $first,
            'last_name' => (string) $last,
            // A WhatsApp thread is a telephone number; a Messenger one is not,
            // so there only a number they typed will do.
            'phone' => $channel === SocialChannel::WhatsApp
                ? $conversation->participant_handle
                : (isset($phone[0]) ? trim($phone[0]) : null),
            'email' => $email[0] ?? null,
            'source' => $channel->leadSource(),
            'description' => sprintf('Converted from %s chat #%d with %s.', $channel->label(), $conversation->id, $conversation->displayName()),
        ];
    }

    /**
     * Tie a lead to the chat it came from: the attribution the conversation
     * kept (the advertisement, when there was one), the lead's own pointer
     * back to the chat, and the chat's link to the lead when it has none yet
     * (or its linked record has been deleted).
     */
    public function link(Lead $lead, SocialConversation $conversation, ?Carbon $capturedAt = null): void
    {
        $channel = $conversation->channel();
        $moment = $capturedAt ?? $conversation->last_message_at ?? Carbon::now();
        $referral = $conversation->referral();

        // Read from the conversation rather than passed in, so the automatic
        // path and the inbox button attribute identically — including weeks
        // later, when the only remaining record of the advertisement is the one
        // the thread kept.
        $lead->recordAttribution($referral !== null
            ? ($this->referralAttribution)($referral, $channel, $conversation->displayName(), $moment)
            : new MarketingAttribution(
                source: $channel->leadSource(),
                sourceDetail: $conversation->displayName(),
                capturedAt: $moment,
            ));

        $lead->forceFill(['social_conversation_id' => $conversation->getKey()])->save();

        // Linked when it has nobody — or only somebody since deleted, whose
        // id would otherwise keep the chat pointing at nothing.
        if ($conversation->subject() === null) {
            $conversation->forceFill(['lead_id' => $lead->getKey()])->save();
        }
    }

    /**
     * A display name as two columns.
     *
     * The **first** word is the first name, which is the opposite of the
     * ingestion transform's rule and right for this source: Meta gives a profile
     * name that is usually "Dara Okafor", and a mononym — which social profiles
     * are full of — is a first name with no surname.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitName(string $name, string $fallback = 'Social'): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return [$fallback, null];
        }

        $first = array_shift($parts);

        return [(string) $first, $parts === [] ? null : implode(' ', $parts)];
    }
}
