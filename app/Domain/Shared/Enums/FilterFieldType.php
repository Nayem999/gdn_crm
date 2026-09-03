<?php

namespace App\Domain\Shared\Enums;

enum FilterFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case Boolean = 'boolean';

    /**
     * The operators offered for this field type, in the order they appear.
     *
     * @return list<FilterOperator>
     */
    public function operators(): array
    {
        $shared = [FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];

        return match ($this) {
            self::Text => [
                FilterOperator::Contains,
                FilterOperator::NotContains,
                FilterOperator::Equals,
                FilterOperator::NotEquals,
                FilterOperator::StartsWith,
                FilterOperator::EndsWith,
                ...$shared,
            ],
            self::Number => [
                FilterOperator::Equals,
                FilterOperator::NotEquals,
                FilterOperator::GreaterThan,
                FilterOperator::LessThan,
                FilterOperator::GreaterThanOrEqual,
                FilterOperator::LessThanOrEqual,
                FilterOperator::Between,
                ...$shared,
            ],
            self::Date => [
                FilterOperator::On,
                FilterOperator::Before,
                FilterOperator::After,
                FilterOperator::Between,
                FilterOperator::LastDays,
                ...$shared,
            ],
            self::Select => [
                FilterOperator::Equals,
                FilterOperator::NotEquals,
                FilterOperator::In,
                FilterOperator::NotIn,
                ...$shared,
            ],
            self::Boolean => [
                FilterOperator::IsTrue,
                FilterOperator::IsFalse,
            ],
        };
    }
}
