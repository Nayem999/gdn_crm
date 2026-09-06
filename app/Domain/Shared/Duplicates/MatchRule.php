<?php

namespace App\Domain\Shared\Duplicates;

use Illuminate\Database\Eloquent\Model;

/**
 * One field — or a few read together — that a module matches duplicates on.
 *
 * The columns are named by the module, never by the browser: DuplicateFinder
 * only ever reads the fields its source declares here.
 */
readonly class MatchRule
{
    /**
     * @param  array<int, string>  $fields  Read in order and joined by a space, so a
     *                                      person's name can be matched across two columns.
     */
    public function __construct(
        public array $fields,
        public MatchStrategy $strategy,
        public ?string $label = null,
    ) {}

    public static function email(string $field = 'email'): self
    {
        return new self([$field], MatchStrategy::Email);
    }

    public static function phone(string $field = 'phone', ?string $label = null): self
    {
        return new self([$field], MatchStrategy::Phone, $label);
    }

    public static function company(string $field, ?string $label = null): self
    {
        return new self([$field], MatchStrategy::Company, $label);
    }

    /**
     * @param  array<int, string>  $fields
     */
    public static function personName(array $fields, ?string $label = null): self
    {
        return new self($fields, MatchStrategy::PersonName, $label);
    }

    public function label(): string
    {
        return $this->label ?? $this->strategy->label();
    }

    /**
     * A stable identifier for this rule, used to report which rules matched.
     */
    public function key(): string
    {
        return $this->strategy->value.':'.implode('+', $this->fields);
    }

    /**
     * The fingerprint of this rule on a record, or null when there is nothing
     * worth matching on.
     */
    public function fingerprint(Model $record): ?string
    {
        $parts = [];

        foreach ($this->fields as $field) {
            $value = $record->getAttribute($field);

            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        }

        return $this->strategy->normalise(implode(' ', $parts));
    }
}
