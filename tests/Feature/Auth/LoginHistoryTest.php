<?php

use App\Domain\Auth\Enums\LoginEvent;
use App\Domain\Auth\Models\LoginHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('a successful sign-in is recorded with the request context', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeaders(['User-Agent' => 'PestBrowser/1.0'])
        ->post(route('login'), [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ]);

    $entry = LoginHistory::query()->sole();

    expect($entry->event)->toBe(LoginEvent::Login)
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->email)->toBe($user->email)
        ->and($entry->ip_address)->toBe('203.0.113.7')
        ->and($entry->user_agent)->toBe('PestBrowser/1.0');
});

test('a failed sign-in against a known account is recorded against that user', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);

    $entry = LoginHistory::query()->sole();

    expect($entry->event)->toBe(LoginEvent::Failed)
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->email)->toBe($user->email);
});

test('a failed sign-in for an unknown email is recorded without a user', function () {
    $this->post(route('login'), ['email' => 'nobody@example.com', 'password' => 'wrong-password']);

    $entry = LoginHistory::query()->sole();

    expect($entry->event)->toBe(LoginEvent::Failed)
        ->and($entry->user_id)->toBeNull()
        ->and($entry->email)->toBe('nobody@example.com');
});

test('signing out is recorded', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'));

    $entry = LoginHistory::query()->where('event', LoginEvent::Logout)->sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->email)->toBe($user->email);
});

test('a user exposes their own login history, most recent first', function () {
    $user = User::factory()->create();

    LoginHistory::factory()->for($user)->create(['created_at' => now()->subDay()]);
    $latest = LoginHistory::factory()->for($user)->create(['created_at' => now()]);
    LoginHistory::factory()->failed()->create();

    expect($user->loginHistories)->toHaveCount(2)
        ->and($user->loginHistories->first()->id)->toBe($latest->id);
});

test('the login event enum exposes a label and colour for every case', function (LoginEvent $event) {
    expect($event->label())->not->toBeEmpty()
        ->and($event->color())->not->toBeEmpty();
})->with(LoginEvent::cases());
