<?php

namespace App\Domain\Social\Actions;

use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Models\Lead;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Referrals\ReferralAttributionAction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turning a conversation into somebody the CRM knows about.
 *
 * Two callers and one implementation, deliberately: the first inbound message
 * does this automatically, and an agent can do it by hand from the inbox panel
 * when the automatic one did not happen — because nobody was connected to own
 * it, or because the conversation was tied to a contact that has since gone.
 * Two implementations would be two ideas about what a social lead looks like,
 * and the one that drifts is the one nobody looks at.
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

        $conversation->forceFill(['lead_id' => $lead->getKey()])->save();

        return $lead;
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
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return ['Social', null];
        }

        $first = array_shift($parts);

        return [(string) $first, $parts === [] ? null : implode(' ', $parts)];
    }
}
