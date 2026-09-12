<?php

namespace Database\Factories;

use App\Domain\Chat\Models\ChatConversation;
use App\Domain\Leads\Models\LeadCaptureForm;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_capture_form_id' => LeadCaptureForm::factory()->state(['kind' => 'chat']),
            'session_id' => (string) Str::uuid(),
            'started_at' => now(),
            'last_message_at' => now(),
        ];
    }
}
