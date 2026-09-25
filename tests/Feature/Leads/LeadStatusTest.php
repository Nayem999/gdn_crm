<?php

use App\Domain\Leads\Actions\ChangeLeadStatusAction;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\DeleteLeadAction;
use App\Domain\Leads\Actions\SyncLeadAssigneesAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\UI\ChipPalette;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

afterEach(function () {
    Carbon::setTestNow();
});

// -- The enums -----------------------------------------------------------------

test('every status has a label, description and palette colour', function (LeadStatus $status) {
    expect($status->label())->not->toBeEmpty()
        ->and($status->description())->not->toBeEmpty()
        ->and(ChipPalette::has($status->color()))->toBeTrue();
})->with(LeadStatus::cases());

test('every source has a label, palette colour and option', function (LeadSource $source) {
    expect($source->label())->not->toBeEmpty()
        ->and(ChipPalette::has($source->color()))->toBeTrue()
        ->and(LeadSource::options())->toHaveKey($source->value);
})->with(LeadSource::cases());

test('the pipeline lists every status exactly once', function () {
    expect(LeadStatus::pipeline())->toHaveCount(count(LeadStatus::cases()))
        ->and(LeadStatus::pipeline())->toEqualCanonicalizing(LeadStatus::cases());
});

// -- Transition rules ----------------------------------------------------------

test('a status never allows a move to itself', function (LeadStatus $status) {
    expect($status->canTransitionTo($status))->toBeFalse();
})->with(LeadStatus::cases());

test('nothing may be moved into converted', function (LeadStatus $status) {
    // Converted is set by conversion (task 2.6) once an account, contact and
    // deal genuinely exist — never by a board drag or a form.
    expect($status->canTransitionTo(LeadStatus::Converted))->toBeFalse();
})->with(LeadStatus::cases());

test('a converted lead cannot be moved anywhere', function () {
    expect(LeadStatus::Converted->allowedTransitions())->toBe([])
        ->and(LeadStatus::Converted->isClosed())->toBeTrue();

    foreach (LeadStatus::cases() as $target) {
        expect(LeadStatus::Converted->canTransitionTo($target))->toBeFalse();
    }
});

test('the transitions a new lead may make', function () {
    expect(LeadStatus::New->allowedTransitions())
        ->toBe([LeadStatus::Contacted, LeadStatus::Nurturing, LeadStatus::Unqualified]);
});

test('an unqualified lead can be reopened, but only back into the working states', function () {
    expect(LeadStatus::Unqualified->allowedTransitions())
        ->toBe([LeadStatus::Contacted, LeadStatus::Nurturing]);
});

test('every transition a status offers is a real status it does not already hold', function (LeadStatus $status) {
    $targets = $status->allowedTransitions();

    // A closed status offering nothing is the correct answer, not an untested
    // one — assert it rather than letting the loop pass vacuously.
    expect($targets)->toBeArray()
        ->and($targets === [])->toBe($status->isClosed());

    foreach ($targets as $target) {
        expect($target)->not->toBe($status)
            ->and(in_array($target, LeadStatus::cases(), true))->toBeTrue();
    }
})->with(LeadStatus::cases());

// -- Changing status -----------------------------------------------------------

test('an allowed move is applied and stamps when it happened', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));
    $lead = Lead::factory()->status(LeadStatus::New)->create();

    Carbon::setTestNow(Carbon::parse('2026-01-15 09:00:00'));
    app(ChangeLeadStatusAction::class)($lead, LeadStatus::Contacted);

    expect($lead->fresh()->status())->toBe(LeadStatus::Contacted)
        ->and($lead->fresh()->status_changed_at->format('Y-m-d'))->toBe('2026-01-15');
});

test('a move the current status forbids is refused and changes nothing', function () {
    $lead = Lead::factory()->status(LeadStatus::New)->create();

    expect(fn () => app(ChangeLeadStatusAction::class)($lead, LeadStatus::Qualified))
        ->toThrow(RuntimeException::class, 'cannot move to qualified');

    expect($lead->fresh()->status())->toBe(LeadStatus::New);
});

test('a move into converted is refused however it is asked for', function () {
    $lead = Lead::factory()->status(LeadStatus::Qualified)->create();

    expect(fn () => app(ChangeLeadStatusAction::class)($lead, LeadStatus::Converted))
        ->toThrow(RuntimeException::class);

    expect($lead->fresh()->status())->toBe(LeadStatus::Qualified);
});

test('a converted lead is immovable', function () {
    $lead = Lead::factory()->status(LeadStatus::Converted)->create();

    expect(fn () => app(ChangeLeadStatusAction::class)($lead, LeadStatus::Contacted))
        ->toThrow(RuntimeException::class);

    expect($lead->fresh()->status())->toBe(LeadStatus::Converted);
});

test('moving to the status it already holds is a no-op, not an error', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));
    $lead = Lead::factory()->status(LeadStatus::Contacted)->create();
    $stamp = $lead->status_changed_at;

    Carbon::setTestNow(Carbon::parse('2026-02-01 09:00:00'));
    app(ChangeLeadStatusAction::class)($lead, LeadStatus::Contacted);

    expect($lead->fresh()->status())->toBe(LeadStatus::Contacted)
        // The stamp is left alone, so "days in status" stays honest.
        ->and($lead->fresh()->status_changed_at->format('Y-m-d'))->toBe($stamp->format('Y-m-d'));
});

test('the whole pipeline can be walked one allowed move at a time', function () {
    $lead = Lead::factory()->status(LeadStatus::New)->create();
    $change = app(ChangeLeadStatusAction::class);

    foreach ([LeadStatus::Contacted, LeadStatus::Nurturing, LeadStatus::Qualified, LeadStatus::Unqualified] as $target) {
        $change($lead, $target);
        expect($lead->fresh()->status())->toBe($target);
    }

    // And back into play from the dead end.
    $change($lead, LeadStatus::Contacted);
    expect($lead->fresh()->status())->toBe(LeadStatus::Contacted);
});

test('conversion may force the status past the rules, and nothing else should', function () {
    $lead = Lead::factory()->status(LeadStatus::Qualified)->create();

    app(ChangeLeadStatusAction::class)->force($lead, LeadStatus::Converted);

    expect($lead->fresh()->status())->toBe(LeadStatus::Converted)
        ->and($lead->fresh()->status_changed_at)->not->toBeNull();
});

// -- The model -----------------------------------------------------------------

test('a lead reads its name, initials, status and source', function () {
    $lead = Lead::factory()
        ->named('Dana', 'Scully')
        ->status(LeadStatus::Qualified)
        ->source(LeadSource::Referral)
        ->create();

    expect($lead->fullName())->toBe('Dana Scully')
        ->and($lead->initials())->toBe('DS')
        ->and($lead->status())->toBe(LeadStatus::Qualified)
        ->and($lead->source())->toBe(LeadSource::Referral);
});

test('an unrecognised status reads as new rather than throwing', function () {
    $lead = Lead::factory()->create();
    $lead->forceFill(['status' => 'time-travel'])->save();

    expect($lead->fresh()->status())->toBe(LeadStatus::New);
});

test('the role line combines job title and company, skipping what is missing', function () {
    expect(Lead::factory()->make(['job_title' => 'CTO', 'company_name' => 'Acme'])->roleLine())->toBe('CTO · Acme')
        ->and(Lead::factory()->make(['job_title' => 'CTO', 'company_name' => null])->roleLine())->toBe('CTO')
        ->and(Lead::factory()->make(['job_title' => null, 'company_name' => 'Acme'])->roleLine())->toBe('Acme')
        ->and(Lead::factory()->make(['job_title' => null, 'company_name' => null])->roleLine())->toBeNull();
});

test('days in status counts from the last move', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));
    $lead = Lead::factory()->stalledFor(0)->create();

    Carbon::setTestNow(Carbon::parse('2026-01-15 09:00:00'));

    expect($lead->fresh()->daysInStatus())->toBe(14);
});

test('days in status falls back to when the lead was captured', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));
    $lead = Lead::factory()->create();
    $lead->forceFill(['status_changed_at' => null])->save();

    Carbon::setTestNow(Carbon::parse('2026-01-06 09:00:00'));

    expect($lead->fresh()->daysInStatus())->toBe(5);
});

test('a website without a scheme still produces a usable link', function () {
    expect(Lead::factory()->make(['website' => 'example.com'])->websiteUrl())->toBe('https://example.com')
        ->and(Lead::factory()->make(['website' => null])->websiteUrl())->toBeNull();
});

test('search matches either name, the two together, company, email and phone', function () {
    Lead::factory()->named('Dana', 'Scully')->create(['company_name' => 'Acme Corporation', 'email' => 'dana@acme.test', 'phone' => '0113 111']);
    Lead::factory()->named('Fox', 'Mulder')->create(['company_name' => 'Beta Industries', 'email' => 'fox@beta.test', 'phone' => '0113 222']);

    expect(Lead::query()->search('Scully')->count())->toBe(1)
        ->and(Lead::query()->search('Dana Scully')->count())->toBe(1)
        ->and(Lead::query()->search('Acme')->count())->toBe(1)
        ->and(Lead::query()->search('beta.test')->count())->toBe(1)
        ->and(Lead::query()->search('0113')->count())->toBe(2)
        ->and(Lead::query()->search('')->count())->toBe(2);
});

test('the open scope leaves out converted and unqualified leads', function () {
    Lead::factory()->status(LeadStatus::New)->create();
    Lead::factory()->status(LeadStatus::Contacted)->create();
    Lead::factory()->status(LeadStatus::Nurturing)->create();
    Lead::factory()->status(LeadStatus::Qualified)->create();
    Lead::factory()->status(LeadStatus::Unqualified)->create();
    Lead::factory()->status(LeadStatus::Converted)->create();

    expect(Lead::query()->open()->count())->toBe(4);
});

// -- Capture and update --------------------------------------------------------

test('a captured lead always starts as new, whatever the caller asks', function () {
    $actor = User::factory()->create();

    $lead = app(CreateLeadAction::class)(
        // A status is not something a capture form gets to assert.
        LeadData::fromArray(['first_name' => 'Dana', 'last_name' => 'Scully', 'status' => 'qualified']),
        $actor
    );

    expect($lead->status())->toBe(LeadStatus::New)
        ->and($lead->status_changed_at)->not->toBeNull()
        // A lead always has at least one assignee; nobody named one, so the
        // acting user became it — mirroring what owner_id used to default to.
        ->and(leadOwnerId($lead))->toBe($actor->id);
});

test('an explicit assignee wins over whoever captured it', function () {
    $owner = User::factory()->create();

    $lead = app(CreateLeadAction::class)(
        LeadData::fromArray([
            'first_name' => 'Dana', 'last_name' => 'Scully',
            'assignees' => [['user_id' => $owner->id, 'priority' => null]],
        ]),
        User::factory()->create()
    );

    expect(leadOwnerId($lead))->toBe($owner->id);
});

test('an ordinary update cannot move the status', function () {
    $lead = Lead::factory()->status(LeadStatus::Contacted)->create();

    // The DTO carries no status field, so even a tampered payload cannot.
    app(UpdateLeadAction::class)($lead, LeadData::fromArray([
        'first_name' => 'Renamed',
        'last_name' => $lead->last_name,
        'status' => LeadStatus::Qualified->value,
    ]));

    expect($lead->fresh()->status())->toBe(LeadStatus::Contacted)
        ->and($lead->fresh()->first_name)->toBe('Renamed');
});

test('an update clears a field the user emptied', function () {
    $lead = Lead::factory()->create(['phone' => '0113 000 0000']);

    app(UpdateLeadAction::class)($lead, LeadData::fromArray([
        'first_name' => $lead->first_name,
        'last_name' => $lead->last_name,
    ]));

    expect($lead->fresh()->phone)->toBeNull();
});

test('an update that omits assignees leaves them alone', function () {
    $owner = User::factory()->create();
    $lead = Lead::factory()->ownedBy($owner)->create();

    app(UpdateLeadAction::class)($lead, LeadData::fromArray([
        'first_name' => $lead->first_name,
        'last_name' => $lead->last_name,
    ]));

    expect(leadOwnerId($lead->fresh()))->toBe($owner->id);
});

// -- Assignment ----------------------------------------------------------------

test('assigning adds somebody alongside whoever is already on the lead', function () {
    $lead = Lead::factory()->create();
    $newAssignee = User::factory()->create();

    app(SyncLeadAssigneesAction::class)->add($lead, $newAssignee);

    expect(leadAssigneeIds($lead->fresh()))->toContain($newAssignee->id);
});

test('assigning somebody already on the lead changes their priority rather than duplicating the row', function () {
    $owner = User::factory()->create();
    $lead = Lead::factory()->ownedBy($owner)->create();

    app(SyncLeadAssigneesAction::class)->add($lead, $owner, priority: 1);

    $fresh = $lead->fresh();

    expect($fresh->assignees()->count())->toBe(1)
        ->and($fresh->assignees()->first()->priority)->toBe(1);
});

test('deleting a lead keeps the row so history still resolves', function () {
    $lead = Lead::factory()->create();

    app(DeleteLeadAction::class)($lead);

    expect($lead->fresh()->trashed())->toBeTrue()
        ->and(Lead::withTrashed()->whereKey($lead->id)->exists())->toBeTrue();
});

// -- Audit ---------------------------------------------------------------------

test('a status move is recorded in the audit trail with the old and new value', function () {
    $this->actingAs(User::factory()->create());

    $lead = Lead::factory()->status(LeadStatus::New)->create();

    app(ChangeLeadStatusAction::class)($lead, LeadStatus::Contacted);

    $entry = Activity::query()
        ->where('subject_type', Lead::class)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($entry->properties['attributes']['status'])->toBe(LeadStatus::Contacted->value)
        ->and($entry->properties['old']['status'])->toBe(LeadStatus::New->value);
});

test('the audit trail records only the allowlisted lead fields', function () {
    $this->actingAs(User::factory()->create());

    $lead = Lead::factory()->create();
    $lead->update(['description' => 'A private note', 'company_name' => 'Acme']);

    $entry = Activity::query()
        ->where('subject_type', Lead::class)
        ->where('event', 'updated')
        ->firstOrFail();

    expect(array_keys($entry->properties['attributes']))->toBe(['company_name'])
        ->and(json_encode($entry->properties))->not->toContain('A private note');
});
