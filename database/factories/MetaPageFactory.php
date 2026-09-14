<?php

namespace Database\Factories;

use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaPage>
 */
class MetaPageFactory extends Factory
{
    protected $model = MetaPage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meta_account_id' => MetaAccount::factory(),
            'page_id' => (string) fake()->unique()->randomNumber(9, true),
            'name' => fake()->company(),
            'category' => 'Business',
            // A page token of its own, which is what makes a page usable after
            // the person who authorised it has gone home.
            'access_token' => 'EAAPage'.fake()->lexify('????????????'),
            'is_subscribed' => true,
            'subscribed_at' => now(),
            'last_synced_at' => now(),
        ];
    }

    /**
     * Listed during the wizard and never finished connecting.
     */
    public function unconnected(): static
    {
        return $this->state(fn () => [
            'access_token' => null,
            'is_subscribed' => false,
            'subscribed_at' => null,
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn () => ['is_subscribed' => false, 'subscribed_at' => null]);
    }
}
