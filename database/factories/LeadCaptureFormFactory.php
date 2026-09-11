<?php

namespace Database\Factories;

use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\LeadCaptureForm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadCaptureForm>
 */
class LeadCaptureFormFactory extends Factory
{
    protected $model = LeadCaptureForm::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => LeadCaptureForm::newToken(),
            'name' => 'Contact us',
            'description' => null,
            'fields' => [
                ['key' => 'first_name', 'label' => 'First name', 'required' => true],
                ['key' => 'last_name', 'label' => 'Last name', 'required' => true],
                ['key' => 'email', 'label' => 'Email', 'required' => true],
            ],
            'owner_id' => User::factory(),
            'source' => LeadSource::WebForm->value,
            'submit_label' => 'Send',
            'success_message' => null,
            'redirect_url' => null,
            'is_active' => true,
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * @param  array<int, array{key: string, label?: string, required?: bool}>  $fields
     */
    public function withFields(array $fields): static
    {
        return $this->state(fn () => ['fields' => $fields]);
    }

    public function redirectingTo(string $url): static
    {
        return $this->state(fn () => ['redirect_url' => $url]);
    }
}
