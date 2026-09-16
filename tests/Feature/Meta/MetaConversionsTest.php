<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Actions\ConvertLeadAction;
use App\Domain\Leads\DTOs\LeadConversionData;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Conversions\Enums\ConversionStatus;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaConversionEvent;
use App\Domain\Settings\SettingsManager;
use App\Livewire\Meta\MetaConversions;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Task 12.12 — reporting CRM outcomes back to Meta.
 *
 * What these protect is Meta's delivery model rather than our own bookkeeping:
 * a conversion counted twice does not merely misreport, it teaches Meta to go
 * and find more people like the ones who did not actually buy. So the
 * idempotency has more tests here than the happy path does.
 */
beforeEach(function () {
    app(SettingsManager::class)->set('meta.app_id', '1772978827247269');
    app(SettingsManager::class)->set('meta.app_secret', 'app-secret-value');
    app(SettingsManager::class)->set('meta.dataset_id', '998877665544');

    MetaAccount::factory()->create(['user_token' => 'EAAconnectiontoken']);
});

/**
 * Meta accepting the event.
 *
 * Called by each test rather than set in `beforeEach`, because a second
 * `Http::fake()` **appends** its stub and leaves the first still matching — so a
 * default success here would quietly win over every refusal a test tried to
 * arrange. See .ai/rules/ingestion.md.
 */
function capiAccepts(): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'messages' => []])]);
}

/**
 * @param  array<int, array<string, mixed>>  $messages
 */
function capiRefuses(array $messages): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 0, 'messages' => $messages])]);
}

/**
 * A lead that genuinely came from Meta, with a click to report against.
 */
function capiLead(array $attributes = []): Lead
{
    $lead = Lead::factory()->create([
        'email' => 'Dara@Example.COM',
        'mobile' => '+44 7700 900123',
        ...$attributes,
    ]);

    $lead->recordAttribution(new MarketingAttribution(
        source: 'whatsapp',
        metaCampaignId: '120200000000001',
        metaAdId: '120200000000003',
        clickId: 'ARAkQ8t9click',
    ));

    return $lead->fresh();
}

function capiAdmin(): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models(['meta.view', 'meta.manage']) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

// -- What is worth reporting ---------------------------------------------------

test('a won deal reports one purchase, with the money', function () {
    capiAccepts();

    $lead = capiLead();
    $deal = Deal::factory()->create(['value' => 125000.50]);
    $lead->copyAttributionTo($deal);

    $deal->forceFill(['stage' => DealStage::Won->value, 'closed_at' => now()])->save();

    $events = MetaConversionEvent::query()->where('event_name', 'Purchase')->get();

    expect($events)->toHaveCount(1)
        ->and((float) $events->first()->value)->toBe(125000.50)
        ->and($events->first()->status())->toBe(ConversionStatus::Sent)
        // business_messaging, because this customer arrived by tapping an
        // advertisement in WhatsApp rather than filling in a form.
        ->and($events->first()->action_source)->toBe('business_messaging');
});

test('winning the same deal twice reports it once', function () {
    capiAccepts();

    $lead = capiLead();
    $deal = Deal::factory()->create(['value' => 1000]);
    $lead->copyAttributionTo($deal);

    $deal->forceFill(['stage' => DealStage::Won->value])->save();
    // Reopened and won again, which happens: a customer changes their mind
    // twice and the pipeline follows them.
    $deal->forceFill(['stage' => DealStage::Negotiation->value])->save();
    $deal->forceFill(['stage' => DealStage::Won->value])->save();

    expect(MetaConversionEvent::query()->where('event_name', 'Purchase')->count())->toBe(1);
});

test('a lead qualified is reported, with its identifiers hashed', function () {
    capiAccepts();

    $lead = capiLead();

    $lead->forceFill(['status' => LeadStatus::Qualified->value])->save();

    $event = MetaConversionEvent::query()->where('event_name', 'Qualified')->firstOrFail();
    $userData = $event->payload['user_data'];

    // Normalised before hashing, or the same customer hashes two ways and Meta
    // matches neither: what is stored on the lead is "Dara@Example.COM".
    expect($userData['em'][0])->toBe(hash('sha256', 'dara@example.com'))
        ->and($userData['ph'][0])->toBe(hash('sha256', '447700900123'))
        // Not a person. Hashing Meta's own click token would make it
        // unmatchable, which is the entire reason it is sent.
        ->and($userData['ctwa_clid'])->toBe('ARAkQ8t9click');
});

test('a lead with nothing from Meta is never reported', function () {
    $lead = Lead::factory()->create(['email' => 'someone@example.com']);

    $lead->forceFill(['status' => LeadStatus::Qualified->value])->save();

    expect(MetaConversionEvent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('nothing is reported when no dataset is configured', function () {
    app(SettingsManager::class)->set('meta.dataset_id', '');

    $lead = capiLead();
    $lead->forceFill(['status' => LeadStatus::Qualified->value])->save();

    expect(MetaConversionEvent::query()->count())->toBe(0);
});

test('a record Meta could not match is kept as skipped rather than sent', function () {
    $lead = Lead::factory()->create(['email' => null, 'phone' => null, 'mobile' => null]);

    // From a Meta campaign, but with no click id, no Meta lead id and no way to
    // reach the person — there is nothing for Meta to match against.
    $lead->recordAttribution(new MarketingAttribution(
        source: 'facebook_ads',
        metaCampaignId: '120200000000001',
    ));

    $lead->fresh()->forceFill(['status' => LeadStatus::Qualified->value])->save();

    $event = MetaConversionEvent::query()->firstOrFail();

    expect($event->status())->toBe(ConversionStatus::Skipped)
        ->and($event->payload)->toBeNull();

    Http::assertNothingSent();
});

// -- What Meta says back -------------------------------------------------------

test('an event Meta records as received counts as sent', function () {
    capiAccepts();

    capiLead()->forceFill(['status' => LeadStatus::Qualified->value])->save();

    $event = MetaConversionEvent::query()->firstOrFail();

    expect($event->status())->toBe(ConversionStatus::Sent)
        ->and($event->sent_at)->not->toBeNull()
        ->and($event->attempts)->toBe(1);
});

test('a 200 that recorded no event is a failure, not a success', function () {
    // Meta's own shape for a refusal: the request succeeded, the event did not.
    capiRefuses([['message' => 'Invalid parameter', 'code' => 100]]);

    capiLead()->forceFill(['status' => LeadStatus::Qualified->value])->save();

    $event = MetaConversionEvent::query()->firstOrFail();

    expect($event->status())->toBe(ConversionStatus::Failed)
        ->and($event->error)->toContain('Invalid parameter');
});

test('an outcome older than Meta will accept is refused with the reason', function () {
    capiAccepts();

    Carbon::setTestNow('2026-09-16 10:00:00');

    $lead = capiLead();
    $deal = Deal::factory()->create(['value' => 500]);
    $lead->copyAttributionTo($deal);

    // Somebody catching up on a pipeline they last touched a fortnight ago.
    $deal->forceFill([
        'stage' => DealStage::Won->value,
        'closed_at' => Carbon::parse('2026-08-30 09:00:00'),
    ])->save();

    $event = MetaConversionEvent::query()->where('event_name', 'Purchase')->firstOrFail();

    expect($event->status())->toBe(ConversionStatus::Failed)
        ->and($event->error)->toContain('seven days');

    Carbon::setTestNow();
});

// -- The log -------------------------------------------------------------------

test('a failure can be retried, and a success is never sent twice', function () {
    // A sequence rather than two fakes, for the same reason: the second call to
    // Http::fake() would append and the first stub would answer both times.
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['events_received' => 0, 'messages' => [['message' => 'Token expired']]])
        ->push(['events_received' => 1, 'messages' => []])]);

    capiLead()->forceFill(['status' => LeadStatus::Qualified->value])->save();

    $event = MetaConversionEvent::query()->firstOrFail();
    expect($event->status())->toBe(ConversionStatus::Failed);

    $admin = capiAdmin();

    Livewire::actingAs($admin)
        ->test(MetaConversions::class)
        ->call('retry', $event->id)
        ->assertSee('Meta accepted it this time.');

    expect($event->fresh()->status())->toBe(ConversionStatus::Sent);

    // Pressing it again sends nothing: the event has gone, and sending it twice
    // is the double count everything here exists to prevent.
    Livewire::actingAs($admin)
        ->test(MetaConversions::class)
        ->call('retry', $event->id);

    expect(MetaConversionEvent::query()->count())->toBe(1)
        ->and($event->fresh()->attempts)->toBe(2);
});

test('the log lists what was reported and what failed', function () {
    capiRefuses([['message' => 'Bad dataset']]);

    capiLead()->forceFill(['status' => LeadStatus::Qualified->value])->save();

    Livewire::actingAs(capiAdmin())
        ->test(MetaConversions::class)
        ->assertSee('Lead qualified')
        ->assertSee('Bad dataset')
        ->assertSee('Try again');
});

test('reading the log needs the Meta permission', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(MetaConversions::class)
        ->assertForbidden();
});

test('converting a Meta lead reports the opportunity', function () {
    capiAccepts();

    $lead = capiLead(['owner_id' => capiAdmin()->id, 'company_name' => 'Acme Industries']);

    // The path that matters: conversion creates the deal and copies the
    // attribution across **afterwards**, so anything that only looked at the
    // moment of creation would find nothing and never look again.
    app(ConvertLeadAction::class)(
        $lead,
        new LeadConversionData(dealValue: '9000'),
        $lead->owner ?? capiAdmin(),
    );

    $event = MetaConversionEvent::query()->where('event_name', 'Opportunity')->firstOrFail();

    expect($event->status())->toBe(ConversionStatus::Sent)
        ->and($event->subject_type)->toContain('Deal')
        // The money belongs to the win alone: an opportunity worth 9,000 that
        // closes at 900 would have reported ten times the revenue, and Meta
        // never hears the correction.
        ->and($event->value)->toBeNull();
});
