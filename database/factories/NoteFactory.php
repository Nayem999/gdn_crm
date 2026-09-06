<?php

namespace Database\Factories;

use App\Domain\Leads\Models\Lead;
use App\Domain\Timeline\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    protected $model = Note::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notable_type' => (new Lead)->getMorphClass(),
            'notable_id' => Lead::factory(),
            'author_id' => User::factory(),
            'body' => fake()->paragraph(),
        ];
    }

    /**
     * Hang the note off a particular record, whatever module it belongs to.
     */
    public function on(Model $subject): self
    {
        return $this->state(fn () => [
            'notable_type' => $subject->getMorphClass(),
            'notable_id' => $subject->getKey(),
        ]);
    }

    public function by(User $author): self
    {
        return $this->state(fn () => ['author_id' => $author->id]);
    }

    /**
     * A note whose author has since been removed, which the timeline has to
     * render without an author rather than falling over.
     */
    public function authorless(): self
    {
        return $this->state(fn () => ['author_id' => null]);
    }
}
