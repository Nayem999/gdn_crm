<?php

namespace App\Domain\Shared\Enums;

enum FilterValueMode: string
{
    /** The operator is self-contained, e.g. "is empty". */
    case None = 'none';

    case Single = 'single';

    /** Two bounds, e.g. "is between". */
    case Pair = 'pair';

    /** Any number of choices, e.g. "is any of". */
    case Multiple = 'multiple';
}
