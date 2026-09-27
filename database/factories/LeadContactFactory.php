<?php

namespace Database\Factories;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadContact>
 */
class LeadContactFactory extends Factory
{
    protected $model = LeadContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'contact_id' => Contact::factory(),
            'position' => 0,
        ];
    }
}
