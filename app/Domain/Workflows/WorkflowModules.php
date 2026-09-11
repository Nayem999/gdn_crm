<?php

namespace App\Domain\Workflows;

use App\Domain\Accounts\AccountFields;
use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\ActivityFields;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\ContactFields;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\CustomModules\CustomModuleFields;
use App\Domain\CustomModules\CustomModuleRegistry;
use App\Domain\CustomModules\Models\CustomRecord;
use App\Domain\Deals\DealFields;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\FilterFieldType;
use App\Domain\Shared\Filters\FilterField;
use Illuminate\Database\Eloquent\Model;

/**
 * The modules a workflow can watch.
 *
 * A module reaches this code as a **key** — from a form, a stored definition, a
 * queued job — and is matched here and nowhere else. A `module` column that
 * could name a class would be a stored row naming a class to instantiate, which
 * is the whole reason the registries in this codebase exist.
 *
 * The field set comes from the module's own `{Module}Fields::filters()`, which
 * is already what its list screen filters on. That is what makes a workflow
 * condition and a filter chip mean the same thing: a workflow saying "status is
 * Qualified" is evaluated by the same applier that draws the chip.
 */
final class WorkflowModules
{
    /**
     * @return array<string, array{label: string, model: class-string<Model>}>
     */
    public static function builtIn(): array
    {
        return [
            'leads' => ['label' => 'Leads', 'model' => Lead::class],
            'contacts' => ['label' => 'Contacts', 'model' => Contact::class],
            'accounts' => ['label' => 'Accounts', 'model' => Account::class],
            'deals' => ['label' => 'Deals', 'model' => Deal::class],
            'activities' => ['label' => 'Activities', 'model' => Activity::class],
        ];
    }

    /**
     * Every module a workflow may be attached to, generated ones included.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::builtIn() as $key => $module) {
            $options[$key] = $module['label'];
        }

        return [...$options, ...app(CustomModuleRegistry::class)->options()];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::options());
    }

    /**
     * The built-in modules alone, for guard tests that walk the five classes
     * this registry names directly.
     *
     * Separate from `keys()` for the reason `CustomFieldRegistry` keeps the
     * same split: `options()` queries the database, and a Pest dataset closure
     * is resolved before there is one.
     *
     * @return array<int, string>
     */
    public static function builtInKeys(): array
    {
        return array_keys(self::builtIn());
    }

    public static function has(string $module): bool
    {
        return array_key_exists($module, self::builtIn())
            || CustomFieldRegistry::customModule($module) !== null;
    }

    public static function label(string $module): string
    {
        $custom = CustomFieldRegistry::customModule($module);

        if ($custom !== null) {
            return $custom->plural_name;
        }

        return self::builtIn()[$module]['label'] ?? 'Unknown';
    }

    /**
     * @return class-string<Model>|null
     */
    public static function modelClass(string $module): ?string
    {
        // Every generated module is the same class; the discriminator is the
        // row's custom_module_id, not its type.
        return self::builtIn()[$module]['model']
            ?? (CustomFieldRegistry::customModule($module) !== null ? CustomRecord::class : null);
    }

    /**
     * The morph value a run's `subject_type` stores for this module.
     */
    public static function morphClass(string $module): ?string
    {
        $class = self::modelClass($module);

        return $class === null ? null : (new $class)->getMorphClass();
    }

    /**
     * The fields a workflow on this module can build conditions from.
     *
     * A match rather than a `$class::filters()` call: the built-in field
     * classes take no argument and a generated module's takes its module, so
     * there is no common signature to call dynamically — and inventing one
     * would mean resolving a class name from a stored string.
     *
     * @return array<string, FilterField>
     */
    public static function fields(string $module): array
    {
        $custom = CustomFieldRegistry::customModule($module);

        if ($custom !== null) {
            return CustomModuleFields::filters($custom);
        }

        return match ($module) {
            'leads' => LeadFields::filters(),
            'contacts' => ContactFields::filters(),
            'accounts' => AccountFields::filters(),
            'deals' => DealFields::filters(),
            'activities' => ActivityFields::filters(),
            default => [],
        };
    }

    public static function hasField(string $module, string $field): bool
    {
        return array_key_exists($field, self::fields($module));
    }

    /**
     * @return array<string, string>
     */
    public static function fieldOptions(string $module): array
    {
        $options = [];

        foreach (self::fields($module) as $key => $field) {
            $options[$key] = $field->label;
        }

        return $options;
    }

    /**
     * The date fields a "when a date arrives" trigger can count from.
     *
     * @return array<string, string>
     */
    public static function dateFieldOptions(string $module): array
    {
        $options = [];

        foreach (self::fields($module) as $key => $field) {
            if ($field->type === FilterFieldType::Date) {
                $options[$key] = $field->label;
            }
        }

        return $options;
    }

    public static function hasDateField(string $module, string $field): bool
    {
        return array_key_exists($field, self::dateFieldOptions($module));
    }
}
