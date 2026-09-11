<?php

namespace App\Domain\CustomFields;

use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The modules an administrator can add custom fields to.
 *
 * A module reaches this code as a **key** — from a form, a URL, a stored
 * definition row — and is matched here and nowhere else. A `module` column that
 * could name a class would be a request naming a class.
 *
 * Deliberately its own list rather than `ActivityRelations`, which looks almost
 * identical: that registry answers "what can an activity be about", and an
 * activity cannot be about an activity. Activities *can* carry custom fields.
 * `CustomFieldRegistryTest` asserts every class listed here actually uses
 * HasCustomFields, so the two cannot drift apart silently.
 */
final class CustomFieldRegistry
{
    /**
     * @return array<string, class-string<Model>>
     */
    public static function subjects(): array
    {
        return [
            'leads' => Lead::class,
            'contacts' => Contact::class,
            'accounts' => Account::class,
            'deals' => Deal::class,
            'activities' => Activity::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'leads' => 'Leads',
            'contacts' => 'Contacts',
            'accounts' => 'Accounts',
            'deals' => 'Deals',
            'activities' => 'Activities',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::subjects());
    }

    public static function has(string $module): bool
    {
        return array_key_exists($module, self::subjects());
    }

    public static function label(string $module): string
    {
        return self::options()[$module] ?? 'Unknown';
    }

    /**
     * @return class-string<Model>|null
     */
    public static function modelClass(string $module): ?string
    {
        return self::subjects()[$module] ?? null;
    }

    /**
     * The morph value stored in `customizable_type` for a module.
     */
    public static function morphClass(string $module): ?string
    {
        $class = self::modelClass($module);

        return $class === null ? null : (new $class)->getMorphClass();
    }

    /**
     * The module key a record belongs to, matched on the class rather than a
     * name so nothing here can be steered by a request value.
     */
    public static function keyFor(Model $record): ?string
    {
        foreach (self::subjects() as $key => $class) {
            if ($record instanceof $class) {
                return $key;
            }
        }

        return null;
    }

    /**
     * A lookup field's choices: that module's records, scoped to what the
     * person filling the form may see.
     *
     * A match rather than `$class::query()` for the same reason
     * ActivityRelations uses one — the access-level scope is a model scope, and
     * a query typed only as Builder<Model> cannot be shown to carry it.
     * `CustomFieldRegistryTest` walks keys() and fails if a module in
     * subjects() has no branch here.
     *
     * @return Builder<covariant Model>|null
     */
    public static function visibleQuery(string $module, User $user): ?Builder
    {
        return match ($module) {
            'leads' => Lead::query()->visibleTo($user)->orderBy('last_name'),
            'contacts' => Contact::query()->visibleTo($user)->orderBy('last_name'),
            'accounts' => Account::query()->visibleTo($user)->orderBy('name'),
            'deals' => Deal::query()->visibleTo($user)->orderBy('name'),
            'activities' => Activity::query()->visibleTo($user)->orderBy('due_at'),
            default => null,
        };
    }

    /**
     * The record a lookup value points at, but only if this person may see it.
     *
     * Every path that stores or reads a lookup goes through here: `exists`
     * proves a record is real, never that the person choosing it may reach it.
     */
    public static function resolve(string $module, int $id, User $user): ?Model
    {
        return self::visibleQuery($module, $user)?->whereKey($id)->first();
    }

    /**
     * How a looked-up record is written on screen.
     */
    public static function recordLabel(Model $record): string
    {
        return match (true) {
            $record instanceof Contact, $record instanceof Lead => $record->fullName(),
            $record instanceof Account, $record instanceof Deal => $record->name,
            $record instanceof Activity => $record->subject,
            default => 'Record #'.$record->getKey(),
        };
    }

    /**
     * Whether a model actually carries custom fields, for the guard test and
     * for anything resolving a subject dynamically.
     */
    public static function isSubject(Model $record): bool
    {
        return in_array(HasCustomFields::class, class_uses_recursive($record), true);
    }
}
