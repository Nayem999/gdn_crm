<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Models\Activity;
use App\Domain\Company\Models\Company;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Settings\DisplayTime;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Livewire\Activities\ActivityForm;
use App\Livewire\Deals\DealsIndex;
use App\Livewire\Leads\LeadShow;
use App\Livewire\Leads\LeadsIndex;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * Stored times are UTC; the company timezone is the clock people read and
 * type in. 20:00 UTC on the 27th is 02:00 on the 28th in Dhaka, so a record
 * saved then must read as the 28th, and "10:30" typed on a form means half
 * past ten in Dhaka.
 */
function officeUser(): User
{
    $role = Role::query()->create([
        'name' => 'Office clock '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::All->value,
    ]);

    $role->syncPermissions(PermissionResolver::models([
        'activities.view', 'activities.create', 'activities.update', 'activities.assign',
        'leads.view', 'leads.update', 'deals.view', 'accounts.view', 'contacts.view',
    ]));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

beforeEach(function () {
    Company::current()->forceFill(['timezone' => 'Asia/Dhaka'])->save();
    Carbon::setTestNow('2026-09-27 20:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a record saved late in the UTC day shows the office date on its page and in the list', function () {
    $user = officeUser();
    $lead = Lead::factory()->ownedBy($user)->create();

    expect($lead->created_at->format('Y-m-d'))->toBe('2026-09-27');

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->assertSee('28 Sep 2026')
        ->assertDontSee('27 Sep 2026');

    $cell = (string) Livewire::actingAs($user)->test(LeadsIndex::class)->instance()
        ->cellFor($lead, new Column('created_at', 'Created'));

    expect($cell)->toBe('28 Sep 2026');
});

test('a date column is a day, and is not shifted by the offset', function () {
    Company::current()->forceFill(['timezone' => 'America/New_York'])->save();
    $user = officeUser();
    $deal = Deal::factory()->ownedBy($user)->create(['expected_close_date' => '2026-10-15']);

    $cell = (string) Livewire::actingAs($user)->test(DealsIndex::class)->instance()
        ->cellFor($deal, new Column('expected_close_date', 'Expected close'));

    expect($cell)->toContain('15 Oct 2026');
});

test('a time typed on the activity form is office time, and reads back the same', function () {
    $user = officeUser();

    Livewire::actingAs($user)
        ->test(ActivityForm::class)
        ->set('type', ActivityType::Call->value)
        ->set('subject', 'Call Dana back')
        ->set('due_date', '2026-10-05')
        ->set('due_time', '10:30')
        ->call('save')
        ->assertHasNoErrors();

    $activity = Activity::query()->sole();

    // Half past ten in Dhaka is half past four UTC.
    expect($activity->due_at->format('Y-m-d H:i'))->toBe('2026-10-05 04:30')
        ->and($activity->dueLabel())->toBe('5 Oct 2026, 10:30 AM');

    Livewire::actingAs($user)
        ->test(ActivityForm::class, ['activity' => $activity])
        ->assertSet('due_date', '2026-10-05')
        ->assertSet('due_time', '10:30')
        ->call('save')
        ->assertHasNoErrors();

    // Saving it again without touching the time does not move it.
    expect($activity->refresh()->due_at->format('Y-m-d H:i'))->toBe('2026-10-05 04:30');
});

test('the activity form starts on the office\'s today', function () {
    Livewire::actingAs(officeUser())
        ->test(ActivityForm::class)
        ->assertSet('due_date', '2026-09-28');
});

test('times are 12-hour by default, and 24-hour when the setting says so', function () {
    $moment = Carbon::parse('2026-10-05 09:05:00');

    expect(DisplayTime::dateTime($moment))->toBe('5 Oct 2026, 3:05 PM');

    settings()->set('localisation.time_format', 'H:i');

    expect(DisplayTime::dateTime($moment))->toBe('5 Oct 2026, 15:05');
});
