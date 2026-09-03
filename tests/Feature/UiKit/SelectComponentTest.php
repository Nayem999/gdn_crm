<?php

test('the original flat option map still works unchanged', function () {
    $this->blade(
        '<x-select name="stage" label="Stage" :options="$options" selected="won" required />',
        ['options' => ['new' => 'New', 'won' => 'Won']]
    )
        ->assertSee('Stage')
        ->assertSee('value="new"', false)
        ->assertSee('value="won"', false)
        ->assertSee('selected', false)
        ->assertSee('required', false)
        ->assertSee('wire:ignore', false)
        ->assertSee('tomSelectField', false);
});

test('multiple mode names the field as an array and offers no blank option', function () {
    $rendered = (string) $this->blade(
        '<x-select name="tags" :options="$options" :selected="$selected" multiple />',
        ['options' => ['a' => 'A', 'b' => 'B'], 'selected' => ['a', 'b']]
    );

    expect($rendered)->toContain('name="tags[]"')
        ->and($rendered)->toContain('multiple')
        ->and(substr_count($rendered, 'selected'))->toBe(2)
        ->and($rendered)->not->toContain('<option value=""></option>');
});

test('rich options carry their description and colour to the browser', function () {
    $rendered = (string) $this->blade('<x-select name="owner" :options="$options" />', [
        'options' => [
            ['value' => '7', 'label' => 'Dana Scully', 'description' => 'Sales', 'color' => 'emerald'],
            ['value' => '8', 'label' => 'Fox Mulder', 'disabled' => true],
        ],
    ]);

    expect($rendered)->toContain('Dana Scully')
        // Tom Select only reads extra option data from data-data.
        ->and($rendered)->toContain('data-data=')
        ->and($rendered)->toContain('Sales')
        ->and($rendered)->toContain('emerald')
        ->and($rendered)->toContain('disabled');
});

test('server-side search is handed to the field as a livewire method', function () {
    $this->blade('<x-select name="account" search-method="searchAccounts" :preload="true" />')
        ->assertSee('searchAccounts', false)
        ->assertSee('preload', false);
});

test('create-on-the-fly names the event the page listens for', function () {
    $this->blade('<x-select name="account" create-event="open-account-modal" />')
        ->assertSee('createEvent', false)
        ->assertSee('open-account-modal', false);
});

test('a dependent select names its parent field', function () {
    $this->blade('<x-select name="city" depends-on="country" />')
        ->assertSee('dependsOn', false)
        ->assertSee('country', false);
});

test('a plain select carries none of the optional wiring', function () {
    $rendered = (string) $this->blade('<x-select name="stage" :options="$options" />', [
        'options' => ['new' => 'New'],
    ]);

    expect($rendered)->not->toContain('searchMethod')
        ->and($rendered)->not->toContain('createEvent')
        ->and($rendered)->not->toContain('dependsOn');
});

test('an error message replaces the hint', function () {
    $this->blade('<x-select name="stage" hint="Pick one" error="Stage is required" />')
        ->assertSee('Stage is required')
        ->assertDontSee('Pick one');

    $this->blade('<x-select name="stage" hint="Pick one" />')->assertSee('Pick one');
});

test('option labels and values are escaped rather than injected', function () {
    $rendered = (string) $this->blade('<x-select name="x" :options="$options" />', [
        'options' => ['<script>' => '<script>alert(1)</script>'],
    ]);

    expect($rendered)->not->toContain('<script>alert(1)</script>')
        ->and($rendered)->toContain('&lt;script&gt;');
});
