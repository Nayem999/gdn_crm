<?php

namespace Database\Factories;

use App\Domain\Knowledge\Enums\ArticleStatus;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(5);

        return [
            'kb_category_id' => null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'excerpt' => fake()->sentence(),
            'body' => fake()->paragraphs(3, true),
            'keywords' => null,
            'status' => ArticleStatus::Draft->value,
            'published_at' => null,
            'author_id' => User::factory(),
            'view_count' => 0,
            'helpful_count' => 0,
            'unhelpful_count' => 0,
        ];
    }

    /**
     * Published, with the stamp that goes with it.
     *
     * Set together, because an article that says "published" with no
     * published_at describes something the action cannot produce.
     */
    public function published(?Carbon $at = null): static
    {
        return $this->state(fn () => [
            'status' => ArticleStatus::Published->value,
            'published_at' => $at ?? now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => ArticleStatus::Archived->value,
            'published_at' => now()->subMonth(),
        ]);
    }

    public function in(Category $category): static
    {
        return $this->state(fn () => ['kb_category_id' => $category->id]);
    }

    public function by(User $user): static
    {
        return $this->state(fn () => ['author_id' => $user->id]);
    }

    /**
     * An article with known words in known places, for a relevance test.
     */
    public function saying(string $title, string $body, ?string $keywords = null, ?string $excerpt = null): static
    {
        return $this->state(fn () => [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'body' => $body,
            'keywords' => $keywords,
            'excerpt' => $excerpt,
        ]);
    }

    public function foundHelpful(int $helpful, int $unhelpful = 0): static
    {
        return $this->state(fn () => ['helpful_count' => $helpful, 'unhelpful_count' => $unhelpful]);
    }
}
