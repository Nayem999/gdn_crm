<?php

namespace App\Domain\Ingestion\Enums;

/**
 * What to do when a delivery matches a record already here.
 *
 * The default is Update, because the common case by far is a system telling us
 * about a record of theirs that has changed, and creating a second copy of it
 * is the failure everybody notices.
 */
enum DedupeAction: string
{
    case Update = 'update';
    case Skip = 'skip';
    /**
     * Create anyway. For a source whose deliveries are genuinely events rather
     * than records — a form submission is a new one every time, even from the
     * same person.
     */
    case Create = 'create';

    public function label(): string
    {
        return match ($this) {
            self::Update => 'Update the record it matched',
            self::Skip => 'Leave the existing record alone',
            self::Create => 'Create another record anyway',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
