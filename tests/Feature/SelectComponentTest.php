<?php

test('the select component renders options and marks the selected value', function () {
    $view = $this->blade(
        <<<'BLADE'
            <x-select name="fruit" label="Fruit" :options="$options" :selected="$selected" />
        BLADE,
        ['options' => ['apple' => 'Apple', 'banana' => 'Banana'], 'selected' => 'banana']
    );

    $view->assertSee('Fruit')
        ->assertSee('Apple')
        ->assertSee('Banana')
        ->assertSeeInOrder(['value="banana"', 'selected'])
        ->assertSee('x-data="tomSelectField', escape: false);
});

test('the select component forwards wire:model to the underlying select element', function () {
    $view = $this->blade(
        <<<'BLADE'
            <x-select name="fruit" :options="$options" wire:model="fruit" />
        BLADE,
        ['options' => ['apple' => 'Apple']]
    );

    $view->assertSee('wire:model="fruit"', escape: false);
});

test('the select component supports multiple selection', function () {
    $view = $this->blade(
        <<<'BLADE'
            <x-select name="fruits" :options="$options" :selected="$selected" multiple />
        BLADE,
        ['options' => ['apple' => 'Apple', 'banana' => 'Banana'], 'selected' => ['apple', 'banana']]
    );

    $view->assertSee('multiple', escape: false)
        ->assertSee('name="fruits[]"', escape: false);
});
