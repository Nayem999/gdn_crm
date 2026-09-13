<?php

namespace App\Domain\Timeline;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Support\Models\Ticket;
use App\Domain\Timeline\Concerns\HasTimeline;
use Illuminate\Database\Eloquent\Model;

/**
 * The modules that have a timeline.
 *
 * One Livewire component serves all of them, so the module reaches it as a
 * string. That string is matched here and nowhere else: an unlisted module
 * 404s rather than being turned into a class name, the same rule the merge and
 * import screens follow.
 */
final class TimelineRegistry
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
            'tickets' => Ticket::class,
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
     * The rows a merge has to carry across, in the shape DuplicateSource asks
     * for. Declared here rather than repeated in each module's source, so a
     * fourth strand added to the timeline is picked up by all of them at once.
     *
     * Both carry a `where` on the morph type: a note is addressed by type and
     * id together, and moving on the id alone would drag a contact's notes onto
     * an account that happens to share its id.
     *
     * @return array<int, array{table: string, column: string, where: array<string, string>}>
     */
    public static function inboundRelations(string $morphClass): array
    {
        return [
            ['table' => 'notes', 'column' => 'notable_id', 'where' => ['notable_type' => $morphClass]],
            ['table' => 'documents', 'column' => 'documentable_id', 'where' => ['documentable_type' => $morphClass]],
            // Activities are the timeline's fourth strand but they are *not*
            // listed here: ActivityRelations::inboundRelations() already
            // declares them, and every duplicate source spreads both. Declaring
            // them twice would move the same rows twice on every merge.
        ];
    }

    /**
     * Whether a model actually carries a timeline, for the guard test and for
     * anything resolving a subject dynamically.
     */
    public static function isSubject(Model $record): bool
    {
        return in_array(HasTimeline::class, class_uses_recursive($record), true);
    }
}
