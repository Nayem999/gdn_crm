<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Capture\CaptureField;
use App\Domain\Leads\Capture\CaptureTimestamp;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadCaptureForm;
use App\Livewire\Leads\LeadCaptureForms;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * A timestamp signed as if the page had been rendered a plausible while ago.
 *
 * The guard measures the gap between rendering and posting, so a test that
 * issues one at the same instant it posts is testing the spam path, not the
 * happy one.
 */
function stampIssuedSecondsAgo(int $seconds = 10): string
{
    $now = Carbon::now();

    Carbon::setTestNow($now->copy()->subSeconds($seconds));
    $stamp = CaptureTimestamp::issue();
    Carbon::setTestNow($now);

    return $stamp;
}

/**
 * A payload a real person would produce.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function capturePayload(array $overrides = []): array
{
    return [
        'first_name' => 'Priya',
        'last_name' => 'Ramanathan',
        'email' => 'priya@example.com',
        LeadCaptureForm::HONEYPOT => '',
        LeadCaptureForm::TIMESTAMP => stampIssuedSecondsAgo(),
        ...$overrides,
    ];
}

/**
 * @param  array<int, string>  $permissions
 */
function captureFormsUser(array $permissions = ['leads.forms']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

beforeEach(function () {
    // The route's throttle counts in the cache, and each test starts from zero.
    Cache::flush();
    Carbon::setTestNow('2026-10-15 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The headline requirement --------------------------------------------------

test('a submission creates a lead', function () {
    $owner = User::factory()->create();
    $form = LeadCaptureForm::factory()->ownedBy($owner)->create();

    $this->post(route('lead-capture.submit', $form->token), capturePayload())
        ->assertOk()
        ->assertSee('Thank you');

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Priya')
        ->and($lead->last_name)->toBe('Ramanathan')
        ->and($lead->email)->toBe('priya@example.com')
        // From the form, never the payload.
        ->and($lead->owner_id)->toBe($owner->id)
        ->and($lead->source())->toBe(LeadSource::WebForm)
        ->and($lead->status())->toBe(LeadStatus::New);

    expect($form->fresh()->submission_count)->toBe(1)
        ->and($form->fresh()->last_submitted_at)->not->toBeNull();
});

test('a filled honeypot is rejected, and looks exactly like success', function () {
    // Telling a bot which signal caught it is telling whoever wrote it what to
    // change.
    $form = LeadCaptureForm::factory()->create();

    $this->post(route('lead-capture.submit', $form->token), capturePayload([
        LeadCaptureForm::HONEYPOT => 'http://spam.example',
    ]))
        ->assertOk()
        ->assertSee('Thank you');

    expect(Lead::query()->count())->toBe(0)
        ->and($form->fresh()->submission_count)->toBe(0);
});

test('a form submitted faster than a person could read it is rejected', function () {
    $form = LeadCaptureForm::factory()->create();

    $this->post(route('lead-capture.submit', $form->token), capturePayload([
        // Issued this instant: the page cannot have been read yet.
        LeadCaptureForm::TIMESTAMP => CaptureTimestamp::issue(),
    ]))
        ->assertOk()
        ->assertSee('Thank you');

    expect(Lead::query()->count())->toBe(0);
});

test('the timing check is signed, so a back-dated timestamp does not pass', function () {
    $form = LeadCaptureForm::factory()->create();

    // A plain integer, of the kind a script would invent.
    $this->post(route('lead-capture.submit', $form->token), capturePayload([
        LeadCaptureForm::TIMESTAMP => (string) now()->subMinutes(5)->getTimestamp(),
    ]))->assertOk();

    expect(Lead::query()->count())->toBe(0);
});

test('a missing timestamp fails safe rather than being waved through', function () {
    $form = LeadCaptureForm::factory()->create();

    $this->post(route('lead-capture.submit', $form->token), capturePayload([
        LeadCaptureForm::TIMESTAMP => '',
    ]))->assertOk();

    expect(Lead::query()->count())->toBe(0);
});

// -- What a public payload may not do -----------------------------------------

test('a payload cannot choose the owner or the source', function () {
    // A submission that could route itself would route itself to somebody who
    // will not look at it.
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $form = LeadCaptureForm::factory()->ownedBy($owner)->create();

    $this->post(route('lead-capture.submit', $form->token), capturePayload([
        'owner_id' => $stranger->id,
        'source' => LeadSource::Partner->value,
        'status' => LeadStatus::Qualified->value,
    ]))->assertOk();

    $lead = Lead::query()->firstOrFail();

    expect($lead->owner_id)->toBe($owner->id)
        ->and($lead->source())->toBe(LeadSource::WebForm)
        ->and($lead->status())->toBe(LeadStatus::New);
});

test('a form that names no source still records one', function () {
    // The builder leaves the source optional, and a lead with no source is
    // invisible to the one report anybody runs about this feature.
    $form = LeadCaptureForm::factory()->create(['source' => null]);

    $this->post(route('lead-capture.submit', $form->token), capturePayload())->assertOk();

    expect(Lead::query()->firstOrFail()->source())->toBe(LeadSource::WebForm);
});

test('a field the form does not show is ignored', function () {
    $form = LeadCaptureForm::factory()->withFields([
        ['key' => 'first_name', 'label' => 'First name', 'required' => true],
        ['key' => 'email', 'label' => 'Email', 'required' => true],
    ])->create();

    $this->post(route('lead-capture.submit', $form->token), capturePayload([
        'company_name' => 'Should be ignored',
    ]))->assertOk();

    expect(Lead::query()->firstOrFail()->company_name)->toBeNull();
});

test('a stored field the catalogue no longer holds is dropped rather than rendered', function () {
    $form = LeadCaptureForm::factory()->withFields([
        ['key' => 'first_name', 'label' => 'First name', 'required' => true],
        ['key' => 'a_field_that_went_away', 'label' => 'Gone', 'required' => true],
    ])->create();

    expect(collect($form->captureFields())->pluck('key')->all())->toBe(['first_name']);
});

// -- Validation ----------------------------------------------------------------

test('a required field left empty is refused, and the visitor is told', function () {
    $form = LeadCaptureForm::factory()->create();

    $this->post(route('lead-capture.submit', $form->token), capturePayload(['email' => '']))
        ->assertSessionHasErrors('email');

    expect(Lead::query()->count())->toBe(0);
});

test('a lead must be reachable somehow, whatever the form asks for', function () {
    $form = LeadCaptureForm::factory()->withFields([
        ['key' => 'first_name', 'label' => 'First name', 'required' => true],
        ['key' => 'email', 'label' => 'Email', 'required' => false],
        ['key' => 'phone', 'label' => 'Phone', 'required' => false],
    ])->create();

    $this->post(route('lead-capture.submit', $form->token), [
        'first_name' => 'Priya',
        LeadCaptureForm::HONEYPOT => '',
        LeadCaptureForm::TIMESTAMP => stampIssuedSecondsAgo(),
    ])->assertSessionHasErrors('email');

    expect(Lead::query()->count())->toBe(0);
});

test('an address that is not one is refused', function () {
    $form = LeadCaptureForm::factory()->create();

    $this->post(route('lead-capture.submit', $form->token), capturePayload(['email' => 'not an address']))
        ->assertSessionHasErrors('email');
});

// -- The public page -----------------------------------------------------------

test('the form renders for anybody, signed in or not', function () {
    $form = LeadCaptureForm::factory()->create(['name' => 'Talk to sales']);

    $this->get(route('lead-capture.show', $form->token))
        ->assertOk()
        ->assertSee('Talk to sales')
        ->assertSee('First name')
        // The honeypot is on the page, hidden and out of the tab order.
        ->assertSee(LeadCaptureForm::HONEYPOT)
        ->assertSee('tabindex="-1"', false)
        // And not indexed: a capture form is for the page that embeds it.
        ->assertSee('noindex', false);
});

test('a token that names nothing is not found', function () {
    $this->get(route('lead-capture.show', 'nothing-here'))->assertNotFound();
});

test('a closed form still resolves, and says so', function () {
    // The URL is out in the world on somebody's website; 404ing at a person who
    // followed it in good faith is worse than telling them.
    $form = LeadCaptureForm::factory()->inactive()->create();

    $this->get(route('lead-capture.show', $form->token))
        ->assertOk()
        ->assertSee('no longer accepting submissions');
});

test('a closed form refuses a submission and says why', function () {
    $form = LeadCaptureForm::factory()->inactive()->create();

    $this->post(route('lead-capture.submit', $form->token), capturePayload())
        ->assertSessionHasErrors('form');

    expect(Lead::query()->count())->toBe(0);
});

test('a form with a redirect sends the visitor there', function () {
    $form = LeadCaptureForm::factory()->redirectingTo('https://example.com/thanks')->create();

    $this->post(route('lead-capture.submit', $form->token), capturePayload())
        ->assertRedirect('https://example.com/thanks');

    expect(Lead::query()->count())->toBe(1);
});

test('the public route is rate limited', function () {
    $form = LeadCaptureForm::factory()->create();

    // The throttle answers before any of the controller runs, so these need not
    // be plausible submissions — they only need to arrive.
    for ($i = 0; $i < 10; $i++) {
        $this->post(route('lead-capture.submit', $form->token), capturePayload([
            LeadCaptureForm::TIMESTAMP => '',
        ]));
    }

    $this->post(route('lead-capture.submit', $form->token), capturePayload())
        ->assertStatus(429);

    expect(Lead::query()->count())->toBe(0);
});

test('csrf is waived for the public form path and the provider webhooks, and nothing else', function () {
    // An embedded form has no usable session, so it cannot carry a token, and
    // neither can a provider posting a bounce. Both stand on an unguessable
    // path instead. This list is the whole of it; a third entry should have to
    // argue for itself here.
    $excluded = (new ReflectionMethod(ValidateCsrfToken::class, 'getExcludedPaths'));
    $excluded->setAccessible(true);

    expect($excluded->invoke(app(ValidateCsrfToken::class)))->toBe(['f/*', 'webhooks/*']);
});

// -- The builder ----------------------------------------------------------------

test('the builder needs its own permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.lead-forms'))
        ->assertForbidden();

    $this->actingAs(captureFormsUser())
        ->get(route('settings.lead-forms'))
        ->assertOk();
});

test('the permission is declared in the catalogue', function () {
    expect(PermissionCatalogue::has('leads.forms'))->toBeTrue();
});

test('a new form gets a random token, not a slug of its name', function () {
    $user = captureFormsUser();

    Livewire::actingAs($user)
        ->test(LeadCaptureForms::class)
        ->call('add')
        ->set('name', 'Contact us')
        ->set('ownerId', (string) $user->id)
        ->call('save')
        ->assertHasNoErrors();

    $form = LeadCaptureForm::query()->firstOrFail();

    // A slug would let anyone enumerate an organisation's forms.
    expect($form->token)->toHaveLength(32)
        ->and($form->token)->not->toContain('contact');
});

test('a token never changes when the form is edited', function () {
    $user = captureFormsUser();
    $form = LeadCaptureForm::factory()->ownedBy($user)->create();
    $token = $form->token;

    Livewire::actingAs($user)
        ->test(LeadCaptureForms::class)
        ->call('edit', $form->id)
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    // Anywhere it is already embedded keeps working.
    expect($form->fresh()->token)->toBe($token)
        ->and($form->fresh()->name)->toBe('Renamed');
});

test('the builder only offers fields from the catalogue', function () {
    $screen = Livewire::actingAs(captureFormsUser())
        ->test(LeadCaptureForms::class)
        ->call('add')
        ->call('addField', 'a_column_i_invented');

    expect(collect($screen->get('fields'))->pluck('key'))->not->toContain('a_column_i_invented');

    $screen->call('addField', 'city');

    expect(collect($screen->get('fields'))->pluck('key'))->toContain('city');
});

test('a field cannot be added twice', function () {
    $screen = Livewire::actingAs(captureFormsUser())
        ->test(LeadCaptureForms::class)
        ->call('add')
        ->call('addField', 'city')
        ->call('addField', 'city');

    expect(collect($screen->get('fields'))->where('key', 'city'))->toHaveCount(1);
});

test('a form needs a name, an owner and at least one field', function () {
    Livewire::actingAs(captureFormsUser())
        ->test(LeadCaptureForms::class)
        ->call('add')
        ->set('name', '')
        ->set('ownerId', '')
        ->set('fields', [])
        ->call('save')
        ->assertHasErrors(['name', 'ownerId', 'fields']);
});

test('a redirect that is not a url is refused', function () {
    // It is handed to a redirect, so anything that is not one belongs nowhere
    // near it.
    $user = captureFormsUser();

    Livewire::actingAs($user)
        ->test(LeadCaptureForms::class)
        ->call('add')
        ->set('name', 'Contact us')
        ->set('ownerId', (string) $user->id)
        ->set('redirectUrl', 'javascript:alert(1)')
        ->call('save')
        ->assertHasErrors('redirectUrl');
});

test('the embed snippet is an iframe, not a script', function () {
    // A script tag would run our code on somebody else's page.
    $form = LeadCaptureForm::factory()->create();

    expect($form->embedSnippet())->toContain('<iframe')
        ->and($form->embedSnippet())->toContain($form->token)
        ->and($form->embedSnippet())->not->toContain('<script');
});

test('the audit trail records a form without its public token', function () {
    // An audit entry is read by more people than should be able to reconstruct
    // a form's public address.
    $form = LeadCaptureForm::factory()->create();
    $logged = new ReflectionMethod(LeadCaptureForm::class, 'activityAttributes');
    $logged->setAccessible(true);

    expect($logged->invoke($form))->toContain('name')
        ->and($logged->invoke($form))->not->toContain('token');
});

test('every catalogue field has a label, a type and rules', function (string $key) {
    $spec = CaptureField::catalogue()[$key];

    expect($spec['label'])->not->toBeEmpty()
        ->and($spec['rules'])->not->toBeEmpty()
        ->and($spec['type'])->not->toBeEmpty();
})->with(fn () => array_keys(CaptureField::catalogue()));
