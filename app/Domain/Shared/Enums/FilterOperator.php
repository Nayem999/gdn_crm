<?php

namespace App\Domain\Shared\Enums;

enum FilterOperator: string
{
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case GreaterThan = 'gt';
    case LessThan = 'lt';
    case GreaterThanOrEqual = 'gte';
    case LessThanOrEqual = 'lte';
    case Between = 'between';
    case On = 'on';
    case Before = 'before';
    case After = 'after';
    case LastDays = 'last_days';
    case In = 'in';
    case NotIn = 'not_in';
    case IsTrue = 'is_true';
    case IsFalse = 'is_false';
    case IsEmpty = 'is_empty';
    case IsNotEmpty = 'is_not_empty';

    public function label(): string
    {
        return match ($this) {
            self::Contains => 'contains',
            self::NotContains => 'does not contain',
            self::Equals => 'is',
            self::NotEquals => 'is not',
            self::StartsWith => 'starts with',
            self::EndsWith => 'ends with',
            self::GreaterThan => 'is greater than',
            self::LessThan => 'is less than',
            self::GreaterThanOrEqual => 'is at least',
            self::LessThanOrEqual => 'is at most',
            self::Between => 'is between',
            self::On => 'is on',
            self::Before => 'is before',
            self::After => 'is after',
            self::LastDays => 'is within the last (days)',
            self::In => 'is any of',
            self::NotIn => 'is none of',
            self::IsTrue => 'is yes',
            self::IsFalse => 'is no',
            self::IsEmpty => 'is empty',
            self::IsNotEmpty => 'is not empty',
        };
    }

    /**
     * How many value inputs this operator needs, and of what shape.
     */
    public function valueMode(): FilterValueMode
    {
        return match ($this) {
            self::IsEmpty, self::IsNotEmpty, self::IsTrue, self::IsFalse => FilterValueMode::None,
            self::Between => FilterValueMode::Pair,
            self::In, self::NotIn => FilterValueMode::Multiple,
            default => FilterValueMode::Single,
        };
    }

    public function needsValue(): bool
    {
        return $this->valueMode() !== FilterValueMode::None;
    }

    /**
     * @return array<string, string>
     */
    public static function optionsFor(FilterFieldType $type): array
    {
        $options = [];

        foreach ($type->operators() as $operator) {
            $options[$operator->value] = $operator->label();
        }

        return $options;
    }
}
