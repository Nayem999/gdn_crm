<?php

namespace App\Livewire\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Domain\Notifications\TemplateRenderer;
use App\Domain\Notifications\TemplateResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Edits the wording for one event on one channel.
 */
#[Title('Notification templates')]
class NotificationTemplates extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'event', except: '')]
    public string $eventKey = '';

    #[Url(as: 'channel', except: '')]
    public string $channel = '';

    public string $subject = '';

    public string $body = '';

    public function mount(): void
    {
        $this->authorize('viewAny', NotificationLog::class);

        if (! NotificationEventRegistry::has($this->eventKey)) {
            $this->eventKey = NotificationEventRegistry::keys()[0];
        }

        if (NotificationChannel::tryFrom($this->channel) === null) {
            $this->channel = NotificationChannel::InApp->value;
        }

        $this->load();
    }

    public function updatedEventKey(): void
    {
        $this->load();
    }

    public function updatedChannel(): void
    {
        $this->load();
    }

    public function event(): NotificationEvent
    {
        return NotificationEventRegistry::find($this->eventKey);
    }

    public function currentChannel(): NotificationChannel
    {
        return NotificationChannel::tryFrom($this->channel) ?? NotificationChannel::InApp;
    }

    /**
     * @return array<string, string>
     */
    public function mergeFields(): array
    {
        return NotificationEventRegistry::mergeFieldsFor($this->eventKey);
    }

    /**
     * What the message will look like, filled with the field names themselves so
     * an admin can see the shape without needing a real record.
     */
    public function preview(): string
    {
        $sample = [];

        foreach (array_keys($this->mergeFields()) as $field) {
            data_set($sample, $field, '['.$field.']');
        }

        return app(TemplateRenderer::class)->render($this->body, $sample);
    }

    /**
     * @return array<int, string>
     */
    public function unknownFields(): array
    {
        return app(TemplateRenderer::class)->unknownFields($this->body, $this->mergeFields());
    }

    public function save(): void
    {
        $this->authorize('update', NotificationLog::class);

        $this->validate([
            'subject' => [$this->currentChannel()->hasSubject() ? 'required' : 'nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        // A template naming a field the event does not carry would render as a
        // gap in a message someone reads, so it is refused here.
        if ($this->unknownFields() !== []) {
            $this->addError('body', 'This template uses fields this event does not provide: '
                .implode(', ', $this->unknownFields()).'.');

            return;
        }

        NotificationTemplate::query()->updateOrCreate(
            ['event' => $this->eventKey, 'channel' => $this->channel],
            [
                'subject' => $this->currentChannel()->hasSubject() ? $this->subject : null,
                'body' => $this->body,
            ]
        );

        $this->dispatch('template-saved');
    }

    /**
     * Throw away the edit and go back to the wording the event ships with.
     */
    public function resetToDefault(): void
    {
        $this->authorize('update', NotificationLog::class);

        NotificationTemplate::query()
            ->where('event', $this->eventKey)
            ->where('channel', $this->channel)
            ->delete();

        $this->load();
        $this->dispatch('template-saved');
    }

    public function isCustomised(): bool
    {
        return app(TemplateResolver::class)->stored($this->eventKey, $this->currentChannel()) !== null;
    }

    public function canUpdate(): bool
    {
        return auth()->user()?->can('notifications.update') ?? false;
    }

    public function render(): View
    {
        return view('livewire.notifications.templates', [
            'events' => NotificationEventRegistry::events(),
        ]);
    }

    private function load(): void
    {
        $wording = app(TemplateResolver::class)->editable($this->eventKey, $this->currentChannel());

        $this->subject = $wording['subject'] ?? '';
        $this->body = $wording['body'];
        $this->resetValidation();
    }
}
