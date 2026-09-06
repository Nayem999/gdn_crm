<?php

use App\Domain\Accounts\AccountDuplicates;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\ContactDuplicates;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\LeadDuplicates;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Actions\MergeRecordsAction;
use App\Domain\Shared\Models\DuplicateKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

function mergeAction(): MergeRecordsAction
{
    return app(MergeRecordsAction::class);
}

/**
 * @param  array<string, mixed>  $chosen
 */
function mergeLeads(Lead $survivor, Lead $loser, array $chosen = []): Lead
{
    /** @var Lead $merged */
    $merged = mergeAction()(app(LeadDuplicates::class), $survivor, $loser, $chosen);

    return $merged;
}

// -- What the survivor ends up with --------------------------------------------

test('the survivor takes the values that were chosen and keeps the rest', function () {
    $survivor = Lead::factory()->create(['job_title' => null, 'city' => 'Bristol', 'company_name' => 'Acme']);
    $loser = Lead::factory()->create(['job_title' => 'Head of Operations', 'city' => 'Leeds', 'company_name' => 'Acme Ltd']);

    mergeLeads($survivor, $loser, ['job_title' => 'Head of Operations']);

    expect($survivor->fresh()->job_title)->toBe('Head of Operations')
        ->and($survivor->fresh()->city)->toBe('Bristol')
        ->and($survivor->fresh()->company_name)->toBe('Acme');
});

test('a field the module does not offer cannot be written by a merge', function () {
    $survivor = Lead::factory()->status(LeadStatus::New)->create();
    $loser = Lead::factory()->create();

    // Status is not mergeable: ChangeLeadStatusAction owns it.
    mergeLeads($survivor, $loser, [
        'status' => LeadStatus::Qualified->value,
        'score' => 99,
        'merged_into_id' => 12345,
    ]);

    expect($survivor->fresh()->status)->toBe(LeadStatus::New->value)
        ->and($survivor->fresh()->score)->not->toBe(99)
        ->and($survivor->fresh()->merged_into_id)->toBeNull();
});

test('nothing is written when nothing was chosen', function () {
    $survivor = Lead::factory()->create(['city' => 'Bristol']);
    $loser = Lead::factory()->create(['city' => 'Leeds']);

    mergeLeads($survivor, $loser);

    expect($survivor->fresh()->city)->toBe('Bristol');
});

// -- What happens to the record that lost --------------------------------------

test('the merged record is kept, marked and taken off the list', function () {
    $survivor = Lead::factory()->create();
    $loser = Lead::factory()->create();

    mergeLeads($survivor, $loser);

    $kept = Lead::withTrashed()->find($loser->id);

    expect($kept)->not->toBeNull()
        ->and($kept->trashed())->toBeTrue()
        ->and($kept->merged_into_id)->toBe($survivor->id)
        ->and($kept->merged_at)->not->toBeNull()
        ->and($kept->isMerged())->toBeTrue()
        // Gone from the list, but not gone.
        ->and(Lead::query()->whereKey($loser->id)->exists())->toBeFalse();
});

test('the survivor can still reach what was merged into it', function () {
    $survivor = Lead::factory()->create();
    $loser = Lead::factory()->create();

    mergeLeads($survivor, $loser);

    expect($survivor->mergedRecords()->pluck('id')->all())->toBe([$loser->id])
        ->and(Lead::withTrashed()->find($loser->id)->mergedInto->id)->toBe($survivor->id);
});

test('the merged record stops being a duplicate candidate', function () {
    $survivor = Lead::factory()->create(['email' => 'dara@acme.test']);
    $loser = Lead::factory()->create(['email' => 'dara@acme.test']);

    expect(DuplicateKey::query()->where('keyable_id', $loser->id)->count())->toBeGreaterThan(0);

    mergeLeads($survivor, $loser);

    expect(DuplicateKey::query()->where('keyable_id', $loser->id)->count())->toBe(0);
});

test('a value the survivor took is fingerprinted under the survivor', function () {
    $survivor = Lead::factory()->create(['email' => 'old@acme.test']);
    $loser = Lead::factory()->create(['email' => 'taken@acme.test']);

    mergeLeads($survivor, $loser, ['email' => 'taken@acme.test']);

    $values = DuplicateKey::query()
        ->where('keyable_id', $survivor->id)
        ->where('kind', 'email')
        ->pluck('value');

    expect($values->all())->toBe(['taken@acme.test']);
});

// -- Merge preserves history ----------------------------------------------------

test('the merged record keeps its own history, still pointed at itself', function () {
    $this->actingAs(User::factory()->create());

    $survivor = Lead::factory()->create();
    $loser = Lead::factory()->create(['city' => 'Leeds']);
    $loser->update(['city' => 'Bristol']);

    $before = Activity::query()
        ->where('subject_type', Lead::class)
        ->where('subject_id', $loser->id)
        ->pluck('id');

    expect($before)->not->toBeEmpty();

    mergeLeads($survivor, $loser);

    $after = Activity::query()
        ->where('subject_type', Lead::class)
        ->where('subject_id', $loser->id)
        ->pluck('id');

    // Every earlier entry survives and still names the record the events
    // actually happened to. Re-pointing them at the survivor would rewrite who
    // did what to which record.
    expect($after->intersect($before)->count())->toBe($before->count());
});

test('a merge is on the trail of both records', function () {
    $this->actingAs(User::factory()->create());

    $survivor = Lead::factory()->named('Dara', 'Okafor')->create();
    $loser = Lead::factory()->named('D', 'Okafor')->create();

    mergeLeads($survivor, $loser);

    $onSurvivor = Activity::query()->where('subject_id', $survivor->id)->latest('id')->first();
    $onLoser = Activity::query()->where('subject_id', $loser->id)
        ->where('description', 'like', '%merged into%')->latest('id')->first();

    expect($onSurvivor?->description)->toContain('merged into Dara Okafor')
        ->and($onSurvivor?->properties['merged_from']['id'] ?? null)->toBe($loser->id)
        ->and($onLoser?->properties['merged_into']['id'] ?? null)->toBe($survivor->id);
});

test('the entry names the merged record as it was, not as the survivor became', function () {
    $this->actingAs(User::factory()->create());

    $survivor = Lead::factory()->named('D', 'Okafor')->create();
    $loser = Lead::factory()->named('Dara', 'Okafor')->create();

    // The survivor takes the loser's name, so reading the label afterwards
    // would report both sides as the same person.
    mergeLeads($survivor, $loser, ['first_name' => 'Dara']);

    $entry = Activity::query()->where('subject_id', $survivor->id)
        ->where('description', 'like', '%merged into%')->latest('id')->first();

    expect($entry?->description)->toBe('Dara Okafor was merged into Dara Okafor');
});

// -- What must be refused -------------------------------------------------------

test('a record cannot be merged into itself', function () {
    $lead = Lead::factory()->create();

    expect(fn () => mergeLeads($lead, $lead))
        ->toThrow(RuntimeException::class, 'cannot be merged into itself');
});

test('an already merged record cannot be merged again', function () {
    $survivor = Lead::factory()->create();
    $loser = Lead::factory()->create();
    $third = Lead::factory()->create();

    mergeLeads($survivor, $loser);

    expect(fn () => mergeLeads($third, Lead::withTrashed()->find($loser->id)))
        ->toThrow(RuntimeException::class, 'already been merged');

    expect(fn () => mergeLeads(Lead::withTrashed()->find($loser->id), $third))
        ->toThrow(RuntimeException::class, 'already been merged');
});

test('two different kinds of record cannot be merged', function () {
    $lead = Lead::factory()->create();
    $contact = Contact::factory()->create();

    expect(fn () => mergeAction()(app(LeadDuplicates::class), $lead, $contact))
        ->toThrow(RuntimeException::class, 'not the same kind of thing');
});

test('a merge that fails part way through leaves both records alone', function () {
    $survivor = Account::factory()->create(['name' => 'Acme', 'city' => 'Bristol']);
    $loser = Account::factory()->create(['name' => 'Acme Ltd', 'city' => 'Leeds']);
    $contact = Contact::factory()->create(['account_id' => $loser->id]);

    // A source whose afterMerge throws, standing in for anything that can fail
    // once the rows have already moved.
    $exploding = new class(app(AccountDuplicates::class)) extends AccountDuplicates
    {
        public function __construct(private AccountDuplicates $inner) {}

        public function afterMerge(Model $survivor, Model $loser): void
        {
            throw new RuntimeException('something went wrong');
        }
    };

    expect(fn () => mergeAction()($exploding, $survivor, $loser, ['city' => 'Leeds']))
        ->toThrow(RuntimeException::class, 'something went wrong');

    expect($survivor->fresh()->city)->toBe('Bristol')
        ->and($loser->fresh())->not->toBeNull()
        ->and($loser->fresh()->merged_into_id)->toBeNull()
        ->and($contact->fresh()->account_id)->toBe($loser->id);
});

// -- Accounts: the rows that move -----------------------------------------------

test('the surviving account inherits the other one people and subsidiaries', function () {
    $survivor = Account::factory()->create(['name' => 'Acme']);
    $loser = Account::factory()->create(['name' => 'Acme Ltd']);

    $person = Contact::factory()->create(['account_id' => $loser->id]);
    $subsidiary = Account::factory()->create(['name' => 'Acme Components', 'parent_id' => $loser->id]);

    mergeAction()(app(AccountDuplicates::class), $survivor, $loser);

    expect($person->fresh()->account_id)->toBe($survivor->id)
        ->and($subsidiary->fresh()->parent_id)->toBe($survivor->id);
});

test('an account that reported to the one it absorbed does not become its own parent', function () {
    $parent = Account::factory()->create(['name' => 'Acme Ltd']);
    $survivor = Account::factory()->create(['name' => 'Acme', 'parent_id' => $parent->id]);

    // Re-pointing parent_id rows wholesale would set the survivor's own parent
    // to itself, and a loop hangs every hierarchy walk.
    mergeAction()(app(AccountDuplicates::class), $survivor, $parent);

    expect($survivor->fresh()->parent_id)->toBeNull()
        ->and($survivor->fresh()->ancestors())->toHaveCount(0);
});

test('a subsidiary that would end up above its new parent is cut loose instead', function () {
    $loser = Account::factory()->create(['name' => 'Acme Ltd']);
    $middle = Account::factory()->create(['name' => 'Acme Middle', 'parent_id' => $loser->id]);
    $survivor = Account::factory()->create(['name' => 'Acme', 'parent_id' => $middle->id]);

    // The middle account would now report to the survivor, which reports to the
    // middle account.
    mergeAction()(app(AccountDuplicates::class), $survivor, $loser);

    expect($survivor->fresh()->parent_id)->toBeNull()
        ->and($middle->fresh()->parent_id)->toBe($survivor->id)
        ->and($middle->fresh()->ancestors())->toHaveCount(1);
});

// -- Contacts: the flag that lives in an action ---------------------------------

test('an account does not lose its primary contact to a merge', function () {
    $account = Account::factory()->create();
    $primary = Contact::factory()->primary()->create(['account_id' => $account->id, 'first_name' => 'Dana', 'last_name' => 'Scully']);
    $other = Contact::factory()->create(['account_id' => $account->id, 'first_name' => 'D', 'last_name' => 'Scully']);

    expect($primary->fresh()->is_primary)->toBeTrue()
        ->and($other->fresh()->is_primary)->toBeFalse();

    mergeAction()(app(ContactDuplicates::class), $other, $primary);

    expect($other->fresh()->is_primary)->toBeTrue()
        ->and(Contact::query()->where('account_id', $account->id)->where('is_primary', true)->count())->toBe(1);
});

test('a merge cannot set the primary flag directly', function () {
    $account = Account::factory()->create();
    $primary = Contact::factory()->primary()->create(['account_id' => $account->id]);
    $other = Contact::factory()->create(['account_id' => $account->id]);

    // is_primary is deliberately absent from mergeableFields.
    mergeAction()(app(ContactDuplicates::class), $primary, $other, ['is_primary' => true]);

    expect(Contact::query()->where('account_id', $account->id)->where('is_primary', true)->count())->toBe(1);
});

test('a contact at another account does not take a primary flag with it', function () {
    $here = Account::factory()->create();
    $elsewhere = Account::factory()->create();

    $primaryElsewhere = Contact::factory()->primary()->create(['account_id' => $elsewhere->id]);
    $survivor = Contact::factory()->create(['account_id' => $here->id]);

    mergeAction()(app(ContactDuplicates::class), $survivor, $primaryElsewhere);

    // "Primary" only means anything relative to one account, so a flag from
    // another one must not follow the merge across.
    expect($survivor->fresh()->is_primary)->toBeFalse()
        ->and($survivor->fresh()->account_id)->toBe($here->id);
});
