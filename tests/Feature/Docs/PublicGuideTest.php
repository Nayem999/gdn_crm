<?php

use App\Models\User;

/**
 * The documentation page a stranger can read.
 *
 * What matters here is that it opens without a session, that it is the
 * repository's own markdown rather than a second copy, and that the contents
 * list actually lands somewhere — a table of contents pointing at ids that do
 * not exist is worse than none.
 */
test('anybody can read the guide without signing in', function () {
    $this->get(route('guide'))
        ->assertOk()
        ->assertSee('User Guide')
        ->assertSee('Administrator Guide');
});

test('it stays public on an installation that already has accounts', function () {
    // The wizard's route shuts once there is a user; this one must not.
    User::factory()->create();

    $this->assertGuest();

    $this->get(route('guide'))->assertOk();
});

test('it renders the markdown rather than printing it', function () {
    $response = $this->get(route('guide'));

    $response->assertOk()
        // A heading from each guide, as HTML.
        ->assertSee('<h2 id="user-1-signing-in">', false)
        ->assertSee('<h2 id="admin-2-installing">', false)
        // Tables survive the conversion, and each gets its own scroller.
        ->assertSee('<div class="table-scroll"><table>', false)
        // And nothing is left as literal markdown.
        ->assertDontSee('## 1. Signing in');
});

test('every contents link points at a heading that exists', function () {
    $html = $this->get(route('guide'))->assertOk()->getContent();

    preg_match_all('/href="#([a-z0-9-]+)"/', $html, $links);
    preg_match_all('/id="([a-z0-9-]+)"/', $html, $ids);

    $dangling = array_values(array_diff(array_unique($links[1]), $ids[1]));

    expect($dangling)->toBe([], 'Contents links with no heading: '.implode(', ', $dangling));
});

test('the guides cross-link to each other on the page rather than to a file', function () {
    // The markdown links to ADMIN_GUIDE.md, which is a file path, not a URL a
    // browser can follow from here.
    $this->get(route('guide'))
        ->assertOk()
        ->assertDontSee('href="ADMIN_GUIDE.md"', false)
        ->assertSee('href="#admin"', false);
});

test('raw HTML in the markdown is stripped rather than rendered', function () {
    // The page reads files off disk on a public route, so the conversion must
    // not be a way to put arbitrary HTML on it.
    $guide = base_path('docs/USER_GUIDE.md');
    $original = (string) file_get_contents($guide);

    try {
        file_put_contents($guide, $original."\n\n<script>alert('x')</script>\n");

        $this->get(route('guide'))
            ->assertOk()
            ->assertDontSee('<script>alert', false);
    } finally {
        file_put_contents($guide, $original);
    }
});

// -- The diagram ---------------------------------------------------------------

test('the page ends with the workflow diagram', function () {
    $html = $this->get(route('guide'))->assertOk()->getContent();

    expect($html)->toContain('id="workflow"')
        ->toContain('<svg')
        // Described for a screen reader, not only drawn.
        ->toContain('role="img"')
        ->toContain('The CRM workflow');

    // It is the last section on the page.
    expect(strrpos($html, 'id="workflow"'))->toBeGreaterThan((int) strrpos($html, 'id="admin"'));
});

test('the diagram names every stage of the flow it claims to show', function (string $label) {
    $this->get(route('guide'))->assertOk()->assertSee($label, false);
})->with([
    'lead' => ['Lead'],
    'account' => ['Account'],
    'contact' => ['Contact'],
    'deal' => ['Deal'],
    'pipeline' => ['DEAL PIPELINE'],
    'won or lost' => ['Won / Lost'],
    'quote' => ['Quote'],
    'order' => ['Sales order'],
    'invoice' => ['Invoice'],
    'payment' => ['Payment'],
    'support' => ['Ticket raised'],
]);

test('the diagram scrolls on a narrow screen rather than stretching the page', function () {
    // A fixed viewBox in a page that cannot scroll sideways is how a diagram
    // becomes unreadable on a phone.
    $this->get(route('guide'))
        ->assertOk()
        ->assertSee('overflow-x-auto', false)
        ->assertSee('viewBox="0 0 980 800"', false);
});
