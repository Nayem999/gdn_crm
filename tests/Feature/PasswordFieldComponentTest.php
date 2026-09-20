<?php

use App\Models\User;

test('the password field renders a masked input with a visibility toggle', function () {
    $view = $this->blade('<x-form.password id="password" name="password" />');

    $view->assertSee('type="password"', escape: false)
        ->assertSee('onclick="togglePasswordField(this)"', escape: false)
        ->assertSee('aria-label="Show password"', escape: false)
        ->assertSee('aria-pressed="false"', escape: false)
        ->assertSee('data-password-icon="show"', escape: false)
        ->assertSee('data-password-icon="hide"', escape: false);
});

test('the toggle does not depend on Alpine being booted', function () {
    // A dead Alpine (bad asset path, blocked script, stale bundle) would leave an
    // x-on:click button silently inert, which is the failure this replaced.
    $html = (string) $this->blade('<x-form.password id="password" name="password" />');

    expect($html)->not->toMatch('/x-(data|show|bind|on|cloak|ref)/');
});

test('the field does not carry its own copy of the handler', function () {
    // It used to publish it with `@once @push('scripts')`, which reaches the
    // page only when the field is in the first render. On a screen that keeps
    // its password fields behind a button — /settings/meta/connect does — the
    // markup arrived by Livewire and the handler never did, so the eye button
    // silently did nothing. The layout ships it instead.
    $html = (string) $this->blade('<x-form.password id="password" name="password" />');

    expect($html)->not->toContain('<script');
});

test('a page ships the handler even with no password field in it', function () {
    $html = (string) $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->getContent();

    // The regression: a field revealed later by Livewire has no way to bring
    // its own handler with it, so the page has to have it already.
    expect($html)->toContain('window.togglePasswordField')
        ->and(substr_count($html, 'window.togglePasswordField'))->toBe(1);
});

test('the password field forwards attributes to the underlying input', function () {
    $view = $this->blade(
        '<x-form.password id="password_confirmation" name="password_confirmation" autocomplete="new-password" required />'
    );

    $view->assertSee('id="password_confirmation"', escape: false)
        ->assertSee('name="password_confirmation"', escape: false)
        ->assertSee('autocomplete="new-password"', escape: false)
        ->assertSee('required', escape: false);
});

test('the password field reserves room for the toggle so the icon never overlaps the text', function () {
    $this->blade('<x-form.password id="password" name="password" />')
        ->assertSee('pr-11', escape: false);
});

test('the toggle icons do not swallow the click meant for the button', function () {
    // Without pointer-events-none the click target is the svg, not the button.
    $this->blade('<x-form.password id="password" name="password" />')
        ->assertSee('pointer-events-none', escape: false);
});

test('the password field marks the input invalid when validation failed', function () {
    $this->blade('<x-form.password id="password" name="password" :invalid="true" />')
        ->assertSee('aria-invalid="true"', escape: false)
        ->assertSee('border-destructive', escape: false);
});

test('the login screen ships a working password visibility toggle', function () {
    $response = $this->get(route('login'))->assertSuccessful();

    $response->assertSee('aria-label="Show password"', escape: false)
        // The toggle helper has to reach the page, or the button does nothing.
        ->assertSee('window.togglePasswordField', escape: false);
});

test('both password fields on the registration screen get their own toggle', function () {
    $content = $this->get(route('register'))->assertSuccessful()->getContent();

    expect(substr_count($content, 'aria-label="Show password"'))->toBe(2)
        // The layout ships the helper once, however many fields use it.
        ->and(substr_count($content, 'window.togglePasswordField'))->toBe(1);
});

test('the reset password screen ships a working password visibility toggle', function () {
    $content = $this->get(route('password.reset', ['token' => 'test-token']))
        ->assertSuccessful()
        ->getContent();

    expect(substr_count($content, 'aria-label="Show password"'))->toBe(2)
        ->and($content)->toContain('window.togglePasswordField');
});

test('the confirm password screen ships a working password visibility toggle', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertSuccessful()
        ->assertSee('aria-label="Show password"', escape: false)
        ->assertSee('window.togglePasswordField', escape: false);
});
