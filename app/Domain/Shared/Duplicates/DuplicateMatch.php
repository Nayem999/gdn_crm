<?php

namespace App\Domain\Shared\Duplicates;

use Illuminate\Database\Eloquent\Model;

/**
 * One record that looks like a duplicate, and why.
 *
 * The reasons travel with the match because "possible duplicate" on its own is
 * not something anybody can act on — an operator needs to see that it was the
 * email that matched, not the company name.
 */
readonly class DuplicateMatch
{
    /**
     * @param  array<int, string>  $reasons  Rule labels that matched, in rule order.
     */
    public function __construct(
        public Model $record,
        public array $reasons,
        public int $score,
    ) {}

    public function confidence(): ?DuplicateConfidence
    {
        return DuplicateConfidence::forScore($this->score);
    }

    /**
     * "Email address and phone number match" — the sentence under a candidate.
     */
    public function summary(): string
    {
        $reasons = array_map(fn (string $reason) => mb_strtolower($reason), $this->reasons);

        if ($reasons === []) {
            return 'No matching fields';
        }

        if (count($reasons) === 1) {
            return ucfirst($reasons[0]).' matches';
        }

        $last = array_pop($reasons);

        return ucfirst(implode(', ', $reasons).' and '.$last).' match';
    }
}
