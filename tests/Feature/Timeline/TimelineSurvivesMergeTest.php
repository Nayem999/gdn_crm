<?php

use App\Domain\Accounts\AccountDuplicates;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\ContactDuplicates;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\LeadDuplicates;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Actions\MergeRecordsAction;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;

/**
 * Task 2.5 kept a merged record's audit entries where the events happened. Its
 * notes and documents are the opposite case: a note written on a duplicate is
 * about the same person, so it belongs to whoever survives.
 */
function mergeSource(string $module): DuplicateSource
{
    return app(match ($module) {
        'leads' => LeadDuplicates::class,
        'contacts' => ContactDuplicates::class,
        'accounts' => AccountDuplicates::class,
    });
}

test('notes and documents follow the record they were written about', function (string $module, string $class) {
    $survivor = $class::factory()->create();
    $loser = $class::factory()->create();

    Note::factory()->on($loser)->create(['body' => 'written on the duplicate']);
    Note::factory()->on($survivor)->create(['body' => 'written on the survivor']);
    Document::factory()->on($loser)->create(['title' => 'duplicate-contract.pdf']);

    app(MergeRecordsAction::class)(mergeSource($module), $survivor, $loser);

    expect($survivor->notes()->pluck('body')->sort()->values()->all())
        ->toBe(['written on the duplicate', 'written on the survivor'])
        ->and($survivor->documents()->pluck('title')->all())->toBe(['duplicate-contract.pdf'])
        ->and($loser->notes()->count())->toBe(0)
        ->and($loser->documents()->count())->toBe(0);
})->with(fn () => [
    ['leads', Lead::class],
    ['contacts', Contact::class],
    ['accounts', Account::class],
]);

test('a merge never drags in another module record that shares an id', function () {
    $survivor = Lead::factory()->create();
    $loser = Lead::factory()->create();

    // A contact sitting on the same primary key as the lead being merged away.
    // Moving notes on notable_id alone would take this one too.
    $bystander = Contact::factory()->create();
    $bystander->forceFill(['id' => $loser->id + 50_000])->save();
    $loser->forceFill(['id' => $bystander->id])->save();
    $loser = $loser->fresh();

    Note::factory()->on($loser)->create(['body' => 'about the lead']);
    Note::factory()->on($bystander)->create(['body' => 'about the contact']);
    Document::factory()->on($bystander)->create(['title' => 'contact-file.pdf']);

    app(MergeRecordsAction::class)(mergeSource('leads'), $survivor, $loser);

    expect($survivor->notes()->pluck('body')->all())->toBe(['about the lead'])
        ->and($bystander->notes()->pluck('body')->all())->toBe(['about the contact'])
        ->and($bystander->documents()->pluck('title')->all())->toBe(['contact-file.pdf']);
});

test('the merged record keeps the audit entries that happened to it', function () {
    $survivor = Lead::factory()->create();
    $loser = Lead::factory()->create();

    $before = $loser->activities()->count();

    app(MergeRecordsAction::class)(mergeSource('leads'), $survivor, $loser);

    // The notes move because they describe the person; the audit entries stay
    // because they describe events on that row. Re-pointing them would rewrite
    // who did what to which record.
    expect($loser->activities()->count())->toBeGreaterThan($before);
});
