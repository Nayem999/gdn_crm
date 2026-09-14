<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Attribution\Models\RecordAttribution;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Actions\ConvertLeadAction;
use App\Domain\Leads\DTOs\LeadConversionData;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\LeadDuplicates;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Actions\MergeRecordsAction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Task 12.3 — where a record came from, and how that survives what happens to it.
 */
function metaAttribution(): MarketingAttribution
{
    return MarketingAttribution::fromArray([
        'source' => LeadSource::FacebookLeadAds->value,
        'source_detail' => 'Facebook Lead Ads',
        'meta_lead_id' => '900112233',
        'page_id' => '1019283746',
        'form_id' => '5566778899',
        'form_name' => 'Pump enquiry',
        'meta_campaign_id' => '23851234567890',
        'meta_campaign_name' => 'Summer pump campaign',
        'meta_ad_set_id' => '23851234567891',
        'meta_ad_set_name' => 'Dhaka dealers',
        'meta_ad_id' => '23851234567892',
        'meta_ad_name' => 'Pump offer video',
        'click_id' => 'IwAR-abc123',
        'utm_source' => 'facebook',
        'utm_medium' => 'paid_social',
        'utm_campaign' => 'summer-pump',
        'utm_content' => 'video-a',
    ]);
}

// -- The value -----------------------------------------------------------------

test('an attribution with nothing in it is empty', function () {
    // A row saying only "this existed" is a row every record already writes by
    // existing.
    expect((new MarketingAttribution)->isEmpty())->toBeTrue()
        ->and(MarketingAttribution::fromArray(['captured_at' => now()])->isEmpty())->toBeTrue()
        ->and(MarketingAttribution::forSource(LeadSource::WebForm)->isEmpty())->toBeFalse();
});

test('blank strings are nothing rather than answers', function () {
    $attribution = MarketingAttribution::fromArray(['source' => '   ', 'utm_source' => '']);

    expect($attribution->source)->toBeNull()
        ->and($attribution->utmSource)->toBeNull()
        ->and($attribution->isEmpty())->toBeTrue();
});

test('it knows whether it came from Meta at all', function () {
    expect(metaAttribution()->isFromMeta())->toBeTrue()
        ->and(MarketingAttribution::forSource(LeadSource::ColdCall)->isFromMeta())->toBeFalse()
        // A click id alone is enough: a click-to-WhatsApp conversation has one
        // and no lead-ads id.
        ->and(MarketingAttribution::fromArray(['click_id' => 'x'])->isFromMeta())->toBeTrue();
});

test('merging two keeps what each knew and takes the earlier moment', function () {
    $first = MarketingAttribution::fromArray([
        'source' => LeadSource::FacebookLeadAds->value,
        'meta_campaign_name' => 'Summer pump campaign',
        'captured_at' => '2026-03-01 10:00:00',
    ]);

    $second = MarketingAttribution::fromArray([
        'source' => LeadSource::WebForm->value,
        'utm_campaign' => 'summer-pump',
        'captured_at' => '2026-01-15 09:00:00',
    ]);

    $merged = $first->mergedWith($second);

    // The survivor's own answer wins field by field; the other fills the gaps.
    expect($merged->source)->toBe(LeadSource::FacebookLeadAds->value)
        ->and($merged->metaCampaignName)->toBe('Summer pump campaign')
        ->and($merged->utmCampaign)->toBe('summer-pump')
        // Attribution is about first touch: being created later does not make
        // the customer newer.
        ->and($merged->capturedAt->toDateString())->toBe('2026-01-15');
});

test('the display drops what is not known rather than showing dashes', function () {
    $rows = MarketingAttribution::forSource(LeadSource::FacebookLeadAds, 'Facebook Lead Ads')->forDisplay();

    expect($rows)->toHaveKey('Source')
        ->toHaveKey('Detail')
        ->not->toHaveKey('Ad set')
        ->not->toHaveKey('UTM source');
});

// -- Storing -------------------------------------------------------------------

test('a record records where it came from', function () {
    $lead = Lead::factory()->create();

    $lead->recordAttribution(metaAttribution());

    expect($lead->fresh()->attribution()->metaAdName)->toBe('Pump offer video')
        ->and($lead->fresh()->hasAttribution())->toBeTrue();
});

test('a record with no attribution answers questions rather than returning null', function () {
    $lead = Lead::factory()->create();

    // Never null: every caller can ask without checking first.
    expect($lead->attribution())->toBeInstanceOf(MarketingAttribution::class)
        ->and($lead->attribution()->isEmpty())->toBeTrue()
        ->and($lead->hasAttribution())->toBeFalse();
});

test('there is only ever one attribution per record', function () {
    $lead = Lead::factory()->create();

    $lead->recordAttribution(metaAttribution());
    $lead->recordAttribution(MarketingAttribution::forSource(LeadSource::ColdCall));

    expect(RecordAttribution::query()
        ->where('attributable_type', $lead->getMorphClass())
        ->where('attributable_id', $lead->id)
        ->count())->toBe(1)
        ->and($lead->fresh()->attribution()->source)->toBe(LeadSource::ColdCall->value);
});

test('recording nothing does not wipe what was known', function () {
    $lead = Lead::factory()->create();
    $lead->recordAttribution(metaAttribution());

    $lead->recordAttribution(new MarketingAttribution);

    // "We learned nothing new" is not "forget what you knew", and a capture
    // pipeline that could not tell them apart would erase a lead's history the
    // first time somebody edited it by hand.
    expect($lead->fresh()->attribution()->metaAdName)->toBe('Pump offer video');
});

test('a second touch fills the gaps without overwriting the first', function () {
    $lead = Lead::factory()->create();
    $lead->recordAttribution(MarketingAttribution::fromArray([
        'source' => LeadSource::FacebookLeadAds->value,
        'meta_ad_name' => 'Pump offer video',
    ]));

    $lead->enrichAttribution(MarketingAttribution::fromArray([
        'source' => LeadSource::WebForm->value,
        'utm_content' => 'footer-form',
    ]));

    $attribution = $lead->fresh()->attribution();

    expect($attribution->source)->toBe(LeadSource::FacebookLeadAds->value)
        ->and($attribution->utmContent)->toBe('footer-form');
});

// -- Conversion ----------------------------------------------------------------

test('converting a lead carries its attribution into everything it becomes', function () {
    $actor = User::factory()->create();
    $campaign = Campaign::factory()->create();

    $lead = Lead::factory()->create([
        'status' => LeadStatus::Qualified->value,
        'campaign_id' => $campaign->id,
        'company_name' => 'Dhaka Pumps Ltd',
    ]);

    $lead->recordAttribution(metaAttribution());

    $result = app(ConvertLeadAction::class)($lead, new LeadConversionData(createDeal: true, dealName: 'Pump order'), $actor);

    // This is the moment a CRM normally loses attribution: three new records
    // that never saw the advertisement.
    foreach ([$result->account, $result->contact, $result->deal] as $record) {
        expect($record->attribution()->metaAdName)->toBe('Pump offer video')
            ->and($record->attribution()->metaCampaignId)->toBe('23851234567890')
            ->and($record->campaign_id)->toBe($campaign->id);
    }
});

test('conversion does not re-attribute an account that already had a campaign', function () {
    $actor = User::factory()->create();
    $first = Campaign::factory()->create();
    $second = Campaign::factory()->create();

    $account = Account::factory()->create(['campaign_id' => $first->id]);
    $account->recordAttribution(MarketingAttribution::fromArray([
        'source' => LeadSource::Referral->value,
        'meta_ad_name' => 'Original ad',
    ]));

    $lead = Lead::factory()->create(['status' => LeadStatus::Qualified->value, 'campaign_id' => $second->id]);
    $lead->recordAttribution(metaAttribution());

    app(ConvertLeadAction::class)($lead, new LeadConversionData(accountId: $account->id), $actor);

    // The first touch is the one that won the customer.
    expect($account->fresh()->campaign_id)->toBe($first->id)
        ->and($account->fresh()->attribution()->source)->toBe(LeadSource::Referral->value)
        ->and($account->fresh()->attribution()->metaAdName)->toBe('Original ad')
        // What the account did not know, it takes.
        ->and($account->fresh()->attribution()->formName)->toBe('Pump enquiry');
});

test('a lead with no attribution converts perfectly well', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->create(['status' => LeadStatus::Qualified->value]);

    $result = app(ConvertLeadAction::class)($lead, new LeadConversionData, $actor);

    expect($result->account->hasAttribution())->toBeFalse()
        ->and($result->contact->hasAttribution())->toBeFalse();
});

// -- Merging -------------------------------------------------------------------

test('attribution survives a merge, with the survivor keeping its own answers', function () {
    $survivor = Lead::factory()->create(['email' => 'same@example.com']);
    $loser = Lead::factory()->create(['email' => 'same@example.com']);

    $survivor->recordAttribution(MarketingAttribution::fromArray([
        'source' => LeadSource::FacebookLeadAds->value,
        'meta_ad_name' => 'Survivor ad',
        'captured_at' => '2026-02-01 12:00:00',
    ]));

    $loser->recordAttribution(MarketingAttribution::fromArray([
        'source' => LeadSource::WebForm->value,
        'utm_campaign' => 'loser-campaign',
        'captured_at' => '2026-01-01 12:00:00',
    ]));

    app(MergeRecordsAction::class)(app(LeadDuplicates::class), $survivor, $loser);

    $attribution = $survivor->fresh()->attribution();

    expect($attribution->source)->toBe(LeadSource::FacebookLeadAds->value)
        ->and($attribution->metaAdName)->toBe('Survivor ad')
        // What the duplicate knew and the survivor did not.
        ->and($attribution->utmCampaign)->toBe('loser-campaign')
        ->and($attribution->capturedAt->toDateString())->toBe('2026-01-01');
});

test('a merge into an unattributed survivor takes the duplicate\'s attribution whole', function () {
    $survivor = Lead::factory()->create(['email' => 'same@example.com']);
    $loser = Lead::factory()->create(['email' => 'same@example.com']);

    $loser->recordAttribution(metaAttribution());

    app(MergeRecordsAction::class)(app(LeadDuplicates::class), $survivor, $loser);

    expect($survivor->fresh()->attribution()->metaAdName)->toBe('Pump offer video');
});

// -- The sources ---------------------------------------------------------------

test('Meta\'s channels are lead sources of their own', function (string $case) {
    $source = LeadSource::from($case);

    // Folded into "social media" they would be one cost and one follow-up;
    // apart, they are three.
    expect($source->isFromMeta())->toBeTrue()
        ->and($source->label())->not->toBeEmpty()
        ->and(LeadSource::options())->toHaveKey($case);
})->with([
    'lead ads' => ['facebook_lead_ads'],
    'messenger' => ['facebook_messenger'],
    'whatsapp' => ['whatsapp'],
]);

test('the sources that existed before are untouched', function () {
    // Additive: an enum case removed or renamed would orphan every row holding
    // it, and there is no migration that fixes a value nobody kept.
    foreach (['web_form', 'referral', 'cold_call', 'email', 'event', 'advertising', 'social_media', 'partner', 'chat', 'other'] as $value) {
        expect(LeadSource::tryFrom($value))->not->toBeNull()
            ->and(LeadSource::from($value)->isFromMeta())->toBeFalse();
    }
});

// -- The panel -----------------------------------------------------------------

test('the attribution panel renders what a record knows', function () {
    $user = User::factory()->create();
    $lead = Lead::factory()->create();
    $lead->recordAttribution(metaAttribution());

    $rendered = view('components.marketing-attribution', ['record' => $lead->fresh()])->render();

    expect($rendered)->toContain('Marketing attribution')
        ->toContain('Pump offer video')
        ->toContain('Dhaka dealers')
        ->toContain('Facebook Lead Ads');
})->skip(fn () => ! view()->exists('components.marketing-attribution'), 'The panel component is missing.');

test('the panel stays out of the way when there is nothing to show', function () {
    $lead = Lead::factory()->create();

    $rendered = trim(view('components.marketing-attribution', ['record' => $lead])->render());

    // A panel of eight dashes on every hand-typed lead would push what matters
    // below the fold.
    expect($rendered)->toBe('');
});

test('a deal keeps its attribution when the campaign is deleted', function () {
    $campaign = Campaign::factory()->create();
    $deal = Deal::factory()->create(['campaign_id' => $campaign->id]);
    $deal->recordAttribution(metaAttribution());

    $campaign->forceDelete();

    expect($deal->fresh()->campaign_id)->toBeNull()
        // The campaign record is gone; what the advertisement said is not.
        ->and($deal->fresh()->attribution()->metaCampaignName)->toBe('Summer pump campaign');
});

test('a contact carries attribution too', function () {
    $contact = Contact::factory()->create();
    $contact->recordAttribution(metaAttribution());

    expect($contact->fresh()->attribution()->isFromMeta())->toBeTrue();
});

test('the captured moment defaults to now rather than being left empty', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');

    $lead = Lead::factory()->create();
    $lead->recordAttribution(MarketingAttribution::forSource(LeadSource::WhatsApp));

    // The column is not nullable: an attribution with no moment cannot be put
    // on a timeline or counted in a period, which is most of what it is for.
    expect($lead->fresh()->attribution()->capturedAt->toDateTimeString())->toBe('2026-05-05 08:00:00');

    Carbon::setTestNow();
});
