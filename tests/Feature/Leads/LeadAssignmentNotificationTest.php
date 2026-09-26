<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Actions\SyncLeadAssigneesAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Models\Lead;
use App\Domain\Notifications\Models\NotificationLog;
use App\Livewire\Leads\LeadForm;
use App\Models\User;
use Livewire\Livewire;

function leadNotifyAdmin(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    foreach (PermissionResolver::models(['leads.view', 'leads.create', 'leads.update', 'leads.assign']) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * Who was told about a lead assignment.
 *
 * @return array<int, int>
 */
function toldAboutLead(): array
{
    return NotificationLog::query()->where('event', 'leads.assigned')->distinct()->orderBy('user_id')->pluck('user_id')->all();
}

test('creating a lead tells its assignees and owner, never the person who made it', function () {
    $admin = leadNotifyAdmin();
    $rep = User::factory()->create(['email_verified_at' => now()]);
    $manager = User::factory()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test(LeadForm::class)
        ->set('first_name', 'Rahim')
        ->set('last_name', 'Uddin')
        ->set('email', 'rahim@example.com')
        ->call('addAssigneeRow')
        ->set('assignees.1.user_id', (string) $rep->id)
        ->set('lead_owner_id', (string) $manager->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(toldAboutLead())->toBe([$rep->id, $manager->id]);
});

test('somebody who is both a new assignee and the owner hears once', function () {
    $admin = leadNotifyAdmin();
    $rep = User::factory()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test(LeadForm::class)
        ->set('first_name', 'Rahim')
        ->set('last_name', 'Uddin')
        ->set('email', 'rahim@example.com')
        ->set('assignees.0.user_id', (string) $rep->id)
        ->set('lead_owner_id', (string) $rep->id)
        ->call('save')
        ->assertHasNoErrors();

    $logs = NotificationLog::query()->where('event', 'leads.assigned')->where('user_id', $rep->id)->get();

    expect(toldAboutLead())->toBe([$rep->id])
        // One notification, possibly delivered on more than one channel.
        ->and($logs->pluck('channel')->unique()->count())->toBe($logs->count());
});

test('an edit tells only the people it newly puts on the lead', function () {
    $admin = leadNotifyAdmin();
    $already = User::factory()->create(['email_verified_at' => now()]);
    $newcomer = User::factory()->create(['email_verified_at' => now()]);
    $lead = Lead::factory()->ownedBy($already)->create();

    $this->actingAs($admin);

    app(UpdateLeadAction::class)($lead, LeadData::fromArray([
        ...$lead->only(['first_name', 'last_name', 'email']),
        'assignees' => [
            ['user_id' => $already->id, 'priority' => null],
            ['user_id' => $newcomer->id, 'priority' => null],
        ],
    ]));

    expect(toldAboutLead())->toBe([$newcomer->id]);
});

test('adding somebody from the lead page tells them, and a repeat add does not', function () {
    $admin = leadNotifyAdmin();
    $rep = User::factory()->create(['email_verified_at' => now()]);
    $lead = Lead::factory()->ownedBy($admin)->create();

    app(SyncLeadAssigneesAction::class)->add($lead, $rep, null, $admin);
    app(SyncLeadAssigneesAction::class)->add($lead, $rep, 2, $admin);

    expect(NotificationLog::query()->where('event', 'leads.assigned')->where('user_id', $rep->id)->pluck('channel')->duplicates()->all())->toBe([])
        ->and(toldAboutLead())->toBe([$rep->id]);
});

test('an automation assigning somebody tells them too', function () {
    // A workflow's assign step has no person behind it.
    $rep = User::factory()->create(['email_verified_at' => now()]);
    $lead = Lead::factory()->create();

    app(SyncLeadAssigneesAction::class)->add($lead, $rep);

    expect(toldAboutLead())->toContain($rep->id);
});

test('assigning yourself tells nobody', function () {
    $admin = leadNotifyAdmin();

    Livewire::actingAs($admin)
        ->test(LeadForm::class)
        ->set('first_name', 'Self')
        ->set('last_name', 'Assigned')
        ->set('email', 'self@example.com')
        ->set('lead_owner_id', (string) $admin->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(toldAboutLead())->toBe([]);
});
