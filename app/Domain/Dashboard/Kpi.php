<?php

namespace App\Domain\Dashboard;

/**
 * One headline figure.
 *
 * The value arrives **already formatted**, because money is written to the
 * configured separators and currency and re-implementing that in Blade is how
 * two screens end up disagreeing about the same number. `raw` is kept beside it
 * for tests and for the "is this zero" check the empty state needs.
 */
final readonly class Kpi
{
    public function __construct(
        public string $key,
        public string $label,
        public string $value,
        public float|int $raw,
        public string $icon,
        public string $color,
        /**
         * A unit shown in front of the figure, for money. The rest of the
         * application prints amounts bare (see DealsIndex::cellFor), so this is
         * a label on the card rather than a new formatting convention.
         */
        public ?string $unit = null,
        /** The line under the figure: what it counts, or over what window. */
        public ?string $caption = null,
        /** Where the figure came from, so the card is a way in. */
        public ?string $href = null,
    ) {}

    public function isZero(): bool
    {
        return (float) $this->raw === 0.0;
    }
}
