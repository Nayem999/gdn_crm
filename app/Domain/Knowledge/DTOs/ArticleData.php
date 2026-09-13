<?php

namespace App\Domain\Knowledge\DTOs;

/**
 * The fields a create or update carries, already validated.
 *
 * `status` and `published_at` are deliberately absent: PublishArticleAction is
 * the only writer of them, so no form can declare an article published and the
 * two can never disagree about when it was.
 */
readonly class ArticleData
{
    public function __construct(
        public string $title,
        public string $body,
        public ?int $categoryId = null,
        public ?string $excerpt = null,
        public ?string $keywords = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $text = fn (string $key): ?string => match (true) {
            ! array_key_exists($key, $attributes) => null,
            $attributes[$key] === null || trim((string) $attributes[$key]) === '' => null,
            default => trim((string) $attributes[$key]),
        };

        return new self(
            title: trim((string) ($attributes['title'] ?? '')),
            body: trim((string) ($attributes['body'] ?? '')),
            categoryId: match (true) {
                ! array_key_exists('kb_category_id', $attributes) => null,
                $attributes['kb_category_id'] === null || $attributes['kb_category_id'] === '' => null,
                default => (int) $attributes['kb_category_id'],
            },
            excerpt: $text('excerpt'),
            keywords: $text('keywords'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'kb_category_id' => $this->categoryId,
            'excerpt' => $this->excerpt,
            'keywords' => $this->keywords,
        ];
    }
}
