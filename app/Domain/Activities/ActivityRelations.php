<?php

namespace App\Domain\Activities;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What an activity may be about.
 *
 * The form and the list carry a **module key**, never a class name, and it is
 * matched here and nowhere else — the same rule the import, merge and timeline
 * screens follow. A payload naming `related_type` directly would be a payload
 * naming a class.
 */
final class ActivityRelations
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

    /**
     * @return class-string<Model>|null
     */
    public static function modelClass(string $module): ?string
    {
        return self::subjects()[$module] ?? null;
    }

    /**
     * The morph value stored in `related_type` for a module, or null when the
     * module is not one of ours.
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
     * That module's records, scoped to what this person may see, searched and
     * ordered the way its own picker orders them.
     *
     * A match rather than `$class::query()`: both the access-level scope and
     * `search` are model scopes, and a query typed only as Builder<Model>
     * cannot be shown to carry either. One match rather than two because every
     * module's `search` scope returns the query untouched for an empty term, so
     * this doubles as the plain visible query. `ActivityRelationsTest` walks
     * keys() and fails if a module in subjects() has no branch here, which is
     * what stops the two drifting apart.
     *
     * @return Builder<covariant Model>|null
     */
    public static function visibleQuery(string $module, User $user, string $term = ''): ?Builder
    {
        return match ($module) {
            'leads' => Lead::query()->visibleTo($user)->search($term)->orderBy('last_name'),
            'contacts' => Contact::query()->visibleTo($user)->search($term)->orderBy('last_name'),
            'accounts' => Account::query()->visibleTo($user)->search($term)->orderBy('name'),
            'deals' => Deal::query()->visibleTo($user)->search($term)->orderBy('name'),
            default => null,
        };
    }

    /**
     * The record a module key and id name, but only if this person may see it.
     *
     * Every path that stores `related_id` goes through here: `exists` proves a
     * record is real, never that the person choosing it may reach it.
     */
    public static function resolve(string $module, int $id, User $user): ?Model
    {
        return self::visibleQuery($module, $user)?->whereKey($id)->first();
    }

    /**
     * The subtitle line a picker shows under a record's name.
     */
    public static function description(Model $record): ?string
    {
        return match (true) {
            $record instanceof Contact => $record->job_title,
            $record instanceof Lead => $record->company_name,
            $record instanceof Account => $record->city,
            $record instanceof Deal => $record->account?->name,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'leads' => 'Lead',
            'contacts' => 'Contact',
            'accounts' => 'Account',
            'deals' => 'Deal',
        ];
    }

    public static function label(Model $record): string
    {
        return match (true) {
            $record instanceof Contact => $record->fullName(),
            $record instanceof Lead => $record->fullName(),
            $record instanceof Account => $record->name,
            $record instanceof Deal => $record->name,
            default => 'Record #'.$record->getKey(),
        };
    }

    public static function typeLabel(Model $record): string
    {
        $key = self::keyFor($record);

        return $key === null ? 'Record' : (self::options()[$key] ?? 'Record');
    }

    /**
     * A link to the record, or null when the module has no show screen.
     */
    public static function showRoute(Model $record): ?string
    {
        $key = self::keyFor($record);

        return $key === null ? null : route($key.'.show', $record->getKey());
    }

    /**
     * The rows a merge has to carry across, in the shape DuplicateSource asks
     * for. A task about a duplicate contact is about the same person, so it
     * follows the survivor.
     *
     * The `where` on the morph type is load-bearing: a polymorphic table is
     * addressed by type *and* id, and moving on the id alone would drag a
     * contact's activities onto an account that happens to share its id.
     *
     * @return array<int, array{table: string, column: string, where: array<string, string>}>
     */
    public static function inboundRelations(string $morphClass): array
    {
        return [
            ['table' => 'activities', 'column' => 'related_id', 'where' => ['related_type' => $morphClass]],
        ];
    }
}
