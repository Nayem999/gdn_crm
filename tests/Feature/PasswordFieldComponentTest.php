<?php

use App\Models\User;

test('the password field renders a masked input with a visibility toggle', function () {
    $view = $this->blade('<x-form.password id="password" name="password" />');

    $view->assertSee('type="password"', escape: false)
        ->assertSee('x-data="{ show: false }"', escape: false)
        ->assertSee('aria-label="Show password"', escape: false)
        // The handler assigns the type on the element directly, so revealing the
        // password never waits on Alpine flushing an attribute binding.
        ->assertSee('x-ref="input"', escape: false)
        ->assertSee("\$refs.input.type = show ? 'text' : 'password'", escape: false);
});

test('the toggle icons do not swallow the click meant for the button', function () {
    // Without pointer-events-none the click target is the svg, not the button.
    $this->blade('<x-form.password id="password" name="password" />')
        ->assertSee('pointer-events-none', escape: false);
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

test('the password field marks the input invalid when validation failed', function () {
    $this->blade('<x-form.password id="password" name="password" :invalid="true" />')
        ->assertSee('aria-invalid="true"', escape: false)
        ->assertSee('border-destructive', escape: false);
});

test('the login screen ships a password visibility toggle', function () {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('aria-label="Show password"', escape: false);
});

test('both password fields on the registration screen get their own toggle', function () {
    $response = $this->get(route('register'))->assertSuccessful();

    expect(substr_count($response->getContent(), 'aria-label="Show password"'))->toBe(2);
});

test('the confirm password screen ships a password visibility toggle', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertSuccessful()
        ->assertSee('aria-label="Show password"', escape: false);
});
