<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Models\NotificationSetting;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\NotificationMatrix;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Recipient;
use App\Domain\Notifications\UserNotificationPreferences;
use App\Domain\Settings\SettingsManager;
use App\Jobs\SendNotification;
use App\Livewire\Notifications\NotificationBell;
use App\Livewire\Notifications\NotificationLogIndex;
use App\Livewire\Notifications\NotificationMatrixScreen;
use App\Livewire\Notifications\NotificationPreferencesPanel;
use App\Livewire\Notifications\NotificationTemplates;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function screenUser(array $permissions = []): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

beforeEach(function () {
    Cache::flush();
    app(SettingsManager::class)->flush();
    app(NotificationMatrix::class)->flush();
});

// -- The matrix screen ---------------------------------------------------------

test('the matrix screen needs the notifications.view permission', function () {
    $this->actingAs(screenUser())->get(route('settings.notifications'))->assertForbidden();

    $this->actingAs(screenUser(['notifications.view']))
        ->get(route('settings.notifications'))
        ->assertOk()
        ->assertSee('Notification matrix');
});

test('a guest cannot reach the matrix', function () {
    $this->get(route('settings.notifications'))->assertRedirect(route('login'));
});

test('the matrix lists every event with its recipient types and channels', function () {
    $response = $this->actingAs(screenUser(['notifications.view']))->get(route('settings.notifications'));

    $response->assertOk()
        ->assertSee('User invited')
        ->assertSee('Invitation accepted')
        ->assertSee('Credential changed')
        ->assertSee('Administrator');

    foreach (NotificationChannel::cases() as $channel) {
        $response->assertSee($channel->label());
    }
});

test('the matrix says which channels are not connected yet', function () {
    $this->actingAs(screenUser(['notifications.view']))
        ->get(route('settings.notifications'))
        ->assertSee('not connected yet')
        ->assertSee('recorded in the log as skipped');
});

test('toggling a cell needs the update permission', function () {
    Livewire::actingAs(screenUser(['notifications.view']))
        ->test(NotificationMatrixScreen::class)
        ->call('toggle', 'user.joined', RecipientType::Admin->value, NotificationChannel::Email->value)
        ->assertForbidden();

    expect(app(NotificationMatrix::class)
        ->isEnabled('user.joined', RecipientType::Admin, NotificationChannel::Email))->toBeFalse();
});

test('someone who cannot update sees the matrix read-only', function () {
    Livewire::actingAs(screenUser(['notifications.view']))
        ->test(NotificationMatrixScreen::class)
        ->assertSee('read-only access to the matrix')
        ->assertSee('disabled', false);
});

test('an administrator can switch a channel on and it sticks', function () {
    Livewire::actingAs(screenUser(['notifications.view', 'notifications.update']))
        ->test(NotificationMatrixScreen::class)
        ->call('toggle', 'user.joined', RecipientType::Admin->value, NotificationChannel::Email->value);

    expect((new NotificationMatrix)->isEnabled('user.joined', RecipientType::Admin, NotificationChannel::Email))
        ->toBeTrue();
});

test('a cell for a recipient type or channel the event does not have is refused', function () {
    $component = Livewire::actingAs(screenUser(['notifications.view', 'notifications.update']))
        ->test(NotificationMatrixScreen::class);

    $component->call('toggle', 'user.joined', RecipientType::Customer->value, NotificationChannel::Email->value);
    $component->call('toggle', 'user.joined', RecipientType::Admin->value, 'carrier-pigeon');
    $component->call('toggle', 'made.up.event', RecipientType::Admin->value, NotificationChannel::Email->value);

    expect(NotificationSetting::query()->count())->toBe(0);
});

// -- Templates -----------------------------------------------------------------

test('the template editor loads the default wording', function () {
    Livewire::actingAs(screenUser(['notifications.view', 'notifications.update']))
        ->test(NotificationTemplates::class)
        ->assertSet('eventKey', 'user.invited')
        ->assertSet('subject', 'New invitation sent')
        ->assertSee('Using the default wording.');
});

test('someone who cannot update sees templates read-only', function () {
    Livewire::actingAs(screenUser(['notifications.view']))
        ->test(NotificationTemplates::class)
        ->assertSee('read-only access to templates')
        ->assertDontSee('Save template');
});

test('the editor lists the merge fields the event offers', function () {
    Livewire::actingAs(screenUser(['notifications.view']))
        ->test(NotificationTemplates::class)
        ->set('eventKey', 'user.joined')
        ->assertSee('user.name')
        ->assertSee('user.email')
        ->assertSee('app.name');
});

test('the preview shows the shape of the message', function () {
    Livewire::actingAs(screenUser(['notifications.view']))
        ->test(NotificationTemplates::class)
        ->set('eventKey', 'user.joined')
        ->set('body', 'Hello {{user.name}}')
        ->assertSee('Hello [user.name]');
});

test('saving a template needs the update permission', function () {
    Livewire::actingAs(screenUser(['notifications.view']))
        ->test(NotificationTemplates::class)
        ->set('body', 'Anything at all')
        ->call('save')
        ->assertForbidden();

    expect(NotificationTemplate::query()->count())->toBe(0);
});

test('an administrator can rewrite a template and it is used', function () {
    Livewire::actingAs(screenUser(['notifications.view', 'notifications.update']))
        ->test(NotificationTemplates::class)
        ->set('eventKey', 'user.joined')
        ->set('channel', NotificationChannel::InApp->value)
        ->set('subject', 'Welcome aboard')
        ->set('body', '{{user.name}} has arrived.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('template-saved');

    $user = User::factory()->create(['email_verified_at' => now()]);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)], [
        'user' => ['name' => 'Dana', 'email' => 'dana@example.test'],
    ]);

    expect($user->notifications()->firstOrFail()->data['message'])->toBe('Dana has arrived.');
});

test('a template naming a field the event does not carry is refused', function () {
    Livewire::actingAs(screenUser(['notifications.view', 'notifications.update']))
        ->test(NotificationTemplates::class)
        ->set('eventKey', 'user.joined')
        ->set('body', 'Hello {{ticket.number}}')
        ->call('save')
        ->assertHasErrors(['body']);

    expect(NotificationTemplate::query()->count())->toBe(0);
});

test('an empty template is refused', function () {
    Livewire::actingAs(screenUser(['notifications.view', 'notifications.update']))
        ->test(NotificationTemplates::class)
        ->set('body', '')
        ->call('save')
        ->assertHasErrors(['body']);
});

test('a customised template can be put back to the default', function () {
    $component = Livewire::actingAs(screenUser(['notifications.view', 'notifications.update']))
        ->test(NotificationTemplates::class)
        ->set('eventKey', 'user.joined')
        ->set('body', '{{user.name}} has arrived.')
        ->call('save');

    expect($component->instance()->isCustomised())->toBeTrue();

    $component->call('resetToDefault');

    expect(NotificationTemplate::query()->count())->toBe(0);
    $component->assertSet('body', '{{user.name}} ({{user.email}}) accepted their invitation.');
});

// -- The log -------------------------------------------------------------------

test('the log needs the notifications.view permission', function () {
    $this->actingAs(screenUser())->get(route('settings.notifications.log'))->assertForbidden();

    $this->actingAs(screenUser(['notifications.view']))
        ->get(route('settings.notifications.log'))
        ->assertOk()
        ->assertSee('Notification log');
});

test('the log lists what was attempted', function () {
    $user = User::factory()->create(['name' => 'Dana Scully', 'email_verified_at' => now()]);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)], [
        'user' => ['name' => 'Fox', 'email' => 'fox@example.test'],
    ]);

    Livewire::actingAs(screenUser(['notifications.view']))
        ->test(NotificationLogIndex::class)
        ->assertSee('Invitation accepted')
        ->assertSee('Dana Scully')
        ->assertSee('Sent');
});

test('the log can be filtered by status, channel and event', function () {
    // Asserted on the recipients rather than the event labels: every label also
    // appears in the event filter's own dropdown.
    $user = User::factory()->create(['name' => 'Dana Scully', 'email_verified_at' => now()]);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)]);

    NotificationLog::query()->create([
        'event' => 'user.removed',
        'channel' => NotificationChannel::Email->value,
        'recipient_type' => RecipientType::Admin->value,
        'recipient' => 'someone@example.test',
        'status' => NotificationStatus::Failed->value,
        'error' => 'Mailbox unavailable',
    ]);

    $component = Livewire::actingAs(screenUser(['notifications.view']))->test(NotificationLogIndex::class);

    $component->set('status', NotificationStatus::Failed->value)
        ->assertSee('someone@example.test')
        ->assertSee('Mailbox unavailable')
        ->assertDontSee('Dana Scully');

    $component->call('clearFilters')->set('channel', NotificationChannel::InApp->value)
        ->assertSee('Dana Scully')
        ->assertDontSee('someone@example.test');

    $component->call('clearFilters')->set('event', 'user.removed')
        ->assertSee('someone@example.test')
        ->assertDontSee('Dana Scully');
});

test('the log shows why something was skipped', function () {
    NotificationLog::query()->create([
        'event' => 'user.joined',
        'channel' => NotificationChannel::Sms->value,
        'recipient_type' => RecipientType::Admin->value,
        'status' => NotificationStatus::Skipped->value,
        'error' => 'No SMS provider is configured yet.',
    ]);

    Livewire::actingAs(screenUser(['notifications.view']))
        ->test(NotificationLogIndex::class)
        ->assertSee('Skipped')
        ->assertSee('No SMS provider is configured yet.');
});

test('a failed delivery can be put back on the queue', function () {
    Queue::fake();

    $log = NotificationLog::query()->create([
        'event' => 'user.joined',
        'channel' => NotificationChannel::InApp->value,
        'recipient_type' => RecipientType::Admin->value,
        'status' => NotificationStatus::Failed->value,
        'error' => 'Something went wrong',
    ]);

    Livewire::actingAs(screenUser(['notifications.view', 'notifications.update']))
        ->test(NotificationLogIndex::class)
        ->call('retry', $log->id)
        ->assertDispatched('notification-retried');

    expect($log->fresh()->status())->toBe(NotificationStatus::Queued)
        ->and($log->fresh()->error)->toBeNull();

    Queue::assertPushed(SendNotification::class);
});

test('retrying needs the update permission', function () {
    Queue::fake();

    $log = NotificationLog::query()->create([
        'event' => 'user.joined',
        'channel' => NotificationChannel::InApp->value,
        'recipient_type' => RecipientType::Admin->value,
        'status' => NotificationStatus::Failed->value,
    ]);

    Livewire::actingAs(screenUser(['notifications.view']))
        ->test(NotificationLogIndex::class)
        ->call('retry', $log->id)
        ->assertForbidden();

    expect($log->fresh()->status())->toBe(NotificationStatus::Failed);

    Queue::assertNothingPushed();
});

test('something already sent cannot be re-sent through retry', function () {
    Queue::fake();

    $log = NotificationLog::query()->create([
        'event' => 'user.joined',
        'channel' => NotificationChannel::InApp->value,
        'recipient_type' => RecipientType::Admin->value,
        'status' => NotificationStatus::Sent->value,
    ]);

    Livewire::actingAs(screenUser(['notifications.view', 'notifications.update']))
        ->test(NotificationLogIndex::class)
        ->call('retry', $log->id)
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('every failed delivery can be retried at once', function () {
    Queue::fake();

    foreach (range(1, 3) as $index) {
        NotificationLog::query()->create([
            'event' => 'user.joined',
            'channel' => NotificationChannel::InApp->value,
            'recipient_type' => RecipientType::Admin->value,
            'status' => NotificationStatus::Failed->value,
        ]);
    }

    Livewire::actingAs(screenUser(['notifications.view', 'notifications.update']))
        ->test(NotificationLogIndex::class)
        ->call('retryAllFailed');

    expect(NotificationLog::query()->retryable()->count())->toBe(0);

    Queue::assertPushed(SendNotification::class, 3);
});

// -- The bell ------------------------------------------------------------------

test('the bell shows an unread count and the newest entries', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)], [
        'user' => ['name' => 'Dana', 'email' => 'dana@example.test'],
    ]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('Someone joined')
        ->assertSee('accepted their invitation')
        ->assertSee('1 unread');
});

test('the bell is empty and unbadged when there is nothing', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(NotificationBell::class)
        ->assertSee('Nothing yet.')
        ->assertDontSee('unread');
});

test('one entry can be marked read', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)]);

    $id = $user->notifications()->firstOrFail()->id;

    Livewire::actingAs($user)->test(NotificationBell::class)->call('markRead', $id);

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('everything can be marked read at once', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    foreach (range(1, 3) as $index) {
        app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)]);
    }

    expect($user->unreadNotifications()->count())->toBe(3);

    Livewire::actingAs($user)->test(NotificationBell::class)->call('markAllRead');

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('one person cannot mark another person\'s notification read', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $other = User::factory()->create(['email_verified_at' => now()]);

    app(Notifier::class)->send('user.joined', [Recipient::user($owner, RecipientType::Admin)]);

    $id = $owner->notifications()->firstOrFail()->id;

    Livewire::actingAs($other)->test(NotificationBell::class)->call('markRead', $id);

    expect($owner->unreadNotifications()->count())->toBe(1);
});

test('the bell only ever shows the signed-in person their own notifications', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $other = User::factory()->create(['email_verified_at' => now()]);

    app(Notifier::class)->send('user.joined', [Recipient::user($owner, RecipientType::Admin)], [
        'user' => ['name' => 'Private Matter', 'email' => 'p@example.test'],
    ]);

    Livewire::actingAs($other)
        ->test(NotificationBell::class)
        ->assertDontSee('Private Matter')
        ->assertSee('Nothing yet.');
});

test('the bell appears in the app shell', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('aria-label="Notifications"', false);
});

// -- Personal preferences ------------------------------------------------------

test('the preferences panel lists every channel', function () {
    $component = Livewire::actingAs(User::factory()->create())->test(NotificationPreferencesPanel::class);

    foreach (NotificationChannel::cases() as $channel) {
        $component->assertSee($channel->label());
    }
});

test('muting a channel from the profile stops delivery', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(NotificationPreferencesPanel::class)
        ->call('toggleChannel', NotificationChannel::InApp->value)
        ->assertDispatched('preferences-saved');

    expect(app(UserNotificationPreferences::class)->channelIsMuted($user, NotificationChannel::InApp))->toBeTrue();

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)]);

    expect($user->notifications()->count())->toBe(0);
});

test('a muted channel can be turned back on', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $component = Livewire::actingAs($user)->test(NotificationPreferencesPanel::class);

    $component->call('toggleChannel', NotificationChannel::InApp->value);
    $component->call('toggleChannel', NotificationChannel::InApp->value);

    expect(app(UserNotificationPreferences::class)->channelIsMuted($user, NotificationChannel::InApp))->toBeFalse();
});

test('a single event can be muted without silencing the channel', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(NotificationPreferencesPanel::class)
        ->call('toggleEvent', 'user.joined', NotificationChannel::InApp->value);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)]);
    app(Notifier::class)->send('user.removed', [Recipient::user($user, RecipientType::Admin)]);

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->firstOrFail()->data['type'])->toBe('user.removed');
});

test('the panel only offers events an administrator has switched on', function () {
    app(NotificationMatrix::class)->set('user.joined', RecipientType::Admin, NotificationChannel::InApp, false);

    Livewire::actingAs(User::factory()->create())
        ->test(NotificationPreferencesPanel::class)
        ->assertSee('User invited')
        ->assertDontSee('Invitation accepted');
});

test('an unknown channel from a tampered payload changes nothing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationPreferencesPanel::class)
        ->call('toggleChannel', 'carrier-pigeon')
        ->call('toggleEvent', 'user.joined', 'carrier-pigeon');

    expect(NotificationPreference::query()->count())->toBe(0);
});

test('the preferences panel is on the profile page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile'))
        ->assertOk()
        ->assertSee('Notifications')
        ->assertSee('these settings only ever quieten things down');
});
