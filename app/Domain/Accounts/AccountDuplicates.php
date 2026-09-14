<?php

namespace App\Domain\Accounts;

use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\ActivityRelations;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Domain\Shared\Duplicates\FormatsMergeValues;
use App\Domain\Shared\Duplicates\MatchRule;
use App\Domain\Timeline\TimelineRegistry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How accounts are matched and merged.
 *
 * Both names are matched: "Acme Industries" and "Acme Industries Ltd"
 * fingerprint the same once the legal suffix is dropped, which is exactly the
 * duplicate an import creates.
 */
class AccountDuplicates implements DuplicateSource
{
    use FormatsMergeValues;

    public function key(): string
    {
        return 'accounts';
    }

    public function modelClass(): string
    {
        return Account::class;
    }

    /**
     * @return array<int, MatchRule>
     */
    public function rules(): array
    {
        return [
            MatchRule::email(),
            MatchRule::phone('phone'),
            MatchRule::company('name', 'Account name'),
            MatchRule::company('legal_name', 'Legal name'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function mergeableFields(): array
    {
        return [
            'name' => 'Account name',
            'legal_name' => 'Legal name',
            'industry' => 'Industry',
            'size' => 'Size',
            'annual_revenue' => 'Annual revenue',
            'website' => 'Website',
            'email' => 'Email',
            'phone' => 'Phone',
            'address_line_1' => 'Address line 1',
            'address_line_2' => 'Address line 2',
            'city' => 'City',
            'state' => 'State or region',
            'postal_code' => 'Postal code',
            'country' => 'Country',
            'description' => 'Notes',
            'parent_id' => 'Parent account',
            'owner_id' => 'Owner',
        ];
    }

    /**
     * The survivor inherits the loser's people and its subsidiaries.
     *
     * @return array<int, array{table: string, column: string, where?: array<string, string>}>
     */
    public function inboundRelations(): array
    {
        $morphClass = (new Account)->getMorphClass();

        return [
            ['table' => 'contacts', 'column' => 'account_id'],
            ['table' => 'accounts', 'column' => 'parent_id'],
            ...TimelineRegistry::inboundRelations($morphClass),
            // A task about a duplicate is about the same customer, so it
            // follows the survivor the way notes and documents do.
            ...ActivityRelations::inboundRelations($morphClass),
        ];
    }

    /**
     * @return Builder<Account>
     */
    public function visibleQuery(User $user): Builder
    {
        return Account::query()->visibleTo($user);
    }

    public function label(Model $record): string
    {
        /** @var Account $record */
        return $record->name;
    }

    public function showRoute(Model $record): string
    {
        return route('accounts.show', $record);
    }

    public function displayValue(Model $record, string $field): string
    {
        /** @var Account $record */
        return match ($field) {
            'owner_id' => $this->ownerName($record->owner_id),
            'annual_revenue' => $this->money($record->annual_revenue),
            'industry' => $record->industry()?->label() ?? self::BLANK,
            'size' => $record->size()?->label() ?? self::BLANK,
            'parent_id' => $record->parent->name ?? self::BLANK,
            default => $this->blankOr($record->getAttribute($field)),
        };
    }

    /**
     * Moving parent_id rows wholesale can put a loop in the hierarchy, and a
     * loop hangs every ancestor walk.
     *
     * Two ways it happens: the survivor's own parent was the loser, so the
     * re-point makes it its own parent; or the survivor was a descendant of one
     * of the loser's subsidiaries, which now reports to the survivor. Either
     * way the offending link is cut rather than guessed at — a subsidiary at
     * the top level is visibly wrong and easily fixed, a loop is neither.
     */
    public function afterMerge(Model $survivor, Model $loser): void
    {
        // The survivor keeps its own answer field by field; the duplicate fills
        // the gaps. Neither record knows more than it knows, and picking one
        // wholesale would throw away half of what the pair had between them.
        if ($survivor instanceof Account && $loser instanceof Account) {
            $survivor->absorbAttributionFrom($loser);
        }

        /** @var Account $survivor */
        if (! $survivor->canBeParentedBy($survivor->parent)) {
            $survivor->forceFill(['parent_id' => null])->save();
            $survivor->refresh();
        }

        foreach ($survivor->children as $child) {
            if (! $child->canBeParentedBy($survivor)) {
                $child->forceFill(['parent_id' => null])->save();
            }
        }
    }
}
