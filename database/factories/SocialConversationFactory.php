<?php

namespace Database\Factories;

use App\Domain\Leads\Models\Lead;
use App\Domain\Social\Enums\ConversationStatus;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\MessagingWindow;
use App\Domain\Social\Models\SocialConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SocialConversation>
 */
class SocialConversationFactory extends Factory
{
    protected $model = SocialConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lastMessage = Carbon::now()->subHour();

        return [
            'channel' => SocialChannel::Messenger->value,
            'external_conversation_id' => 't_'.fake()->unique()->lexify('????????????'),
            'channel_account_id' => (string) fake()->randomNumber(9, true),
            'participant_external_id' => (string) fake()->unique()->randomNumber(9, true),
            'participant_name' => fake()->name(),
            'participant_handle' => null,
            'status' => ConversationStatus::Open->value,
            'unread_count' => 0,
            'last_message_at' => $lastMessage,
            // Open by default: the awkward states are the ones a test asks for
            // by name, and a fixture that started closed would make every
            // ordinary reply test set it up first.
            'window_expires_at' => MessagingWindow::expiresAt(SocialChannel::Messenger, $lastMessage),
        ];
    }

    public function onChannel(SocialChannel $channel): static
    {
        return $this->state(function (array $attributes) use ($channel) {
            $lastMessage = $attributes['last_message_at'] ?? Carbon::now();

            return [
                'channel' => $channel->value,
                'window_expires_at' => MessagingWindow::expiresAt($channel, Carbon::parse($lastMessage)),
            ];
        });
    }

    /**
     * Meta's reply window has closed — the state in which only a template or a
     * message tag may be sent.
     */
    public function windowClosed(): static
    {
        return $this->state(fn () => [
            'last_message_at' => Carbon::now()->subDays(30),
            'window_expires_at' => Carbon::now()->subDays(23),
        ]);
    }

    /**
     * Never written to us — an outbound-first thread, for which Meta has opened
     * no window at all.
     */
    public function neverInbound(): static
    {
        return $this->state(fn () => ['window_expires_at' => null]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_to_id' => $user->id]);
    }

    public function forLead(Lead $lead): static
    {
        return $this->state(fn () => ['lead_id' => $lead->id]);
    }

    public function unread(int $count = 3): static
    {
        return $this->state(fn () => ['unread_count' => $count]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => ConversationStatus::Closed->value]);
    }
}
