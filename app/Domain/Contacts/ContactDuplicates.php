<?php

namespace App\Domain\Contacts;

use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\ActivityRelations;
use App\Domain\Contacts\Actions\SetPrimaryContactAction;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Domain\Shared\Duplicates\FormatsMergeValues;
use App\Domain\Shared\Duplicates\MatchRule;
use App\Domain\Timeline\TimelineRegistry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How contacts are matched and merged.
 *
 * There is no company string to match on — a contact's organisation is a
 * relation — so the name carries that weight instead. Two people called the
 * same thing is only a "possible" match on its own, which is the intent.
 */
class ContactDuplicates implements DuplicateSource
{
    use FormatsMergeValues;

    public function __construct(private readonly SetPrimaryContactAction $setPrimary) {}

    public function key(): string
    {
        return 'contacts';
    }

    public function modelClass(): string
    {
        return Contact::class;
    }

    /**
     * @return array<int, MatchRule>
     */
    public function rules(): array
    {
        return [
            MatchRule::email(),
            MatchRule::phone('phone'),
            MatchRule::phone('mobile'),
            MatchRule::personName(['first_name', 'last_name']),
        ];
    }

    /**
     * `is_primary` is deliberately absent: SetPrimaryContactAction owns that
     * flag, and a merge form must not be able to write it directly.
     *
     * @return array<string, string>
     */
    public function mergeableFields(): array
    {
        return [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'job_title' => 'Job title',
            'department' => 'Department',
            'email' => 'Email',
            'phone' => 'Phone',
            'mobile' => 'Mobile',
            'address_line_1' => 'Address line 1',
            'address_line_2' => 'Address line 2',
            'city' => 'City',
            'state' => 'State or region',
            'postal_code' => 'Postal code',
            'country' => 'Country',
            'description' => 'Notes',
            'account_id' => 'Account',
            'owner_id' => 'Owner',
        ];
    }

    /**
     * @return array<int, array{table: string, column: string, where?: array<string, string>}>
     */
    public function inboundRelations(): array
    {
        $morphClass = (new Contact)->getMorphClass();

        return [
            ...TimelineRegistry::inboundRelations($morphClass),
            // A task about a duplicate is about the same customer, so it
            // follows the survivor the way notes and documents do.
            ...ActivityRelations::inboundRelations($morphClass),
        ];
    }

    /**
     * @return Builder<Contact>
     */
    public function visibleQuery(User $user): Builder
    {
        return Contact::query()->visibleTo($user);
    }

    public function label(Model $record): string
    {
        /** @var Contact $record */
        return $record->fullName();
    }

    public function showRoute(Model $record): string
    {
        return route('contacts.show', $record);
    }

    public function displayValue(Model $record, string $field): string
    {
        /** @var Contact $record */
        return match ($field) {
            'owner_id' => $this->ownerName($record->owner_id),
            'account_id' => $record->account_id === null
                ? self::BLANK
                : (Account::query()->whereKey($record->account_id)->value('name') ?? self::BLANK),
            default => $this->blankOr($record->getAttribute($field)),
        };
    }

    /**
     * Merging must not lose an account its primary contact.
     *
     * If the merged-away contact held the flag and the survivor is at the same
     * account, the survivor takes it — through the action that owns the flag,
     * so the "one primary per account" rule still holds.
     */
    public function afterMerge(Model $survivor, Model $loser): void
    {
        // The survivor keeps its own answer field by field; the duplicate fills
        // the gaps. Neither record knows more than it knows, and picking one
        // wholesale would throw away half of what the pair had between them.
        if ($survivor instanceof Contact && $loser instanceof Contact) {
            $survivor->absorbAttributionFrom($loser);
        }

        /** @var Contact $survivor */
        /** @var Contact $loser */
        if (! $loser->is_primary || $survivor->is_primary) {
            return;
        }

        if ($survivor->account_id === null || $survivor->account_id !== $loser->account_id) {
            return;
        }

        $this->setPrimary->promote($survivor);
    }
}
