<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Api\ApiModules;
use App\Domain\Api\Documentation\OpenApiDocument;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Webhooks\WebhookEvents;
use App\Livewire\Settings\ApiDocumentation;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function docsUser(array $permissions = ['api.tokens']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

function spec(): array
{
    return app(OpenApiDocument::class)->build();
}

// -- It builds, and it builds something valid --------------------------------------

it('builds without errors and writes a file', function () {
    $path = storage_path('app/testing/openapi.json');

    File::delete($path);

    $this->artisan('api:docs', ['--path' => $path])
        ->expectsOutputToContain('Wrote')
        ->assertSuccessful();

    expect(File::exists($path))->toBeTrue();

    $written = json_decode((string) File::get($path), true);

    expect($written)->toBeArray()
        ->and($written['openapi'])->toBe('3.1.0');

    File::delete($path);
});

it('describes itself as OpenAPI with the sections a reader needs', function () {
    $document = spec();

    expect($document)->toHaveKeys(['openapi', 'info', 'servers', 'security', 'components', 'paths', 'webhooks'])
        ->and($document['info']['title'])->toContain(config('app.name'))
        ->and($document['components']['securitySchemes']['bearerAuth']['scheme'])->toBe('bearer');
});

it('documents every collection the API actually exposes, and nothing else', function () {
    $paths = array_keys(spec()['paths']);

    foreach (ApiModules::keys() as $module) {
        expect($paths)->toContain('/'.$module, '/'.$module.'/{id}');
    }

    // /me plus a list and an item path for each module. A path for a module the
    // registry does not expose would be a promise nothing keeps.
    expect($paths)->toHaveCount(1 + (count(ApiModules::keys()) * 2));
});

it('documents every method the routes actually serve', function () {
    $contacts = spec()['paths']['/contacts'];
    $contact = spec()['paths']['/contacts/{id}'];

    expect(array_keys($contacts))->toBe(['get', 'post'])
        ->and(array_keys($contact))->toBe(['parameters', 'get', 'patch', 'delete']);
});

// -- The declaration cannot drift from the thing it describes ------------------------

it('declares exactly the fields a real record comes back with', function () {
    // The guard that makes generated documentation trustworthy: the schema is
    // hand-declared, so something has to hold it against the real output.
    $records = [
        'contacts' => fn () => Contact::factory()->create(),
        'accounts' => fn () => Account::factory()->create(),
        'leads' => fn () => Lead::factory()->create(),
        'deals' => fn () => Deal::factory()->create(),
    ];

    foreach ($records as $key => $make) {
        $module = ApiModules::find($key);

        expect(array_keys($module->toArray($make())))
            ->toBe(array_keys($module->schema()), "{$key} schema does not match its response");
    }
});

it('takes the request fields from the rules the controller enforces', function () {
    $input = spec()['components']['schemas']['ContactInput'];

    expect($input['required'])->toBe(['first_name', 'last_name'])
        ->and(array_keys($input['properties']))->toBe(array_keys(ApiModules::find('contacts')->rules(creating: true)))
        // Types come from the rules, so an integer field is documented as one.
        ->and($input['properties']['account_id']['type'])->toContain('integer')
        ->and($input['properties']['email']['format'])->toBe('email');
});

it('describes the rate limit that is actually configured', function () {
    settings()->set('api.rate_limit_per_minute', 120);

    expect(spec()['info']['description'])->toContain('120 requests per minute');
});

// -- Webhooks ---------------------------------------------------------------------------

it('documents every webhook event, with how to verify one', function () {
    $webhooks = spec()['webhooks'];

    expect(array_keys($webhooks))->toBe(WebhookEvents::all());

    $description = $webhooks['contacts.created']['post']['description'];

    expect($description)->toContain('X-CRM-Signature')
        ->toContain('HMAC-SHA256')
        // The replay warning is the part an integrator most needs and most
        // often is not told.
        ->toContain('replayed');

    // The payload's data is the same shape the API returns, not a second one.
    expect($webhooks['contacts.created']['post']['requestBody']['content']['application/json']['schema']['properties']['data'])
        ->toBe(['$ref' => '#/components/schemas/Contact']);
});

// -- Serving it ---------------------------------------------------------------------------

it('serves the description to a key holder and nobody else', function () {
    $this->getJson('/api/v1/openapi.json')->assertUnauthorized();

    $key = docsUser()->createToken('Docs', ['read'])->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$key)
        ->getJson('/api/v1/openapi.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0');
});

it('renders the readable page from the same document', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.api-docs'))
        ->assertForbidden();

    Livewire::actingAs(docsUser())
        ->test(ApiDocumentation::class)
        ->assertSee('/contacts')
        ->assertSee('Authorization: Bearer')
        ->assertSee('contacts.created')
        ->assertSee('X-CRM-Signature');
});
