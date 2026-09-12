<?php

use App\Domain\Chat\Models\ChatConversation;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadCaptureForm;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

function chatWidget(array $overrides = []): LeadCaptureForm
{
    return LeadCaptureForm::factory()->create([
        'kind' => 'chat',
        'source' => LeadSource::WebForm->value,
        ...$overrides,
    ]);
}

function say(LeadCaptureForm $widget, string $session, string $message, array $visitor = [])
{
    return test()->postJson('/c/'.$widget->token, [
        'session_id' => $session,
        'message' => $message,
        ...$visitor,
    ]);
}

beforeEach(function () {
    // The route throttles, and each test starts from zero.
    Cache::flush();
});

// -- A conversation becomes a lead ------------------------------------------------

it('keeps a conversation that has nobody behind it yet', function () {
    $widget = chatWidget();
    $session = (string) Str::uuid();

    say($widget, $session, 'Do you sell in Bangladesh?')
        ->assertOk()
        ->assertJson(['identified' => false]);

    $conversation = ChatConversation::query()->firstOrFail();

    expect($conversation->messages)->toHaveCount(1)
        ->and($conversation->lead_id)->toBeNull()
        // Not a lead: there is no way to reach this person, and a lead nobody
        // can answer is a row somebody deletes later.
        ->and(Lead::query()->count())->toBe(0);
});

it('makes a lead as soon as the visitor leaves an address', function () {
    $owner = User::factory()->create();
    $widget = chatWidget(['owner_id' => $owner->id]);
    $session = (string) Str::uuid();

    say($widget, $session, 'Do you sell in Bangladesh?');
    say($widget, $session, 'Yes please, send me a quote.', [
        'name' => 'Priya Ramanathan',
        'email' => 'priya@example.com',
    ])->assertOk()->assertJson(['identified' => true]);

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Priya')
        ->and($lead->last_name)->toBe('Ramanathan')
        ->and($lead->email)->toBe('priya@example.com')
        // From the widget, never the payload.
        ->and($lead->owner_id)->toBe($owner->id)
        ->and($lead->source())->toBe(LeadSource::WebForm)
        // The whole conversation, including what was said before they gave
        // their name.
        ->and($lead->description)->toContain('Do you sell in Bangladesh?')
        ->and($lead->description)->toContain('send me a quote');
});

it('updates the one lead rather than making another with every message', function () {
    $widget = chatWidget();
    $session = (string) Str::uuid();

    say($widget, $session, 'Hello', ['email' => 'someone@example.com']);
    say($widget, $session, 'Still there?');
    say($widget, $session, 'One more thing.');

    expect(Lead::query()->count())->toBe(1)
        ->and(ChatConversation::query()->count())->toBe(1)
        ->and(Lead::query()->firstOrFail()->description)->toContain('One more thing.');
});

it('accepts a phone number as a way to reach somebody', function () {
    $widget = chatWidget();

    say($widget, (string) Str::uuid(), 'Call me', ['phone' => '+8801811111111']);

    $lead = Lead::query()->firstOrFail();

    expect($lead->phone)->toBe('+8801811111111')
        ->and($lead->first_name)->toBe('Website visitor');
});

it('keeps the details it was given first rather than letting a later message blank them', function () {
    $widget = chatWidget();
    $session = (string) Str::uuid();

    say($widget, $session, 'Hi', ['name' => 'Priya Ramanathan', 'email' => 'priya@example.com']);
    say($widget, $session, 'Another question', ['name' => '', 'email' => '']);

    $conversation = ChatConversation::query()->firstOrFail();

    expect($conversation->visitor_name)->toBe('Priya Ramanathan')
        ->and($conversation->visitor_email)->toBe('priya@example.com');
});

it('records the page the visitor was on', function () {
    $widget = chatWidget();

    say($widget, (string) Str::uuid(), 'Hi', ['page_url' => 'https://example.com/pricing']);

    expect(ChatConversation::query()->firstOrFail()->page_url)->toBe('https://example.com/pricing');
});

it('separates two visitors talking at the same time', function () {
    $widget = chatWidget();

    say($widget, (string) Str::uuid(), 'First visitor', ['email' => 'one@example.com']);
    say($widget, (string) Str::uuid(), 'Second visitor', ['email' => 'two@example.com']);

    expect(ChatConversation::query()->count())->toBe(2)
        ->and(Lead::query()->count())->toBe(2);
});

// -- What the endpoint will not do -------------------------------------------------

it('will not accept a message through a form token', function () {
    $form = LeadCaptureForm::factory()->create(['kind' => 'form']);

    // A public form has a honeypot and a timing check that this interface never
    // runs; letting one be driven through the other would be a way round both.
    say($form, (string) Str::uuid(), 'Hello')->assertNotFound();

    expect(ChatConversation::query()->count())->toBe(0);
});

it('says plainly when the widget is switched off', function () {
    $widget = chatWidget(['is_active' => false]);

    say($widget, (string) Str::uuid(), 'Anyone there?')
        ->assertStatus(503)
        ->assertJson(['message' => 'Chat is closed right now.']);

    expect(ChatConversation::query()->count())->toBe(0);
});

it('refuses a message with no session or no words', function () {
    $widget = chatWidget();

    test()->postJson('/c/'.$widget->token, ['message' => 'No session'])
        ->assertStatus(422);

    test()->postJson('/c/'.$widget->token, ['session_id' => (string) Str::uuid(), 'message' => ''])
        ->assertStatus(422);
});

it('rate limits a visitor who will not stop typing', function () {
    $widget = chatWidget();
    $session = (string) Str::uuid();

    for ($i = 0; $i < 30; $i++) {
        say($widget, $session, 'Message '.$i)->assertOk();
    }

    say($widget, $session, 'One too many')->assertStatus(429);
});

it('does not need a CSRF token, because the widget lives on somebody else site', function () {
    $widget = chatWidget();

    // No token, no session: exactly what a cross-site widget can offer.
    test()->post('/c/'.$widget->token, [
        'session_id' => (string) Str::uuid(),
        'message' => 'Hello',
    ])->assertOk();
});

it('tells the widget where to post and does not pretend to ship a front end', function () {
    $widget = chatWidget();

    expect($widget->endpoint())->toEndWith('/c/'.$widget->token)
        ->and($widget->embedSnippet())->toBe($widget->endpoint());
});
