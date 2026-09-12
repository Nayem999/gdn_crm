<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Notifications\ChannelManager;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Models\NotificationSetting;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Domain\Notifications\NotificationMatrix;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Recipient;
use App\Domain\Notifications\TemplateRenderer;
use App\Domain\Notifications\UserNotificationPreferences;
use App\Domain\Settings\SettingsManager;
use App\Jobs\SendNotification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\RecordingChannelDriver;

/**
 * @param  array<int, string>  $permissions
 */
function notifyUser(array $permissions = []): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * Swap in a recording driver for a channel and hand it back.
 */
function recordChannel(NotificationChannel $channel, bool $configured = true, ?string $failWith = null): RecordingChannelDriver
{
    $driver = new RecordingChannelDriver($channel, $configured, $failWith);

    app(ChannelManager::class)->extend($channel, $driver);

    return $driver;
}

/**
 * The suite runs on the sync queue driver (phpunit.xml), so dispatching a
 * notification runs its job there and then. Tests that care about *queueing*
 * rather than delivery use Queue::fake() explicitly.
 */
beforeEach(function () {
    Cache::flush();
    app(SettingsManager::class)->flush();
    app(NotificationMatrix::class)->flush();
});

// -- Dispatch ------------------------------------------------------------------

test('an event the registry does not know is not dispatched', function () {
    Queue::fake();

    $sent = app(Notifier::class)->send('made.up.event', [
        Recipient::user(notifyUser(), RecipientType::Admin),
    ]);

    expect($sent)->toBe(0)
        ->and(NotificationLog::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('a dispatch queues rather than sending inline', function () {
    Queue::fake();

    $sent = app(Notifier::class)->send('user.joined', [
        Recipient::user(notifyUser(), RecipientType::Admin),
    ], ['user' => ['name' => 'Dana', 'email' => 'dana@example.test']]);

    expect($sent)->toBe(1);

    Queue::assertPushed(SendNotification::class, 1);

    expect(NotificationLog::query()->first()->status())->toBe(NotificationStatus::Queued);
});

test('each channel the matrix has on gets its own delivery', function () {
    $inApp = recordChannel(NotificationChannel::InApp);
    $email = recordChannel(NotificationChannel::Email);
    $sms = recordChannel(NotificationChannel::Sms);
    $whatsApp = recordChannel(NotificationChannel::WhatsApp);

    $matrix = app(NotificationMatrix::class);

    foreach (NotificationChannel::cases() as $channel) {
        $matrix->set('user.joined', RecipientType::Admin, $channel, true);
    }

    app(Notifier::class)->send('user.joined', [
        Recipient::user(notifyUser(), RecipientType::Admin),
    ], ['user' => ['name' => 'Dana', 'email' => 'dana@example.test']]);

    expect($inApp->count())->toBe(1)
        ->and($email->count())->toBe(1)
        ->and($sms->count())->toBe(1)
        ->and($whatsApp->count())->toBe(1)
        ->and(NotificationLog::query()->where('status', NotificationStatus::Sent->value)->count())->toBe(4);
});

test('a recipient is only told once even if they turn up twice', function () {
    $inApp = recordChannel(NotificationChannel::InApp);
    $user = notifyUser();

    app(Notifier::class)->send('user.joined', [
        Recipient::user($user, RecipientType::Admin),
        Recipient::user($user, RecipientType::Admin),
    ]);

    expect($inApp->count())->toBe(1);
});

test('nobody is notified about something they did themselves', function () {
    $inApp = recordChannel(NotificationChannel::InApp);
    $actor = notifyUser();
    $other = notifyUser();

    app(Notifier::class)->send('user.joined', [
        Recipient::user($actor, RecipientType::Admin),
        Recipient::user($other, RecipientType::Admin),
    ], [], actor: $actor);

    expect($inApp->count())->toBe(1)
        ->and($inApp->sent[0]->user->id)->toBe($other->id);
});

// -- The matrix ----------------------------------------------------------------

test('a channel switched off in the matrix does not dispatch', function () {
    $inApp = recordChannel(NotificationChannel::InApp);

    app(NotificationMatrix::class)->set('user.joined', RecipientType::Admin, NotificationChannel::InApp, false);

    app(Notifier::class)->send('user.joined', [
        Recipient::user(notifyUser(), RecipientType::Admin),
    ]);

    expect($inApp->count())->toBe(0)
        ->and(NotificationLog::query()->count())->toBe(0);
});

test('the matrix only stores a cell that differs from the default', function () {
    $matrix = app(NotificationMatrix::class);

    // user.joined defaults to in-app on, email off.
    $matrix->set('user.joined', RecipientType::Admin, NotificationChannel::InApp, true);
    expect(NotificationSetting::query()->count())->toBe(0);

    $matrix->set('user.joined', RecipientType::Admin, NotificationChannel::InApp, false);
    expect(NotificationSetting::query()->count())->toBe(1);

    // Setting it back to the default clears the row again.
    $matrix->set('user.joined', RecipientType::Admin, NotificationChannel::InApp, true);
    expect(NotificationSetting::query()->count())->toBe(0);
});

test('the matrix refuses a recipient type the event does not have', function () {
    $matrix = app(NotificationMatrix::class);

    expect($matrix->set('user.joined', RecipientType::Customer, NotificationChannel::InApp, true))->toBeFalse()
        ->and($matrix->isEnabled('user.joined', RecipientType::Customer, NotificationChannel::InApp))->toBeFalse();
});

test('the matrix refuses an event the registry does not know', function () {
    expect(app(NotificationMatrix::class)->set('made.up', RecipientType::Admin, NotificationChannel::InApp, true))
        ->toBeFalse();
});

test('toggling flips a cell and survives a fresh matrix', function () {
    $matrix = app(NotificationMatrix::class);

    $matrix->toggle('user.joined', RecipientType::Admin, NotificationChannel::Email);

    expect($matrix->isEnabled('user.joined', RecipientType::Admin, NotificationChannel::Email))->toBeTrue()
        ->and((new NotificationMatrix)->isEnabled('user.joined', RecipientType::Admin, NotificationChannel::Email))
        ->toBeTrue();
});

// -- Per-user overrides --------------------------------------------------------

test('a muted channel wins over the matrix', function () {
    $inApp = recordChannel(NotificationChannel::InApp);
    $user = notifyUser();

    app(UserNotificationPreferences::class)->setChannel($user, NotificationChannel::InApp, false);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)]);

    expect($inApp->count())->toBe(0);
});

test('an event-level mute is more precise than the channel-wide one', function () {
    $inApp = recordChannel(NotificationChannel::InApp);
    $user = notifyUser();
    $preferences = app(UserNotificationPreferences::class);

    $preferences->setChannel($user, NotificationChannel::InApp, false);
    $preferences->setEvent($user, 'user.joined', NotificationChannel::InApp, true);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)]);
    app(Notifier::class)->send('user.removed', [Recipient::user($user, RecipientType::Admin)]);

    expect($inApp->count())->toBe(1)
        ->and($inApp->sent[0]->event)->toBe('user.joined');
});

test('unmuting removes the row rather than storing a positive override', function () {
    $user = notifyUser();
    $preferences = app(UserNotificationPreferences::class);

    $preferences->setChannel($user, NotificationChannel::Email, false);
    expect(NotificationPreference::query()->count())->toBe(1);

    $preferences->setChannel($user, NotificationChannel::Email, true);
    expect(NotificationPreference::query()->count())->toBe(0);
});

test('a preference cannot switch on what the matrix has turned off', function () {
    $inApp = recordChannel(NotificationChannel::InApp);
    $user = notifyUser();

    app(NotificationMatrix::class)->set('user.joined', RecipientType::Admin, NotificationChannel::InApp, false);
    app(UserNotificationPreferences::class)->setEvent($user, 'user.joined', NotificationChannel::InApp, true);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)]);

    expect($inApp->count())->toBe(0);
});

test('a preference for an unregistered event is refused', function () {
    expect(app(UserNotificationPreferences::class)
        ->setEvent(notifyUser(), 'made.up', NotificationChannel::InApp, false))
        ->toBeFalse();
});

// -- Unconfigured channels -----------------------------------------------------

test('an unconfigured channel is skipped with a reason, not attempted', function () {
    recordChannel(NotificationChannel::Email, configured: false);

    app(NotificationMatrix::class)->set('user.joined', RecipientType::Admin, NotificationChannel::Email, true);

    app(Notifier::class)->send('user.joined', [Recipient::user(notifyUser(), RecipientType::Admin)]);

    $log = NotificationLog::query()->where('channel', NotificationChannel::Email->value)->firstOrFail();

    expect($log->status())->toBe(NotificationStatus::Skipped)
        ->and($log->error)->toContain('Not configured');
});

test('sms reports what is missing once a real provider is chosen', function () {
    // Until 7.6 these two were stubs that reported themselves unconfigured
    // outright. They now have providers, and "not configured" means the chosen
    // provider is missing a credential — which is a far more useful sentence
    // than "no SMS provider yet".
    settings()->set('sms.provider', 'twilio');

    $manager = app(ChannelManager::class);

    expect($manager->driver(NotificationChannel::Sms)->isConfigured())->toBeFalse()
        ->and($manager->driver(NotificationChannel::Sms)->unavailableReason())->toContain('Twilio needs');
});

test('every channel is configured out of the box, because the default is one that logs', function () {
    // Each channel's default provider writes to the application log. That is a
    // real delivery of a kind — it does what it says — so the engine does not
    // skip these channels on a fresh install and silently lose messages.
    expect(app(ChannelManager::class)->configuredChannels())
        ->toEqualCanonicalizing(NotificationChannel::cases());
});

// -- Failure and retry ---------------------------------------------------------

test('a delivery failure is logged with its reason and stays retryable', function () {
    recordChannel(NotificationChannel::InApp, failWith: 'The provider refused the message.');

    // The job rethrows so the queue retries it; on the sync driver that surfaces
    // here, at the dispatch call.
    $thrown = null;

    try {
        app(Notifier::class)->send('user.joined', [Recipient::user(notifyUser(), RecipientType::Admin)]);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);

    $log = NotificationLog::query()->firstOrFail();

    $log->refresh();

    expect($log->status())->toBe(NotificationStatus::Failed)
        ->and($log->error)->toContain('provider refused')
        ->and($log->attempts)->toBe(1)
        ->and($log->status()->isRetryable())->toBeTrue()
        ->and(NotificationLog::query()->retryable()->count())->toBe(1);
});

test('a retry after the problem clears marks the same log sent', function () {
    $driver = recordChannel(NotificationChannel::InApp, failWith: 'Temporarily unavailable.');

    try {
        app(Notifier::class)->send('user.joined', [Recipient::user(notifyUser(), RecipientType::Admin)]);
    } catch (Throwable) {
        // Expected on the first attempt.
    }

    $log = NotificationLog::query()->firstOrFail();

    $driver->failWith = null;

    app()->call([new SendNotification($log->id), 'handle']);

    $log->refresh();

    expect($log->status())->toBe(NotificationStatus::Sent)
        ->and($log->attempts)->toBe(2)
        ->and($log->sent_at)->not->toBeNull()
        ->and($log->error)->toBeNull();
});

test('an already sent notification is not sent twice', function () {
    $driver = recordChannel(NotificationChannel::InApp);

    app(Notifier::class)->send('user.joined', [Recipient::user(notifyUser(), RecipientType::Admin)]);

    $log = NotificationLog::query()->firstOrFail();

    app()->call([new SendNotification($log->id), 'handle']);
    app()->call([new SendNotification($log->id), 'handle']);

    expect($driver->count())->toBe(1);
});

test('the job backs off between attempts', function () {
    expect((new SendNotification(1))->backoff())->toBe([30, 120])
        ->and((new SendNotification(1))->tries)->toBe(3);
});

// -- The registry --------------------------------------------------------------

test('every registered event declares a label, group and at least one recipient type', function (string $key) {
    $event = NotificationEventRegistry::find($key);

    expect($event->label)->not->toBeEmpty()
        ->and($event->group)->not->toBeEmpty()
        ->and($event->description)->not->toBeEmpty()
        ->and($event->recipientTypes)->not->toBeEmpty()
        ->and($event->defaultBody(NotificationChannel::InApp))->not->toBeEmpty();
})->with(NotificationEventRegistry::keys());

test('every merge field a default template uses is one the event declares', function (string $key) {
    $event = NotificationEventRegistry::find($key);
    $renderer = app(TemplateRenderer::class);
    $available = NotificationEventRegistry::mergeFieldsFor($key);

    foreach (NotificationChannel::cases() as $channel) {
        expect($renderer->unknownFields($event->defaultBody($channel), $available))
            ->toBe([], "the default {$channel->value} template for {$key} uses an undeclared field");
    }
})->with(NotificationEventRegistry::keys());
