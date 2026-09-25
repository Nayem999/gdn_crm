<?php

use App\Domain\Accounts\AccountDuplicates;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\ContactDuplicates;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\LeadDuplicates;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Duplicates\DuplicateConfidence;
use App\Domain\Shared\Duplicates\DuplicateFinder;
use App\Domain\Shared\Duplicates\DuplicateMatch;
use App\Domain\Shared\Duplicates\DuplicateRegistry;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Domain\Shared\Duplicates\MatchStrategy;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\Models\DuplicateKey;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

function duplicateUser(): User
{
    return User::factory()->create();
}

/**
 * @return array<int, DuplicateMatch>
 */
function duplicatesOf(DuplicateSource $source, $record, ?User $user = null): array
{
    // A lead has no single owner to fall back to any more — several people
    // can be assigned at once — so it reads the same primary assignee
    // Lead::primaryAssignee() and every other "one name" display already do.
    $fallbackOwner = $record instanceof Lead
        ? $record->primaryAssignee()
        : $record->owner;

    return app(DuplicateFinder::class)->for($source, $record, $user ?? $fallbackOwner ?? duplicateUser());
}

function leadSource(): LeadDuplicates
{
    return app(LeadDuplicates::class);
}

// -- Normalisation -------------------------------------------------------------

test('a value is reduced to the fingerprint that makes two records comparable', function (
    MatchStrategy $strategy,
    ?string $value,
    ?string $expected,
) {
    expect($strategy->normalise($value))->toBe($expected);
})->with([
    'email is lower-cased and trimmed' => [MatchStrategy::Email, '  Dara@Acme.TEST ', 'dara@acme.test'],
    'something without an @ is a typo, not an address' => [MatchStrategy::Email, 'dara-at-acme', null],
    'an empty value fingerprints to nothing' => [MatchStrategy::Email, '', null],
    'a null value fingerprints to nothing' => [MatchStrategy::Email, null, null],

    'a phone keeps its last nine digits' => [MatchStrategy::Phone, '+44 117 000 0000', '170000000'],
    'so a trunk zero and a country code agree' => [MatchStrategy::Phone, '0117 000 0000', '170000000'],
    'punctuation is thrown away' => [MatchStrategy::Phone, '(623) 793-4732', '237934732'],
    'and so is the leading one' => [MatchStrategy::Phone, '+1 623 793 4732', '237934732'],
    'an extension is too short to match on' => [MatchStrategy::Phone, '4732', null],

    'a legal suffix says what kind of company, not which' => [MatchStrategy::Company, 'Acme Industries Ltd.', 'acme industries'],
    'the bare name fingerprints the same' => [MatchStrategy::Company, 'Acme Industries', 'acme industries'],
    'stacked suffixes all come off' => [MatchStrategy::Company, 'Acme Trading Co Ltd', 'acme trading'],
    'a leading the carries no information' => [MatchStrategy::Company, 'The Acme Group', 'acme'],
    'punctuation and case are ignored' => [MatchStrategy::Company, 'ACME-INDUSTRIES', 'acme industries'],
    'an initial is not a company' => [MatchStrategy::Company, 'A&B', null],
    'a name that is only a legal form leaves nothing' => [MatchStrategy::Company, 'Holdings Ltd', null],

    'a name is collapsed and lower-cased' => [MatchStrategy::PersonName, '  Dara   OKAFOR ', 'dara okafor'],
]);

test('every strategy has a label and is worth something', function (MatchStrategy $strategy) {
    expect($strategy->label())->not->toBeEmpty()
        ->and($strategy->weight())->toBeGreaterThan(0);
})->with(MatchStrategy::cases());

test('an email is worth more than a company name on its own', function () {
    expect(MatchStrategy::Email->weight())->toBeGreaterThan(MatchStrategy::Phone->weight())
        ->and(MatchStrategy::Phone->weight())->toBeGreaterThan(MatchStrategy::Company->weight())
        ->and(MatchStrategy::Company->weight())->toBeLessThan(DuplicateConfidence::Strong->threshold());
});

// -- The stored fingerprints ---------------------------------------------------

test('a record is fingerprinted as it is saved', function () {
    $lead = Lead::factory()->create([
        'email' => 'Dara@Acme.test',
        'phone' => '+44 117 000 0000',
        'mobile' => null,
        'company_name' => 'Acme Industries Ltd',
        'first_name' => 'Dara',
        'last_name' => 'Okafor',
    ]);

    $keys = DuplicateKey::query()
        ->where('keyable_type', Lead::class)
        ->where('keyable_id', $lead->id)
        ->pluck('value', 'kind');

    expect($keys['email'])->toBe('dara@acme.test')
        ->and($keys['phone'])->toBe('170000000')
        ->and($keys['company'])->toBe('acme industries')
        ->and($keys['person_name'])->toBe('dara okafor');
});

test('editing a matched field moves the fingerprint with it', function () {
    $lead = Lead::factory()->create(['email' => 'old@acme.test']);

    $lead->update(['email' => 'new@acme.test']);

    $values = DuplicateKey::query()->where('keyable_id', $lead->id)->where('kind', 'email')->pluck('value');

    expect($values->all())->toBe(['new@acme.test']);
});

test('re-saving a record does not pile up duplicate fingerprints', function () {
    $lead = Lead::factory()->create(['email' => 'dara@acme.test']);
    $before = DuplicateKey::query()->where('keyable_id', $lead->id)->count();

    $lead->touch();
    $lead->save();

    expect(DuplicateKey::query()->where('keyable_id', $lead->id)->count())->toBe($before);
});

test('two rules that normalise to the same value are stored once', function () {
    $lead = Lead::factory()->create([
        // The same line given twice: one telephone number, not two.
        'phone' => '+44 117 000 0000',
        'mobile' => '0117 000 0000',
    ]);

    expect(DuplicateKey::query()->where('keyable_id', $lead->id)->where('kind', 'phone')->count())->toBe(1);
});

test('a record with nothing worth matching on is not fingerprinted at all', function () {
    $lead = Lead::factory()->create([
        'email' => null,
        'phone' => null,
        'mobile' => null,
        'company_name' => null,
        'first_name' => 'A',
        'last_name' => 'B',
    ]);

    expect(DuplicateKey::query()->where('keyable_id', $lead->id)->count())->toBe(0);
});

test('removing a record takes its fingerprints with it, and restoring brings them back', function () {
    $lead = Lead::factory()->create(['email' => 'dara@acme.test']);

    $lead->delete();

    expect(DuplicateKey::query()->where('keyable_id', $lead->id)->count())->toBe(0);

    $lead->restore();

    expect(DuplicateKey::query()->where('keyable_id', $lead->id)->count())->toBeGreaterThan(0);
});

// -- What counts as a duplicate ------------------------------------------------

test('a shared email is as good as certain', function () {
    $owner = duplicateUser();
    Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test', 'phone' => null, 'company_name' => null, 'first_name' => 'A', 'last_name' => 'One']);
    $lead = Lead::factory()->ownedBy($owner)->create(['email' => 'DARA@ACME.TEST', 'phone' => null, 'company_name' => null, 'first_name' => 'B', 'last_name' => 'Two']);

    $matches = duplicatesOf(leadSource(), $lead, $owner);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->confidence())->toBe(DuplicateConfidence::Exact)
        ->and($matches[0]->summary())->toBe('Email address matches');
});

test('a shared phone number is strong but not certain', function () {
    $owner = duplicateUser();
    Lead::factory()->ownedBy($owner)->create(['email' => 'one@a.test', 'phone' => '+44 117 000 0000', 'mobile' => null, 'company_name' => null, 'first_name' => 'A', 'last_name' => 'One']);
    $lead = Lead::factory()->ownedBy($owner)->create(['email' => 'two@b.test', 'phone' => '0117 000 0000', 'mobile' => null, 'company_name' => null, 'first_name' => 'B', 'last_name' => 'Two']);

    $matches = duplicatesOf(leadSource(), $lead, $owner);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->confidence())->toBe(DuplicateConfidence::Strong)
        ->and($matches[0]->score)->toBe(MatchStrategy::Phone->weight());
});

test('a shared company name alone is only possible', function () {
    $owner = duplicateUser();
    Lead::factory()->ownedBy($owner)->create(['email' => 'one@a.test', 'phone' => null, 'mobile' => null, 'company_name' => 'Acme Industries', 'first_name' => 'A', 'last_name' => 'One']);
    $lead = Lead::factory()->ownedBy($owner)->create(['email' => 'two@b.test', 'phone' => null, 'mobile' => null, 'company_name' => 'Acme Industries Ltd', 'first_name' => 'B', 'last_name' => 'Two']);

    $matches = duplicatesOf(leadSource(), $lead, $owner);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->confidence())->toBe(DuplicateConfidence::Possible);
});

test('matching a phone and a mobile is still one telephone number of evidence', function () {
    $owner = duplicateUser();
    Lead::factory()->ownedBy($owner)->create([
        'email' => 'one@a.test', 'company_name' => null, 'first_name' => 'A', 'last_name' => 'One',
        'phone' => '0117 000 0000', 'mobile' => '0117 000 0000',
    ]);
    $lead = Lead::factory()->ownedBy($owner)->create([
        'email' => 'two@b.test', 'company_name' => null, 'first_name' => 'B', 'last_name' => 'Two',
        'phone' => '+44 117 000 0000', 'mobile' => '+44 117 000 0000',
    ]);

    // Counting phone and mobile separately would score 170 and call a shared
    // switchboard number an exact match.
    expect(duplicatesOf(leadSource(), $lead, $owner)[0]->score)->toBe(MatchStrategy::Phone->weight());
});

test('nothing in common is not a duplicate', function () {
    $owner = duplicateUser();
    Lead::factory()->ownedBy($owner)->create(['email' => 'one@a.test', 'phone' => '0117 111 1111', 'mobile' => null, 'company_name' => 'One Ltd', 'first_name' => 'A', 'last_name' => 'One']);
    $lead = Lead::factory()->ownedBy($owner)->create(['email' => 'two@b.test', 'phone' => '0207 222 2222', 'mobile' => null, 'company_name' => 'Two Ltd', 'first_name' => 'B', 'last_name' => 'Two']);

    expect(duplicatesOf(leadSource(), $lead, $owner))->toBe([]);
});

test('a record never reports itself', function () {
    $owner = duplicateUser();
    $lead = Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test']);

    expect(duplicatesOf(leadSource(), $lead, $owner))->toBe([]);
});

test('the strongest match is listed first', function () {
    $owner = duplicateUser();
    $lead = Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test', 'phone' => '0117 000 0000', 'mobile' => null, 'company_name' => 'Acme Industries', 'first_name' => 'Dara', 'last_name' => 'Okafor']);
    Lead::factory()->ownedBy($owner)->create(['email' => 'other@b.test', 'phone' => null, 'mobile' => null, 'company_name' => 'Acme Industries Ltd', 'first_name' => 'B', 'last_name' => 'Two']);
    $exact = Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test', 'phone' => null, 'mobile' => null, 'company_name' => null, 'first_name' => 'C', 'last_name' => 'Three']);

    $matches = duplicatesOf(leadSource(), $lead, $owner);

    expect($matches)->toHaveCount(2)
        ->and($matches[0]->record->id)->toBe($exact->id)
        ->and($matches[0]->score)->toBeGreaterThan($matches[1]->score);
});

// -- What must never be reported ------------------------------------------------

test('a duplicate outside the viewer access level is never hinted at', function () {
    $role = Role::create(['name' => 'Own only', 'guard_name' => Guard::getDefaultName(User::class)]);
    $role->forceFill(['data_access_level' => DataAccessLevel::Own->value])->save();

    $mine = duplicateUser();
    $mine->assignRole($role);
    $theirs = duplicateUser();

    Lead::factory()->ownedBy($theirs)->create(['email' => 'dara@acme.test', 'first_name' => 'Theirs', 'last_name' => 'Lead']);
    $lead = Lead::factory()->ownedBy($mine)->create(['email' => 'dara@acme.test', 'first_name' => 'Mine', 'last_name' => 'Lead']);

    // The fingerprint matches, so the hint would leak the record's existence.
    expect(DuplicateKey::query()->where('kind', 'email')->where('value', 'dara@acme.test')->count())->toBe(2)
        ->and(duplicatesOf(leadSource(), $lead, $mine->fresh()))->toBe([]);
});

test('a removed record is not offered as a duplicate', function () {
    $owner = duplicateUser();
    $gone = Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test', 'first_name' => 'A', 'last_name' => 'One']);
    $lead = Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test', 'first_name' => 'B', 'last_name' => 'Two']);

    $gone->delete();

    expect(duplicatesOf(leadSource(), $lead, $owner))->toBe([]);
});

test('an already merged record is a resolved duplicate, not an open one', function () {
    $owner = duplicateUser();
    $survivor = Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test', 'first_name' => 'A', 'last_name' => 'One']);
    $merged = Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test', 'first_name' => 'B', 'last_name' => 'Two']);

    // Stamped but not deleted, so only the merged_into_id check can exclude it.
    $merged->forceFill(['merged_into_id' => $survivor->id, 'merged_at' => now()])->save();

    expect(duplicatesOf(leadSource(), $survivor, $owner))->toBe([]);
});

test('a contact sharing an email with a lead is not a lead duplicate', function () {
    $owner = duplicateUser();
    Contact::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test']);
    $lead = Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test']);

    expect(duplicatesOf(leadSource(), $lead, $owner))->toBe([]);
});

// -- Drafts --------------------------------------------------------------------

test('an unsaved record can be matched, which is the point of warning while typing', function () {
    $owner = duplicateUser();
    $existing = Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test']);

    $draft = new Lead(['email' => 'DARA@acme.test']);
    $matches = app(DuplicateFinder::class)->for(leadSource(), $draft, $owner);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->record->id)->toBe($existing->id);
});

test('an edit does not report the record being edited', function () {
    $owner = duplicateUser();
    $lead = Lead::factory()->ownedBy($owner)->create(['email' => 'dara@acme.test']);

    $draft = new Lead(['email' => 'dara@acme.test']);

    expect(app(DuplicateFinder::class)->for(leadSource(), $draft, $owner, $lead->id))->toBe([]);
});

// -- The other two modules -----------------------------------------------------

test('an account matches its own name with and without a legal suffix', function () {
    $owner = duplicateUser();
    Account::factory()->ownedBy($owner)->create(['name' => 'Acme Industries', 'email' => null, 'phone' => null, 'legal_name' => null]);
    $account = Account::factory()->ownedBy($owner)->create(['name' => 'Acme Industries Ltd', 'email' => null, 'phone' => null, 'legal_name' => null]);

    $matches = duplicatesOf(app(AccountDuplicates::class), $account, $owner);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->confidence())->toBe(DuplicateConfidence::Possible);
});

test('a contact matching on both name and email is certain', function () {
    $owner = duplicateUser();
    Contact::factory()->ownedBy($owner)->create(['first_name' => 'Dana', 'last_name' => 'Scully', 'email' => 'dana@fbi.test', 'phone' => null, 'mobile' => null]);
    $contact = Contact::factory()->ownedBy($owner)->create(['first_name' => 'Dana', 'last_name' => 'Scully', 'email' => 'Dana@FBI.test', 'phone' => null, 'mobile' => null]);

    $matches = duplicatesOf(app(ContactDuplicates::class), $contact, $owner);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->confidence())->toBe(DuplicateConfidence::Exact)
        ->and($matches[0]->reasons)->toEqualCanonicalizing(['Email address', 'Name']);
});

// -- The registry --------------------------------------------------------------

test('the registry is the only way a module name becomes a source', function () {
    expect(DuplicateRegistry::keys())->toEqualCanonicalizing(['leads', 'contacts', 'accounts'])
        ->and(DuplicateRegistry::has('leads'))->toBeTrue()
        ->and(DuplicateRegistry::has('users'))->toBeFalse()
        ->and(DuplicateRegistry::find('users'))->toBeNull()
        ->and(DuplicateRegistry::find('leads'))->toBeInstanceOf(LeadDuplicates::class);
});

test('a model resolves to the source that owns it, and an unlisted one to nothing', function () {
    expect(DuplicateRegistry::sourceFor(new Lead)?->key())->toBe('leads')
        ->and(DuplicateRegistry::sourceFor(new Contact)?->key())->toBe('contacts')
        ->and(DuplicateRegistry::sourceFor(new Account)?->key())->toBe('accounts')
        ->and(DuplicateRegistry::sourceFor(new User))->toBeNull();
});

test('every source names its own key, and it matches the registry', function (string $module) {
    expect(DuplicateRegistry::find($module)?->key())->toBe($module);
})->with(DuplicateRegistry::keys());

test('every field a source offers to match or merge is a real column', function (string $module) {
    $source = DuplicateRegistry::find($module);
    $table = (new ($source->modelClass()))->getTable();

    foreach ($source->rules() as $rule) {
        foreach ($rule->fields as $field) {
            expect(Schema::hasColumn($table, $field))->toBeTrue("{$table}.{$field} does not exist");
        }
    }

    foreach (array_keys($source->mergeableFields()) as $field) {
        expect(Schema::hasColumn($table, $field))->toBeTrue("{$table}.{$field} does not exist");
    }
})->with(DuplicateRegistry::keys());

test('every table a merge would move rows in really has that column', function (string $module) {
    $source = DuplicateRegistry::find($module);

    foreach ($source->inboundRelations() as $relation) {
        expect(Schema::hasColumn($relation['table'], $relation['column']))
            ->toBeTrue("{$relation['table']}.{$relation['column']} does not exist");
    }

    expect(true)->toBeTrue();
})->with(DuplicateRegistry::keys());
