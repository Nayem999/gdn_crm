<?php

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\NotificationMatrix;
use App\Domain\Notifications\NotificationThrottle;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\QuietHours;
use App\Domain\Notifications\Recipient;
use App\Domain\Notifications\TemplateRenderer;
use App\Domain\Notifications\TemplateResolver;
use App\Domain\Settings\SettingsManager;
use App\Jobs\SendNotification;
use App\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    app(SettingsManager::class)->flush();
    app(NotificationMatrix::class)->flush();
});

// -- Merge fields --------------------------------------------------------------

test('merge fields resolve from nested data', function () {
    $rendered = app(TemplateRenderer::class)->render(
        'Hello {{user.name}}, {{actor.name}} invited you to {{app.name}}.',
        ['user' => ['name' => 'Dana'], 'actor' => ['name' => 'Fox'], 'app' => ['name' => 'GDN CRM']]
    );

    expect($rendered)->toBe('Hello Dana, Fox invited you to GDN CRM.');
});

test('spacing inside the braces does not matter', function () {
    expect(app(TemplateRenderer::class)->render('{{ user.name }} and {{user.name}}', ['user' => ['name' => 'Dana']]))
        ->toBe('Dana and Dana');
});

test('an unknown field renders empty rather than leaving braces in the message', function () {
    expect(app(TemplateRenderer::class)->render('Hello {{nobody.here}}.', []))->toBe('Hello .');
});

test('lists and booleans render readably', function () {
    $renderer = app(TemplateRenderer::class);

    expect($renderer->render('{{keys}}', ['keys' => ['a', 'b']]))->toBe('a, b')
        ->and($renderer->render('{{yes}}/{{no}}', ['yes' => true, 'no' => false]))->toBe('Yes/');
});

test('a template is never treated as code', function () {
    $renderer = app(TemplateRenderer::class);

    // Anything that is not a plain dotted field name is left exactly as typed.
    expect($renderer->render('{{ phpinfo() }}', []))->toBe('{{ phpinfo() }}')
        ->and($renderer->render('{{ $user->password }}', ['user' => ['password' => 'hunter2']]))
        ->toBe('{{ $user->password }}')
        ->and($renderer->render('{{ 7 * 7 }}', []))->toBe('{{ 7 * 7 }}');
});

test('merge data cannot smuggle a second placeholder in', function () {
    // A value that looks like a placeholder is inserted, not re-scanned.
    expect(app(TemplateRenderer::class)->render('{{user.name}}', ['user' => ['name' => '{{app.name}}']]))
        ->toBe('{{app.name}}');
});

test('the fields a template uses can be listed and checked', function () {
    $renderer = app(TemplateRenderer::class);
    $template = 'Hi {{user.name}}, see {{ticket.number}} and {{user.name}} again.';

    expect($renderer->fieldsUsed($template))->toBe(['user.name', 'ticket.number'])
        ->and($renderer->unknownFields($template, ['user.name' => 'Name']))->toBe(['ticket.number']);
});

// -- Templates -----------------------------------------------------------------

test('the registry default is used until an admin writes one', function () {
    $resolver = app(TemplateResolver::class);

    $rendered = $resolver->render('user.joined', NotificationChannel::InApp, [
        'user' => ['name' => 'Dana', 'email' => 'dana@example.test'],
    ]);

    expect($rendered['body'])->toBe('Dana (dana@example.test) accepted their invitation.')
        ->and($rendered['subject'])->toBe('Someone joined');
});

test('a stored template wins over the default', function () {
    NotificationTemplate::query()->create([
        'event' => 'user.joined',
        'channel' => NotificationChannel::InApp->value,
        'subject' => 'Welcome aboard',
        'body' => '{{user.name}} is now with us.',
    ]);

    $rendered = app(TemplateResolver::class)->render('user.joined', NotificationChannel::InApp, [
        'user' => ['name' => 'Dana'],
    ]);

    expect($rendered['body'])->toBe('Dana is now with us.')
        ->and($rendered['subject'])->toBe('Welcome aboard');
});

test('channels without a subject line get none', function () {
    $rendered = app(TemplateResolver::class)->render('user.joined', NotificationChannel::Sms, []);

    expect($rendered['subject'])->toBeNull()
        ->and($rendered['body'])->not->toBeEmpty();
});

test('the rendered subject is recorded on the log', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)], [
        'user' => ['name' => 'Dana', 'email' => 'dana@example.test'],
    ]);

    expect(NotificationLog::query()->firstOrFail()->subject)->toBe('Someone joined');
});

// -- The mail channel ----------------------------------------------------------

test('the email channel actually sends mail with the rendered wording', function () {
    Mail::fake();

    $user = User::factory()->create(['email_verified_at' => now(), 'email' => 'dana@example.test']);

    app(NotificationMatrix::class)->set('user.joined', RecipientType::Admin, NotificationChannel::Email, true);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)], [
        'user' => ['name' => 'Dana', 'email' => 'dana@example.test'],
    ]);

    Mail::assertSent(NotificationMail::class, function ($mail) {
        return $mail->hasTo('dana@example.test')
            && $mail->subject === 'Someone joined'
            && str_contains($mail->body, 'accepted their invitation');
    });
});

test('the in-app channel writes a row the bell can read', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)], [
        'user' => ['name' => 'Dana', 'email' => 'dana@example.test'],
    ]);

    $notification = $user->notifications()->firstOrFail();

    expect($notification->data['type'])->toBe('user.joined')
        ->and($notification->data['subject'])->toBe('Someone joined')
        ->and($notification->data['message'])->toContain('accepted their invitation')
        ->and($notification->read_at)->toBeNull();
});

// -- Quiet hours ---------------------------------------------------------------

test('quiet hours are off until switched on', function () {
    expect(app(QuietHours::class)->isEnabled())->toBeFalse()
        ->and(app(QuietHours::class)->covers(Carbon::parse('2026-01-01 02:00')))->toBeFalse();
});

test('a window that runs through midnight is understood', function () {
    settings()->set('notifications.quiet_hours_enabled', true);
    settings()->set('notifications.quiet_hours_start', '21:00');
    settings()->set('notifications.quiet_hours_end', '07:00');

    $quiet = app(QuietHours::class);

    expect($quiet->covers(Carbon::parse('2026-01-01 22:30')))->toBeTrue()
        ->and($quiet->covers(Carbon::parse('2026-01-01 02:00')))->toBeTrue()
        ->and($quiet->covers(Carbon::parse('2026-01-01 06:59')))->toBeTrue()
        ->and($quiet->covers(Carbon::parse('2026-01-01 12:00')))->toBeFalse();
});

test('a window inside one day is understood too', function () {
    settings()->set('notifications.quiet_hours_enabled', true);
    settings()->set('notifications.quiet_hours_start', '12:00');
    settings()->set('notifications.quiet_hours_end', '14:00');

    $quiet = app(QuietHours::class);

    expect($quiet->covers(Carbon::parse('2026-01-01 13:00')))->toBeTrue()
        ->and($quiet->covers(Carbon::parse('2026-01-01 15:00')))->toBeFalse();
});

test('in-app notifications are never held, only the intrusive channels', function () {
    settings()->set('notifications.quiet_hours_enabled', true);
    settings()->set('notifications.quiet_hours_start', '21:00');
    settings()->set('notifications.quiet_hours_end', '07:00');

    Carbon::setTestNow(Carbon::parse('2026-01-01 23:00'));

    $quiet = app(QuietHours::class);

    expect($quiet->delayFor(NotificationChannel::InApp))->toBe(0)
        ->and($quiet->delayFor(NotificationChannel::Email))->toBeGreaterThan(0)
        // Held until the window ends the next morning, not dropped.
        ->and($quiet->endsAfter()->format('Y-m-d H:i'))->toBe('2026-01-02 07:00');

    Carbon::setTestNow();
});

test('a held notification is delayed rather than dropped', function () {
    Queue::fake();

    settings()->set('notifications.quiet_hours_enabled', true);
    settings()->set('notifications.quiet_hours_start', '21:00');
    settings()->set('notifications.quiet_hours_end', '07:00');

    Carbon::setTestNow(Carbon::parse('2026-01-01 23:00'));

    $user = User::factory()->create(['email_verified_at' => now()]);
    app(NotificationMatrix::class)->set('user.joined', RecipientType::Admin, NotificationChannel::Email, true);

    app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)]);

    // Still queued, and still logged — nothing was thrown away.
    expect(NotificationLog::query()->where('channel', 'email')->count())->toBe(1);

    Queue::assertPushed(SendNotification::class, function (SendNotification $job) {
        return $job->delay > 0;
    });

    Carbon::setTestNow();
});

// -- Rate limiting -------------------------------------------------------------

test('a recipient stops receiving once the hourly ceiling is reached', function () {
    settings()->set('notifications.rate_limit_per_hour', 2);

    $user = User::factory()->create(['email_verified_at' => now()]);

    for ($index = 0; $index < 4; $index++) {
        app(Notifier::class)->send('user.joined', [Recipient::user($user, RecipientType::Admin)]);
    }

    expect(NotificationLog::query()->where('status', NotificationStatus::Sent->value)->count())->toBe(2)
        ->and(NotificationLog::query()->where('status', NotificationStatus::Skipped->value)->count())->toBe(2);

    $skipped = NotificationLog::query()->where('status', NotificationStatus::Skipped->value)->first();

    expect($skipped->error)->toContain('limit');
});

test('the ceiling is per recipient, so one busy person does not silence another', function () {
    settings()->set('notifications.rate_limit_per_hour', 1);

    $busy = User::factory()->create(['email_verified_at' => now()]);
    $quiet = User::factory()->create(['email_verified_at' => now()]);

    app(Notifier::class)->send('user.joined', [Recipient::user($busy, RecipientType::Admin)]);
    app(Notifier::class)->send('user.joined', [Recipient::user($busy, RecipientType::Admin)]);
    app(Notifier::class)->send('user.joined', [Recipient::user($quiet, RecipientType::Admin)]);

    expect(NotificationLog::query()->where('user_id', $busy->id)->where('status', 'sent')->count())->toBe(1)
        ->and(NotificationLog::query()->where('user_id', $busy->id)->where('status', 'skipped')->count())->toBe(1)
        ->and(NotificationLog::query()->where('user_id', $quiet->id)->where('status', 'sent')->count())->toBe(1);
});

test('the ceiling is also per channel', function () {
    $throttle = app(NotificationThrottle::class);
    $user = User::factory()->create();

    settings()->set('notifications.rate_limit_per_hour', 1);

    expect($throttle->attempt($user, null, NotificationChannel::InApp))->toBeTrue()
        ->and($throttle->attempt($user, null, NotificationChannel::InApp))->toBeFalse()
        // A different channel has its own allowance.
        ->and($throttle->attempt($user, null, NotificationChannel::Email))->toBeTrue();
});

test('the configured limit is respected and never drops below one', function () {
    settings()->set('notifications.rate_limit_per_hour', 0);

    expect(app(NotificationThrottle::class)->limit())->toBe(1);
});
