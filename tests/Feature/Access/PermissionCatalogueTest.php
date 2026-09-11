<?php

use App\Domain\Access\PermissionCatalogue;

test('the catalogue has no duplicate permission names', function () {
    $all = PermissionCatalogue::all();

    expect($all)->toBe(array_values(array_unique($all)));
});

test('every catalogued permission is namespaced under its group', function () {
    foreach (PermissionCatalogue::groups() as $key => $group) {
        expect($group['label'])->not->toBeEmpty()
            ->and($group['icon'])->not->toBeEmpty()
            ->and($group['permissions'])->not->toBeEmpty();

        foreach ($group['permissions'] as $permission => $label) {
            expect($permission)->toStartWith($key.'.')
                ->and($label)->not->toBeEmpty();
        }
    }
});

test('only catalogued permissions survive filtering', function () {
    $filtered = PermissionCatalogue::only(['users.view', 'billing.refund', 'users.view', 'teams.delete']);

    expect($filtered)->toEqualCanonicalizing(['users.view', 'teams.delete'])
        ->and(PermissionCatalogue::has('users.view'))->toBeTrue()
        ->and(PermissionCatalogue::has('billing.refund'))->toBeFalse();
});

test('every permission a policy checks is present in the catalogue', function () {
    // Guards against a new module enforcing a permission the roles matrix never
    // offers, which would leave it permanently un-grantable.
    $policyFiles = glob(app_path('Domain/*/Policies/*.php')) ?: [];

    expect($policyFiles)->not->toBeEmpty();

    $checked = [];

    foreach ($policyFiles as $file) {
        preg_match_all("/can\('([^']+)'\)/", (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $permission) {
            $checked[$permission] = basename($file);
        }
    }

    expect($checked)->not->toBeEmpty();

    $missing = [];

    foreach ($checked as $permission => $policy) {
        if (! PermissionCatalogue::has($permission)) {
            $missing[] = $permission.' ('.$policy.')';
        }
    }

    expect($missing)->toBe([], 'These policy permissions are missing from PermissionCatalogue: '.implode(', ', $missing));
});

test('the catalogue covers the modules built so far', function () {
    expect(array_keys(PermissionCatalogue::groups()))
        ->toEqualCanonicalizing(['company', 'users', 'teams', 'roles', 'accounts', 'leads', 'contacts', 'deals', 'activities', 'custom-fields', 'saved-views', 'timeline', 'settings', 'notifications', 'audit']);
});
