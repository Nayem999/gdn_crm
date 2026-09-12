<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Webhooks\Enums\WebhookDeliveryStatus;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookEndpoint;
use App\Domain\Webhooks\WebhookEvents;
use App\Domain\Webhooks\WebhookSignature;
use App\Jobs\DeliverWebhook;
use App\Livewire\Settings\WebhookEndpoints;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function webhookUser(array $permissions = ['api.webhooks', 'contacts.view', 'contacts.create']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

function deliver(WebhookDelivery $delivery): void
{
    (new DeliverWebhook($delivery->id))->handle();
}

// -- What gets sent, and when ------------------------------------------------------

it('tells an endpoint that asked to hear about it', function () {
    Queue::fake();

    $endpoint = WebhookEndpoint::factory()->listeningTo(['contacts.created'])->create();

    $contact = Contact::factory()->create();

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->webhook_endpoint_id)->toBe($endpoint->id)
        ->and($delivery->event)->toBe('contacts.created')
        ->and($delivery->payload['data']['id'])->toBe($contact->id)
        ->and($delivery->payload['event'])->toBe('contacts.created')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending);

    Queue::assertPushed(DeliverWebhook::class);
});

it('says nothing to an endpoint that did not ask', function () {
    Queue::fake();

    WebhookEndpoint::factory()->listeningTo(['deals.created'])->create();

    Contact::factory()->create();

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('says nothing at all while an endpoint is paused', function () {
    Queue::fake();

    WebhookEndpoint::factory()->listeningTo(['contacts.created'])->create(['is_active' => false]);

    Contact::factory()->create();

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('captures the record as it was when the event happened', function () {
    Queue::fake();

    WebhookEndpoint::factory()->listeningTo(['contacts.created'])->create();

    $contact = Contact::factory()->create(['first_name' => 'Priya']);

    // Changed afterwards. The stored payload must still say Priya: a retry an
    // hour later reports what happened, not what is true now.
    $contact->forceFill(['first_name' => 'Someone Else'])->save();

    expect(WebhookDelivery::query()->first()->payload['data']['first_name'])->toBe('Priya');
});

it('only publishes events for the modules the API exposes', function () {
    expect(WebhookEvents::all())->toContain('contacts.created', 'deals.updated', 'accounts.deleted')
        ->and(WebhookEvents::exists('quotes.created'))->toBeFalse();
});

// -- Signing -------------------------------------------------------------------------

it('signs every request, and the signature verifies against the secret', function () {
    Http::fake(['example.com/*' => Http::response([], 200)]);

    $endpoint = WebhookEndpoint::factory()->create(['secret' => 'shhh-this-is-the-secret']);
    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => 'contacts.created',
        'payload' => ['id' => 'abc', 'event' => 'contacts.created', 'data' => ['id' => 1]],
        'status' => WebhookDeliveryStatus::Pending,
        'attempts' => 0,
    ]);

    deliver($delivery);

    Http::assertSent(function ($request) {
        $header = $request->header(WebhookSignature::HEADER)[0] ?? '';

        // Verified the way a receiver would, against the body that arrived.
        return WebhookSignature::verify($header, $request->body(), 'shhh-this-is-the-secret');
    });

    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->attempts)->toBe(1);
});

it('refuses a signature signed with a different secret', function () {
    $payload = '{"hello":"world"}';
    $header = WebhookSignature::for($payload, 'the-real-secret', time());

    expect(WebhookSignature::verify($header, $payload, 'the-real-secret'))->toBeTrue()
        ->and(WebhookSignature::verify($header, $payload, 'a-different-secret'))->toBeFalse()
        // And a body somebody edited in flight.
        ->and(WebhookSignature::verify($header, '{"hello":"tampered"}', 'the-real-secret'))->toBeFalse();
});

it('refuses a captured request replayed later', function () {
    $payload = '{"hello":"world"}';

    // Signed six minutes ago. Without the timestamp inside the signed string,
    // this would verify for ever.
    $header = WebhookSignature::for($payload, 'the-real-secret', time() - 360);

    expect(WebhookSignature::verify($header, $payload, 'the-real-secret'))->toBeFalse()
        ->and(WebhookSignature::verify($header, $payload, 'the-real-secret', tolerance: 600))->toBeTrue();
});

// -- Retrying ---------------------------------------------------------------------------

it('retries a delivery the endpoint refused', function () {
    // A sequence rather than two Http::fake calls: the second call adds to the
    // stub list rather than replacing it, so the first stub would keep winning.
    Http::fake(['example.com/*' => Http::sequence()
        ->push('no thanks', 500)
        ->push([], 200)]);

    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => 'contacts.created',
        'payload' => ['id' => 'abc'],
        'status' => WebhookDeliveryStatus::Pending,
        'attempts' => 0,
    ]);

    // Thrown rather than returned, which is what puts it back on the queue —
    // a quiet return would leave it at "trying" for ever.
    expect(fn () => deliver($delivery))->toThrow(RuntimeException::class, 'Endpoint answered 500');

    $delivery->refresh();

    expect($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(500)
        ->and($delivery->error)->toBe('no thanks')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending);

    // Second attempt succeeds; the row records both.
    deliver($delivery);

    expect($delivery->refresh()->attempts)->toBe(2)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->delivered_at)->not->toBeNull();
});

it('backs off in minutes, not seconds, and gives up after an hour or so', function () {
    $job = new DeliverWebhook(1);

    expect($job->tries)->toBe(6)
        ->and(array_sum($job->backoff()))->toBeGreaterThan(3000);
});

it('marks a delivery given up when the last attempt fails', function () {
    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => 'contacts.created',
        'payload' => ['id' => 'abc'],
        'status' => WebhookDeliveryStatus::Pending,
        'attempts' => 5,
    ]);

    (new DeliverWebhook($delivery->id))->failed(new RuntimeException('gone'));

    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Failed);
});

it('does not send again once it has been delivered', function () {
    Http::fake();

    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => 'contacts.created',
        'payload' => ['id' => 'abc'],
        'status' => WebhookDeliveryStatus::Delivered,
        'attempts' => 1,
    ]);

    deliver($delivery);

    Http::assertNothingSent();
});

// -- Where it will not send ----------------------------------------------------------------

it('will not deliver to an address inside this network', function () {
    Http::fake();

    $endpoint = WebhookEndpoint::factory()->create(['url' => 'http://169.254.169.254/latest/meta-data/']);
    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => 'contacts.created',
        'payload' => ['id' => 'abc'],
        'status' => WebhookDeliveryStatus::Pending,
        'attempts' => 0,
    ]);

    deliver($delivery);

    Http::assertNothingSent();

    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->error)->toContain('inside this network');
});

// -- Managing endpoints ----------------------------------------------------------------------

it('needs a permission to configure a webhook', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.webhooks'))
        ->assertForbidden();

    $this->actingAs(webhookUser())
        ->get(route('settings.webhooks'))
        ->assertOk();
});

it('shows the signing secret once, and keeps it encrypted at rest', function () {
    $component = Livewire::actingAs(webhookUser())
        ->test(WebhookEndpoints::class)
        ->call('add')
        ->set('name', 'Order system')
        ->set('url', 'https://example.com/hooks/crm')
        ->set('events', ['contacts.created'])
        ->call('save')
        ->assertHasNoErrors();

    $secret = $component->get('revealedSecret');
    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($secret)->not->toBeNull()
        ->and($endpoint->secret)->toBe($secret)
        // The stored column is ciphertext, not the secret.
        ->and(DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret'))->not->toBe($secret);
});

it('refuses an event the application does not publish', function () {
    Livewire::actingAs(webhookUser())
        ->test(WebhookEndpoints::class)
        ->call('add')
        ->set('name', 'Bad')
        ->set('url', 'https://example.com/hook')
        ->set('events', ['invented.event'])
        ->call('save')
        ->assertHasErrors(['events.0']);
});

it('replaces a secret when it has been somewhere it should not have', function () {
    $endpoint = WebhookEndpoint::factory()->create(['secret' => 'the-old-secret']);

    $component = Livewire::actingAs(webhookUser())
        ->test(WebhookEndpoints::class)
        ->call('regenerate', $endpoint->id);

    expect($endpoint->refresh()->secret)->not->toBe('the-old-secret')
        ->and($component->get('revealedSecret'))->toBe($endpoint->secret);
});
