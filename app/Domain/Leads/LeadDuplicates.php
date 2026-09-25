<?php

namespace App\Domain\Leads;

use App\Domain\Activities\ActivityRelations;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Domain\Shared\Duplicates\FormatsMergeValues;
use App\Domain\Shared\Duplicates\MatchRule;
use App\Domain\Timeline\TimelineRegistry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How leads are matched and merged.
 *
 * The same person captured twice is the commonest kind of duplicate here — a
 * web form and a phone call an hour apart — so both contact numbers are matched
 * as well as the email.
 */
class LeadDuplicates implements DuplicateSource
{
    use FormatsMergeValues;

    public function key(): string
    {
        return 'leads';
    }

    public function modelClass(): string
    {
        return Lead::class;
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
            MatchRule::company('company_name'),
            MatchRule::personName(['first_name', 'last_name']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function mergeableFields(): array
    {
        return [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'job_title' => 'Job title',
            'company_name' => 'Company',
            'email' => 'Email',
            'phone' => 'Phone',
            'mobile' => 'Mobile',
            'website' => 'Website',
            'address_line_1' => 'Address line 1',
            'address_line_2' => 'Address line 2',
            'city' => 'City',
            'state' => 'State or region',
            'postal_code' => 'Postal code',
            'country' => 'Country',
            'source' => 'Source',
            'estimated_value' => 'Estimated value',
            'description' => 'Notes',
            'lead_owner_id' => 'Lead owner',
        ];
    }

    /**
     * Nothing points at a lead yet. Task 2.6's conversion will, and this is
     * where those rows get moved.
     *
     * @return array<int, array{table: string, column: string, where?: array<string, string>}>
     */
    public function inboundRelations(): array
    {
        // A note written on the duplicate is about the same person, so it
        // follows them onto the survivor.
        $morphClass = (new Lead)->getMorphClass();

        return [
            ...TimelineRegistry::inboundRelations($morphClass),
            // A task about a duplicate is about the same customer, so it
            // follows the survivor the way notes and documents do.
            ...ActivityRelations::inboundRelations($morphClass),
            // lead_assignees is deliberately NOT here. The generic move is a
            // bulk UPDATE ... SET lead_id = survivor, and the unique index on
            // (lead_id, user_id) means it throws outright the moment the
            // survivor and the loser share an assignee — which two duplicates
            // of the same prospect very often do. afterMerge() below unions
            // the two sets by hand instead, where a shared assignee is
            // something to notice rather than a constraint violation that
            // aborts the whole merge.
        ];
    }

    /**
     * @return Builder<Lead>
     */
    public function visibleQuery(User $user): Builder
    {
        return Lead::query()->visibleTo($user);
    }

    public function label(Model $record): string
    {
        /** @var Lead $record */
        return $record->fullName();
    }

    public function showRoute(Model $record): string
    {
        return route('leads.show', $record);
    }

    public function displayValue(Model $record, string $field): string
    {
        /** @var Lead $record */
        return match ($field) {
            'estimated_value' => $this->money($record->estimated_value),
            'source' => $record->source()?->label() ?? self::BLANK,
            'lead_owner_id' => $record->leadOwner->name ?? self::BLANK,
            default => $this->blankOr($record->getAttribute($field)),
        };
    }

    public function afterMerge(Model $survivor, Model $loser): void
    {
        // The survivor keeps its own answer field by field; the duplicate fills
        // the gaps. Neither record knows more than it knows, and picking one
        // wholesale would throw away half of what the pair had between them.
        if ($survivor instanceof Lead && $loser instanceof Lead) {
            $survivor->absorbAttributionFrom($loser);
        }

        if ($survivor instanceof Lead && $loser instanceof Lead) {
            // Whoever was working the duplicate is presumably still supposed
            // to be working whichever of the pair survives, so the union of
            // both assignee sets carries over — not a bulk move, because the
            // survivor and the loser sharing an assignee (the commonest case:
            // the same rep captured the same prospect twice) is something to
            // reconcile, not a row for a generic engine to overwrite blind.
            $existing = $survivor->assignees()->pluck('user_id')->all();

            foreach ($loser->assignees as $assignee) {
                if (in_array($assignee->user_id, $existing, true)) {
                    // Present on both: the survivor's own priority stands,
                    // matching how every ordinary mergeable field behaves —
                    // its own answer first, the duplicate filling only a gap.
                    continue;
                }

                LeadAssignee::create([
                    'lead_id' => $survivor->id,
                    'user_id' => $assignee->user_id,
                    'priority' => $assignee->priority,
                    'assigned_at' => $assignee->assigned_at,
                ]);
            }
        }

        // A lead's status is not a mergeable field, so nothing here can have
        // moved it, and there is nothing else to reconcile.
    }
}
