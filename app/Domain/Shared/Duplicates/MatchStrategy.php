<?php

namespace App\Domain\Shared\Duplicates;

/**
 * How one field's value is reduced to a fingerprint, and how much a match on it
 * is worth.
 *
 * Normalisation happens here and nowhere else, so the value written to
 * duplicate_keys and the value looked up are produced by the same code. A
 * strategy that returns null means "this value is too weak to match on" — that
 * is deliberately not the same as "no match", and a rule must never fall
 * through to matching every record with a blank field.
 */
enum MatchStrategy: string
{
    case Email = 'email';
    case Phone = 'phone';
    case Company = 'company';
    case PersonName = 'person_name';

    /**
     * Two numbers agreeing on this many trailing digits are the same line.
     * Enough to see past a country code or a trunk zero, short enough that
     * "+44 117 000 0000" and "0117 000 0000" meet.
     */
    public const PHONE_DIGITS = 9;

    /**
     * Words that say what kind of company something is, not which company.
     */
    private const LEGAL_SUFFIXES = [
        'ltd', 'limited', 'llc', 'lc', 'inc', 'incorporated', 'corp', 'corporation',
        'plc', 'llp', 'lp', 'gmbh', 'ag', 'bv', 'nv', 'sa', 'srl', 'spa', 'oy', 'ab',
        'as', 'aps', 'pty', 'pte', 'co', 'company', 'group', 'holdings', 'holding',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email address',
            self::Phone => 'Phone number',
            self::Company => 'Company name',
            self::PersonName => 'Name',
        };
    }

    /**
     * What a match on this field contributes to the confidence score.
     *
     * An email is an identity; a company name is a coincidence waiting to
     * happen, so it only becomes convincing alongside something else.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Email => 100,
            self::Phone => 85,
            self::PersonName => 45,
            self::Company => 40,
        };
    }

    /**
     * The fingerprint for a value, or null when there is nothing worth matching.
     */
    public function normalise(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return match ($this) {
            self::Email => $this->normaliseEmail($value),
            self::Phone => $this->normalisePhone($value),
            self::Company => $this->normaliseCompany($value),
            self::PersonName => $this->normaliseName($value),
        };
    }

    private function normaliseEmail(string $value): ?string
    {
        $value = mb_strtolower($value);

        // Something without an @ is a typo, not an address; matching on it
        // would pair unrelated records that share a scrap of text.
        return str_contains($value, '@') ? $value : null;
    }

    private function normalisePhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        // Below this it is an extension or a fragment, and would collide freely.
        if (mb_strlen($digits) < 7) {
            return null;
        }

        return mb_substr($digits, -self::PHONE_DIGITS);
    }

    private function normaliseCompany(string $value): ?string
    {
        $words = $this->words($value);

        // "The" carries no information about which company this is.
        if (($words[0] ?? null) === 'the') {
            array_shift($words);
        }

        // Trailing legal form, possibly stacked ("co ltd").
        while ($words !== [] && in_array(end($words), self::LEGAL_SUFFIXES, true)) {
            array_pop($words);
        }

        // One or two letters left is not a company, it is an initial. The
        // separators do not count towards that: "A&B" is two initials.
        return $this->atLeastThreeCharacters(implode(' ', $words));
    }

    private function normaliseName(string $value): ?string
    {
        return $this->atLeastThreeCharacters(implode(' ', $this->words($value)));
    }

    /**
     * Anything shorter than three characters of actual content is initials or
     * noise, and would collide with half the table.
     */
    private function atLeastThreeCharacters(string $normalised): ?string
    {
        return mb_strlen(str_replace(' ', '', $normalised)) < 3 ? null : $normalised;
    }

    /**
     * Lower-cased alphanumeric words, punctuation and spacing thrown away, so
     * "Acme-Industries", "acme industries" and "ACME  Industries." agree.
     *
     * @return array<int, string>
     */
    private function words(string $value): array
    {
        $cleaned = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)) ?? '';

        return array_values(array_filter(explode(' ', trim($cleaned)), fn (string $word) => $word !== ''));
    }
}
