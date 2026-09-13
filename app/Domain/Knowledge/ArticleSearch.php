<?php

namespace App\Domain\Knowledge;

use App\Domain\Knowledge\Models\Article;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Finding the article somebody actually meant.
 *
 * Scored in SQL from four columns rather than through a FULLTEXT index, for two
 * reasons. MySQL and MariaDB disagree about minimum token length and stopwords,
 * so the same query ranks differently on the two engines this application runs
 * on — a three-letter product name is unsearchable on one of them. And a
 * weighted score is something a test can assert exactly, which an engine's own
 * ranking is not.
 *
 * The weights say what matters: an article *titled* after what you typed is the
 * one you meant, and the body is the weakest signal because a long article
 * mentions everything once.
 */
class ArticleSearch
{
    /**
     * The whole phrase, found in the title. Nothing outranks this.
     */
    public const TITLE_PHRASE = 100;

    /**
     * One of the words, in the title.
     */
    public const TITLE_TERM = 40;

    /**
     * In the keywords — the words people type that the article does not
     * contain. Deliberately close to a title hit: "won't turn on" is exactly
     * what somebody searches for and never what an article is called.
     */
    public const KEYWORD_TERM = 30;

    public const EXCERPT_TERM = 10;

    /**
     * The weakest signal: a long article mentions everything once.
     */
    public const BODY_TERM = 5;

    /**
     * Shorter than this and a term matches half the library.
     */
    public const MIN_TERM_LENGTH = 2;

    public const MAX_TERMS = 8;

    /**
     * The articles matching a query, best first.
     *
     * @return Collection<int, Article>
     */
    public function search(string $query, ?User $user = null, int $limit = 20): Collection
    {
        $builder = $this->query($query, $user);

        if ($builder === null) {
            return new Collection;
        }

        return $builder->limit($limit)->get();
    }

    /**
     * The scored query, or null when there is nothing worth searching for.
     *
     * Null rather than an empty result set, so a caller can tell "you typed
     * nothing" from "nothing matched" and say the right thing.
     *
     * @return Builder<Article>|null
     */
    public function query(string $query, ?User $user = null): ?Builder
    {
        $terms = $this->terms($query);
        $phrase = trim(Str::squish($query));

        if ($terms === [] && mb_strlen($phrase) < self::MIN_TERM_LENGTH) {
            return null;
        }

        $builder = Article::query()->readableBy($user)->with('category:id,name,parent_id');

        $score = [];
        $bindings = [];

        if ($phrase !== '') {
            $score[] = '(CASE WHEN kb_articles.title LIKE ? THEN '.self::TITLE_PHRASE.' ELSE 0 END)';
            $bindings[] = '%'.$this->escape($phrase).'%';
        }

        foreach ($terms as $term) {
            $like = '%'.$this->escape($term).'%';

            foreach ([
                'title' => self::TITLE_TERM,
                'keywords' => self::KEYWORD_TERM,
                'excerpt' => self::EXCERPT_TERM,
                'body' => self::BODY_TERM,
            ] as $column => $weight) {
                $score[] = '(CASE WHEN kb_articles.'.$column.' LIKE ? THEN '.$weight.' ELSE 0 END)';
                $bindings[] = $like;
            }
        }

        $expression = implode(' + ', $score);

        return $builder
            ->select('kb_articles.*')
            ->selectRaw('('.$expression.') as relevance', $bindings)
            // The score decides what matches, so the threshold and the ordering
            // cannot disagree about which articles are in the list.
            ->havingRaw('relevance > 0')
            ->orderByDesc('relevance')
            // Ties broken by what readers found useful, then by recency: two
            // equally relevant articles are not equally good answers.
            ->orderByDesc('helpful_count')
            ->orderByDesc('published_at')
            ->orderByDesc('kb_articles.id');
    }

    /**
     * What one article scores for a query, for a screen that wants to say why.
     */
    public function score(Article $article, string $query): int
    {
        $phrase = trim(Str::squish($query));
        $score = 0;

        if ($phrase !== '' && Str::contains($article->title, $phrase, ignoreCase: true)) {
            $score += self::TITLE_PHRASE;
        }

        foreach ($this->terms($query) as $term) {
            foreach ([
                $article->title => self::TITLE_TERM,
                (string) $article->keywords => self::KEYWORD_TERM,
                (string) $article->excerpt => self::EXCERPT_TERM,
                $article->body => self::BODY_TERM,
            ] as $haystack => $weight) {
                if (Str::contains($haystack, $term, ignoreCase: true)) {
                    $score += $weight;
                }
            }
        }

        return $score;
    }

    /**
     * The words worth searching for.
     *
     * @return array<int, string>
     */
    public function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', Str::lower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = array_values(array_unique(array_filter(
            $words,
            fn (string $word) => mb_strlen($word) >= self::MIN_TERM_LENGTH
        )));

        // Capped so a pasted paragraph does not become a hundred-branch CASE.
        return array_slice($terms, 0, self::MAX_TERMS);
    }

    /**
     * LIKE has its own wildcards, and a customer searching for "100%" means the
     * character.
     */
    private function escape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
