<?php

test('the dashboard renders the app shell with sidebar and topbar', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('Dashboard', escape: false);
    $response->assertSeeInOrder(['aria-label="Primary"', 'Search leads, contacts, deals'], escape: false);
});

test('the app shell exposes a working dark mode toggle', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('Toggle dark mode', escape: false);
    $response->assertSee("classList.toggle('dark')", escape: false);
    $response->assertSee("localStorage.setItem('theme'", escape: false);
    $response->assertSee('prefersDark', escape: false);
});

test('the sidebar lists every core module with an icon', function () {
    $response = $this->get('/');

    $response->assertSuccessful();

    foreach (['Leads', 'Contacts', 'Accounts', 'Deals', 'Activities', 'Products', 'Quotes & Invoices', 'Support', 'Reports', 'Automation', 'Settings'] as $module) {
        $response->assertSee($module);
    }
});

test('the layout loads the compiled tailwind stylesheet and the livewire/alpine script bundle', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('/build/assets/app-', escape: false);
    $response->assertSee('/livewire/livewire.js', escape: false);
});
