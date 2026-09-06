<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Actions\ChangeLeadStatusAction;
use App\Domain\Leads\Actions\ConvertLeadAction;
use App\Domain\Leads\DTOs\LeadConversionData;
use App\Domain\Leads\DTOs\LeadConversionResult;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Shared\UI\ChipPalette;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

function converter(): User
{
    return User::factory()->create();
}

function convertLead(Lead $lead, ?LeadConversionData $data = null, ?User $actor = null): LeadConversionResult
{
    $actor ??= $lead->owner ?? converter();

    return app(ConvertLeadAction::class)($lead, $data ?? new LeadConversionData, $actor);
}

/**
 * A lead with everything conversion carries across.
 */
function convertibleLead(?User $owner = null): Lead
{
    return Lead::factory()
        ->ownedBy($owner ?? converter())
        ->status(LeadStatus::Qualified)
        ->named('Dara', 'Okafor')
        ->create([
            'company_name' => 'Acme Industries',
            'job_title' => 'Head of Operations',
            'email' => 'dara@acme.test',
            'phone' => '+44 117 000 0000',
            'mobile' => '+44 7700 900000',
            'website' => 'https://acme.test',
            'address_line_1' => '1 Acme Way',
            'city' => 'Bristol',
            'postal_code' => 'BS1 1AA',
            'country' => 'United Kingdom',
            'estimated_value' => '45000.00',
        ]);
}

// -- The deal stage enum ---------------------------------------------------------

test('every stage has a label, a palette colour and a probability', function (DealStage $stage) {
    expect($stage->label())->not->toBeEmpty()
        ->and(ChipPalette::has($stage->color()))->toBeTrue()
        ->and($stage->probability())->toBeGreaterThanOrEqual(0)
        ->and($stage->probability())->toBeLessThanOrEqual(100)
        ->and(DealStage::options())->toHaveKey($stage->value);
})->with(DealStage::cases());

test('only won and lost are closed, and the pipeline lists them all', function () {
    expect(array_filter(DealStage::cases(), fn (DealStage $s) => $s->isClosed()))
        ->toEqualCanonicalizing([DealStage::Won, DealStage::Lost])
        ->and(DealStage::pipeline())->toEqualCanonicalizing(DealStage::cases());
});

test('a deal is worth its value discounted by how likely the stage is', function () {
    $deal = Deal::factory()->stage(DealStage::Proposal)->worth('40000.00')->create();

    expect($deal->weightedValue())->toBe(20000.0)
        ->and($deal->stage()->probability())->toBe(50);
});

// -- Conversion creates all three ------------------------------------------------

test('converting a lead creates an account, a contact and a deal', function () {
    $lead = convertibleLead();

    $result = convertLead($lead);

    expect($result->justConverted)->toBeTrue()
        ->and($result->account)->toBeInstanceOf(Account::class)
        ->and($result->contact)->toBeInstanceOf(Contact::class)
        ->and($result->deal)->toBeInstanceOf(Deal::class);

    expect(Account::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1)
        ->and(Deal::query()->count())->toBe(1);
});

test('the account is built from what the lead knew about the organisation', function () {
    $lead = convertibleLead();

    $account = convertLead($lead)->account;

    expect($account->name)->toBe('Acme Industries')
        ->and($account->website)->toBe('https://acme.test')
        ->and($account->email)->toBe('dara@acme.test')
        ->and($account->phone)->toBe('+44 117 000 0000')
        ->and($account->address_line_1)->toBe('1 Acme Way')
        ->and($account->city)->toBe('Bristol')
        ->and($account->postal_code)->toBe('BS1 1AA')
        ->and($account->owner_id)->toBe($lead->owner_id);
});

test('the contact is built from what the lead knew about the person', function () {
    $lead = convertibleLead();

    $result = convertLead($lead);

    expect($result->contact->first_name)->toBe('Dara')
        ->and($result->contact->last_name)->toBe('Okafor')
        ->and($result->contact->job_title)->toBe('Head of Operations')
        ->and($result->contact->email)->toBe('dara@acme.test')
        ->and($result->contact->mobile)->toBe('+44 7700 900000')
        ->and($result->contact->account_id)->toBe($result->account->id)
        // First person at a new account, so CreateContactAction makes them primary.
        ->and($result->contact->is_primary)->toBeTrue();
});

test('the deal carries the estimated value and points back at where it came from', function () {
    $lead = convertibleLead();

    $result = convertLead($lead);

    expect((float) $result->deal->value)->toBe(45000.0)
        ->and($result->deal->account_id)->toBe($result->account->id)
        ->and($result->deal->contact_id)->toBe($result->contact->id)
        ->and($result->deal->lead_id)->toBe($lead->id)
        ->and($result->deal->stage())->toBe(DealStage::New)
        ->and($result->deal->name)->toBe('Acme Industries opportunity')
        ->and($result->deal->owner_id)->toBe($lead->owner_id);
});

test('the lead is closed and knows what it became', function () {
    $lead = convertibleLead();

    $result = convertLead($lead);
    $lead->refresh();

    expect($lead->status())->toBe(LeadStatus::Converted)
        ->and($lead->isConverted())->toBeTrue()
        ->and($lead->converted_at)->not->toBeNull()
        ->and($lead->converted_account_id)->toBe($result->account->id)
        ->and($lead->converted_contact_id)->toBe($result->contact->id)
        ->and($lead->converted_deal_id)->toBe($result->deal->id)
        ->and($lead->convertedAccount->id)->toBe($result->account->id)
        ->and($lead->convertedContact->id)->toBe($result->contact->id)
        ->and($lead->convertedDeal->id)->toBe($result->deal->id);
});

test('a lead with no company still lands somewhere, named after the person', function () {
    $lead = Lead::factory()->named('Dara', 'Okafor')->create(['company_name' => null]);

    expect(convertLead($lead)->account->name)->toBe('Dara Okafor');
});

test('the deal is optional, and the other two are still created', function () {
    $lead = convertibleLead();

    $result = convertLead($lead, new LeadConversionData(createDeal: false));

    expect($result->deal)->toBeNull()
        ->and(Deal::query()->count())->toBe(0)
        ->and($lead->fresh()->converted_deal_id)->toBeNull()
        ->and($lead->fresh()->status())->toBe(LeadStatus::Converted)
        ->and(Account::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1);
});

test('the chosen owner takes all three records', function () {
    $lead = convertibleLead();
    $newOwner = converter();

    $result = convertLead($lead, new LeadConversionData(ownerId: $newOwner->id));

    expect($result->account->owner_id)->toBe($newOwner->id)
        ->and($result->contact->owner_id)->toBe($newOwner->id)
        ->and($result->deal->owner_id)->toBe($newOwner->id);
});

test('the deal can be named, valued and dated by the operator', function () {
    $lead = convertibleLead();

    $result = convertLead($lead, new LeadConversionData(
        dealName: 'Acme rollout, phase one',
        dealValue: '125000',
        dealCloseDate: '2027-03-31',
    ));

    expect($result->deal->name)->toBe('Acme rollout, phase one')
        ->and((float) $result->deal->value)->toBe(125000.0)
        ->and($result->deal->expected_close_date?->format('Y-m-d'))->toBe('2027-03-31');
});

// -- Linking to what already exists -----------------------------------------------

test('a lead can join an account that already exists rather than starting a second', function () {
    $existing = Account::factory()->create(['name' => 'Acme Industries Ltd']);
    $lead = convertibleLead();

    $result = convertLead($lead, new LeadConversionData(accountId: $existing->id));

    expect($result->account->id)->toBe($existing->id)
        ->and(Account::query()->count())->toBe(1)
        ->and($result->contact->account_id)->toBe($existing->id)
        ->and($result->deal->account_id)->toBe($existing->id);
});

test('a lead can be linked to somebody already on file', function () {
    $existing = Contact::factory()->create(['first_name' => 'Dara', 'last_name' => 'Okafor', 'account_id' => null]);
    $lead = convertibleLead();

    $result = convertLead($lead, new LeadConversionData(contactId: $existing->id));

    expect($result->contact->id)->toBe($existing->id)
        ->and(Contact::query()->count())->toBe(1)
        // Somebody with no account joins the one being converted into.
        ->and($result->contact->account_id)->toBe($result->account->id);
});

test('a contact already at another account keeps it', function () {
    $elsewhere = Account::factory()->create(['name' => 'Somewhere Else']);
    $existing = Contact::factory()->create(['account_id' => $elsewhere->id]);
    $lead = convertibleLead();

    $result = convertLead($lead, new LeadConversionData(contactId: $existing->id));

    expect($result->contact->account_id)->toBe($elsewhere->id);
});

test('linking to a record that is not there is refused', function (string $field, int $id) {
    $lead = convertibleLead();

    $data = $field === 'account'
        ? new LeadConversionData(accountId: $id)
        : new LeadConversionData(contactId: $id);

    expect(fn () => convertLead($lead, $data))->toThrow(RuntimeException::class, 'does not exist');

    expect($lead->fresh()->isConverted())->toBeFalse();
})->with([
    'account' => ['account', 999999],
    'contact' => ['contact', 999999],
]);

// -- Idempotent ---------------------------------------------------------------------

test('converting twice does not create a second set of records', function () {
    $lead = convertibleLead();

    $first = convertLead($lead);
    $second = convertLead($lead->fresh());

    expect($second->justConverted)->toBeFalse()
        ->and($second->account->id)->toBe($first->account->id)
        ->and($second->contact->id)->toBe($first->contact->id)
        ->and($second->deal->id)->toBe($first->deal->id);

    expect(Account::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1)
        ->and(Deal::query()->count())->toBe(1);
});

test('a second conversion does not move the timestamp', function () {
    $lead = convertibleLead();

    convertLead($lead);
    $convertedAt = $lead->fresh()->converted_at;

    convertLead($lead->fresh());

    expect($lead->fresh()->converted_at?->toDateTimeString())->toBe($convertedAt?->toDateTimeString());
});

test('a second conversion ignores different choices rather than acting on them', function () {
    $lead = convertibleLead();
    $first = convertLead($lead);
    $other = Account::factory()->create(['name' => 'Somewhere Else']);

    $second = convertLead($lead->fresh(), new LeadConversionData(accountId: $other->id));

    expect($second->account->id)->toBe($first->account->id);
});

// -- Refused --------------------------------------------------------------------------

test('an unqualified lead cannot be converted', function () {
    $lead = Lead::factory()->status(LeadStatus::Unqualified)->create();

    expect(fn () => convertLead($lead))
        ->toThrow(RuntimeException::class, 'unqualified lead cannot be converted');

    expect(Account::query()->count())->toBe(0);
});

test('a lead can be converted from any status still in play', function (LeadStatus $status) {
    $lead = Lead::factory()->status($status)->create(['company_name' => 'Acme']);

    convertLead($lead);

    expect($lead->fresh()->status())->toBe(LeadStatus::Converted);
})->with([
    'new' => [LeadStatus::New],
    'contacted' => [LeadStatus::Contacted],
    'nurturing' => [LeadStatus::Nurturing],
    'qualified' => [LeadStatus::Qualified],
]);

test('conversion is the only thing that sets converted, and it does not go through the transition rules', function () {
    // Converted is deliberately absent from every allowedTransitions() list, so
    // a normal move can never reach it however the lead is worked.
    foreach (LeadStatus::cases() as $status) {
        expect($status->allowedTransitions())->not->toContain(LeadStatus::Converted);
    }

    $lead = convertibleLead();
    convertLead($lead);

    expect($lead->fresh()->status())->toBe(LeadStatus::Converted);
});

// -- Rolls back on failure ---------------------------------------------------------------

test('a conversion that fails part way through leaves nothing behind', function () {
    $lead = convertibleLead();

    // Closing the lead is the last step inside the transaction, so failing it
    // proves the account, the contact and the deal created before it all go.
    app()->bind(ChangeLeadStatusAction::class, fn () => new class extends ChangeLeadStatusAction
    {
        public function __construct() {}

        public function force(Lead $lead, LeadStatus $target): Lead
        {
            throw new RuntimeException('the wheels came off');
        }
    });

    expect(fn () => convertLead($lead))->toThrow(RuntimeException::class, 'the wheels came off');

    expect(Account::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0)
        ->and(Deal::query()->count())->toBe(0)
        ->and($lead->fresh()->isConverted())->toBeFalse()
        ->and($lead->fresh()->status())->toBe(LeadStatus::Qualified)
        ->and($lead->fresh()->converted_at)->toBeNull();
});

test('a typed accessor works on a record created without that column', function (string $class, string $method, array $attributes) {
    // A method sharing its name with a column is fine until the instance has no
    // such attribute loaded: Laravel then takes the property read for a relation
    // and calls the method again. Model::create() leaves out whatever it was not
    // given, which is exactly what conversion does.
    $model = new $class($attributes);

    expect(fn () => $model->{$method}())->not->toThrow(Throwable::class);
})->with([
    'account industry' => [Account::class, 'industry', ['name' => 'Acme']],
    'account size' => [Account::class, 'size', ['name' => 'Acme']],
    'contact department' => [Contact::class, 'department', ['first_name' => 'Dana', 'last_name' => 'Scully']],
    'lead status' => [Lead::class, 'status', ['first_name' => 'Dara', 'last_name' => 'Okafor']],
    'lead source' => [Lead::class, 'source', ['first_name' => 'Dara', 'last_name' => 'Okafor']],
    'deal stage' => [Deal::class, 'stage', ['name' => 'An opportunity']],
]);

test('a column sharing a name with a method has a default, so reading it is safe', function (string $class) {
    // Laravel decides whether a property read is a relation by looking for a
    // method of that name. Without a declared default the key can be missing on
    // a freshly created instance, and then reading it calls the method — which
    // is how conversion first broke Account::industry.
    $model = new $class;
    $columns = Schema::getColumnListing($model->getTable());
    $defaults = (new ReflectionClass($class))->getDefaultProperties()['attributes'] ?? [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->class !== $class || $method->getNumberOfParameters() > 0) {
            continue;
        }

        if (! in_array($method->name, $columns, true)) {
            continue;
        }

        expect(array_key_exists($method->name, $defaults))->toBeTrue(
            $class.'::'.$method->name.'() shares its name with a column; add it to $attributes'
        );

        // And the read itself works on an instance that was never given one.
        expect(fn () => $model->{$method->name})->not->toThrow(Throwable::class);
    }

    expect(true)->toBeTrue();
})->with([
    Account::class,
    Contact::class,
    Lead::class,
    Deal::class,
    LeadScoringRule::class,
]);

test('a lead whose converted records were later removed is not silently reconverted', function () {
    $lead = convertibleLead();
    $result = convertLead($lead);

    // nullOnDelete leaves the lead stamped as converted with nothing to show.
    $result->account->forceDelete();

    expect(app(ConvertLeadAction::class)->alreadyConverted($lead->fresh()))->toBeNull()
        ->and($lead->fresh()->status())->toBe(LeadStatus::Converted);
});

// -- On the trail ---------------------------------------------------------------------

test('the three new records and the lead move are all on the audit trail', function () {
    $actor = converter();
    $this->actingAs($actor);

    $lead = convertibleLead($actor);
    $result = convertLead($lead, actor: $actor);

    $subjects = Activity::query()->pluck('subject_type')->unique()->values();

    expect($subjects)->toContain(Account::class)
        ->and($subjects)->toContain(Contact::class)
        ->and($subjects)->toContain(Deal::class);

    $leadEntry = Activity::query()
        ->where('subject_type', Lead::class)
        ->where('subject_id', $lead->id)
        ->latest('id')
        ->first();

    expect($leadEntry?->properties['attributes']['status'] ?? null)->toBe(LeadStatus::Converted->value);
});

test('a deal only logs the attributes it is allowed to', function () {
    $method = new ReflectionMethod(Deal::class, 'activityAttributes');

    expect($method->invoke(new Deal))->toEqualCanonicalizing([
        'name', 'account_id', 'contact_id', 'value', 'expected_close_date', 'stage', 'owner_id',
    ]);
});
