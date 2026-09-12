<?php

namespace App\Livewire\Settings;

use App\Domain\Webhooks\Enums\WebhookDeliveryStatus;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookEndpoint;
use App\Domain\Webhooks\WebhookEvents;
use App\Jobs\DeliverWebhook;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where the application tells somebody else's system what happened.
 *
 * The secret is shown once, when it is generated, for the same reason an API
 * key is: it is stored encrypted so that a database backup does not carry every
 * integration's secret away, and a screen that offered to show it again would
 * be a screen that decrypts it on demand.
 */
#[Title('Webhooks')]
class WebhookEndpoints extends Component
{
    use AuthorizesRequests;

    public bool $editing = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $url = '';

    public bool $isActive = true;

    /**
     * @var array<int, string>
     */
    public array $events = [];

    /**
     * The signing secret, in plain text, for as long as this page is open.
     */
    public ?string $revealedSecret = null;

    public function mount(): void
    {
        $this->authorize('viewAny', WebhookEndpoint::class);
    }

    /**
     * @return Collection<int, WebhookEndpoint>
     */
    public function endpoints(): Collection
    {
        return WebhookEndpoint::query()->with(['deliveries' => fn ($query) => $query->limit(5)])->orderBy('name')->get();
    }

    /**
     * @return array<string, string>
     */
    public function eventOptions(): array
    {
        return WebhookEvents::options();
    }

    public function canManage(): bool
    {
        return auth()->user()?->can('api.webhooks') ?? false;
    }

    public function add(): void
    {
        $this->authorize('create', WebhookEndpoint::class);

        $this->reset(['editingId', 'name', 'url', 'events', 'revealedSecret']);
        $this->isActive = true;
        $this->editing = true;
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $endpoint = WebhookEndpoint::query()->findOrFail($id);

        $this->authorize('update', $endpoint);

        $this->editingId = $endpoint->id;
        $this->name = $endpoint->name;
        $this->url = $endpoint->url;
        $this->events = $endpoint->events ?? [];
        $this->isActive = $endpoint->is_active;
        $this->editing = true;
        $this->revealedSecret = null;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'editingId', 'name', 'url', 'events', 'revealedSecret']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $endpoint = $this->editingId === null ? null : WebhookEndpoint::query()->findOrFail($this->editingId);

        $endpoint === null
            ? $this->authorize('create', WebhookEndpoint::class)
            : $this->authorize('update', $endpoint);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            // A URL, not free text: it is handed to an HTTP client, and the
            // SSRF guard refuses anything pointing inside this network when the
            // delivery runs.
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', WebhookEvents::all())],
        ], [], ['events' => 'events']);

        $values = [
            'name' => $this->name,
            'url' => $this->url,
            'events' => array_values($this->events),
            'is_active' => $this->isActive,
        ];

        if ($endpoint === null) {
            $secret = Str::random(48);

            $endpoint = WebhookEndpoint::query()->create([
                ...$values,
                'secret' => $secret,
                'created_by' => auth()->id(),
            ]);

            $this->revealedSecret = $secret;
        } else {
            $endpoint->update($values);
        }

        $this->editing = false;
        $this->editingId = null;
        $this->dispatch('webhook-saved');
    }

    /**
     * A new secret, because the old one has been somewhere it should not have.
     */
    public function regenerate(int $id): void
    {
        $endpoint = WebhookEndpoint::query()->findOrFail($id);

        $this->authorize('update', $endpoint);

        $secret = Str::random(48);

        $endpoint->update(['secret' => $secret]);

        // Said plainly on the screen: every delivery signed with the old secret
        // stops verifying the moment this is pressed.
        $this->revealedSecret = $secret;
    }

    public function delete(int $id): void
    {
        $endpoint = WebhookEndpoint::query()->findOrFail($id);

        $this->authorize('delete', $endpoint);

        $endpoint->delete();
    }

    /**
     * Send a failed delivery again, with the payload it was built with.
     */
    public function retry(int $deliveryId): void
    {
        $delivery = WebhookDelivery::query()->findOrFail($deliveryId);

        $this->authorize('update', $delivery->endpoint ?? new WebhookEndpoint);

        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Pending,
            'error' => null,
        ])->save();

        DeliverWebhook::dispatch($delivery->id);
    }

    public function render(): View
    {
        return view('livewire.settings.webhook-endpoints', [
            'endpoints' => $this->endpoints(),
        ]);
    }
}
