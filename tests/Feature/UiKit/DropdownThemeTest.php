<?php

/**
 * Tom Select ships a light theme with hard-coded colours, and some of its
 * selectors are more specific than a single class. An override written at lower
 * specificity loses silently — that is how an open dropdown came to paint white
 * text on a white control in dark mode. These tests pin the overrides that fix
 * it so a stylesheet tidy-up cannot quietly reintroduce the bug.
 */
function appStylesheet(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/app.css');
}

function vendorTomSelectStylesheet(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/node_modules/tom-select/dist/css/tom-select.css');
}

/**
 * Class-count stands in for specificity here: none of these selectors carry an
 * id or an inline style, so the class column is the only one that moves.
 */
function classCount(string $selector): int
{
    return preg_match_all('/\.[a-zA-Z][\w-]*/', $selector);
}

test('each vendor rule that hard-codes a light colour has an override that outranks it', function (
    string $vendorSelector,
    string $ourSelector
) {
    expect(vendorTomSelectStylesheet())->toContain($vendorSelector)
        ->and(appStylesheet())->toContain($ourSelector)
        ->and(classCount($ourSelector))->toBeGreaterThanOrEqual(classCount($vendorSelector));
})->with([
    // The one that broke: three classes deep, so `.ts-wrapper .ts-control` lost.
    ['.ts-wrapper.single.input-active .ts-control', '.ts-wrapper.single.input-active .ts-control'],
    // Applied whenever every option has been taken, or the field is disabled.
    ['.full .ts-control', '.ts-wrapper.full .ts-control'],
    ['.disabled .ts-control', '.ts-wrapper.disabled .ts-control'],
    // Chips in a multi select, and the divider on their remove button.
    ['.ts-wrapper.multi .ts-control > div', '.ts-wrapper.multi .ts-control > div'],
    ['.ts-wrapper.multi .ts-control > div.active', '.ts-wrapper.multi .ts-control > div.active'],
    ['.ts-wrapper.plugin-remove_button:not(.rtl) .item .remove', '.ts-wrapper.plugin-remove_button:not(.rtl) .item .remove'],
    ['.ts-wrapper.plugin-remove_button.rtl .item .remove', '.ts-wrapper.plugin-remove_button.rtl .item .remove'],
    // Inside the open dropdown.
    ['.ts-dropdown .optgroup-header', '.ts-dropdown .optgroup-header'],
    ['.ts-dropdown .active.create', '.ts-dropdown .active.create'],
]);

test('the dropdown styles read colours from theme tokens, never a literal', function () {
    $rules = [];

    preg_match_all('/^(\.ts-[^{]*)\{([^}]*)\}/m', appStylesheet(), $matches, PREG_SET_ORDER);

    foreach ($matches as [, $selector, $body]) {
        if (preg_match('/#[0-9a-fA-F]{3,8}\b|\brgba?\(/', $body) === 1) {
            $rules[] = trim($selector);
        }
    }

    expect($rules)->toBeEmpty();
});
