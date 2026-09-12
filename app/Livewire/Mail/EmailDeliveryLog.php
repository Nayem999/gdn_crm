<?php

namespace App\Livewire\Mail;

use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\MailConfiguration;
use App\Domain\Mail\MailProviders;
use App\Domain\Mail\Models\EmailMessage;
use App\Domain\Mail\Webhooks\MailWebhooks;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every message that left the application, and what the provider said next.
 *
 * It also carries the webhook URL to paste into the provider, because a
 * delivery log that only ever says "sent" is the symptom of a webhook nobody
 * configured, and the address needed to fix that should be on the screen that
 * shows the symptom.
 */
#[Title('Email delivery')]
class EmailDeliveryLog extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $provider = '';

    public int $perPage = 25;

    /**
     * The message whose event history is open, if any.
     */
    public ?int $expanded = null;

    public function mount(): void
    {
        $this->authorize('viewAny', EmailMessage::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'provider'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'provider']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->provider !== '';
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    /**
     * @return LengthAwarePaginator<int, EmailMessage>
     */
    public function messages(): LengthAwarePaginator
    {
        return EmailMessage::query()
            ->with('events')
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';

                $query->where(fn ($rows) => $rows
                    ->where('to_email', 'like', $term)
                    ->orWhere('subject', 'like', $term));
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->provider !== '', fn ($query) => $query->where('provider', $this->provider))
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    /**
     * The address the configured provider should report to, when it reports at
     * all.
     */
    public function webhookUrl(): ?string
    {
        $provider = app(MailConfiguration::class)->activeProvider()->key();

        return MailWebhooks::supports($provider) ? MailWebhooks::urlFor($provider) : null;
    }

    public function activeProviderLabel(): string
    {
        return app(MailConfiguration::class)->activeProvider()->label();
    }

    public function render(): View
    {
        return view('livewire.mail.delivery-log', [
            'messages' => $this->messages(),
            'statuses' => EmailStatus::options(),
            'providers' => MailProviders::options(),
        ]);
    }
}
