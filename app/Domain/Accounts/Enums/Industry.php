<?php

namespace App\Domain\Accounts\Enums;

/**
 * The trade an account is in.
 *
 * A PHP enum stored in a string column, not a MySQL ENUM: adding a case is a
 * code change with no migration, and the column stays readable in any client.
 */
enum Industry: string
{
    case Agriculture = 'agriculture';
    case Construction = 'construction';
    case Consulting = 'consulting';
    case Education = 'education';
    case Energy = 'energy';
    case Finance = 'finance';
    case Government = 'government';
    case Healthcare = 'healthcare';
    case Hospitality = 'hospitality';
    case Legal = 'legal';
    case Logistics = 'logistics';
    case Manufacturing = 'manufacturing';
    case Media = 'media';
    case NonProfit = 'non_profit';
    case RealEstate = 'real_estate';
    case Retail = 'retail';
    case Technology = 'technology';
    case Telecommunications = 'telecommunications';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Agriculture => 'Agriculture',
            self::Construction => 'Construction',
            self::Consulting => 'Consulting',
            self::Education => 'Education',
            self::Energy => 'Energy & utilities',
            self::Finance => 'Financial services',
            self::Government => 'Government',
            self::Healthcare => 'Healthcare',
            self::Hospitality => 'Hospitality & travel',
            self::Legal => 'Legal',
            self::Logistics => 'Transport & logistics',
            self::Manufacturing => 'Manufacturing',
            self::Media => 'Media & entertainment',
            self::NonProfit => 'Non-profit',
            self::RealEstate => 'Real estate',
            self::Retail => 'Retail & e-commerce',
            self::Technology => 'Technology',
            self::Telecommunications => 'Telecommunications',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Finance, self::Legal, self::Consulting => 'emerald',
            self::Technology, self::Telecommunications => 'indigo',
            self::Healthcare, self::NonProfit => 'rose',
            self::Manufacturing, self::Construction, self::Energy => 'amber',
            self::Retail, self::Hospitality, self::Media => 'violet',
            self::Education, self::Government => 'blue',
            self::Agriculture, self::Logistics, self::RealEstate => 'teal',
            self::Other => 'slate',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $industry) {
            $options[$industry->value] = $industry->label();
        }

        // Alphabetical by label, but "Other" belongs at the bottom.
        uasort($options, fn (string $a, string $b) => match (true) {
            $a === 'Other' => 1,
            $b === 'Other' => -1,
            default => strcmp($a, $b),
        });

        return $options;
    }
}
