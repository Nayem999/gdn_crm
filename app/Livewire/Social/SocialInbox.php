<?php

namespace App\Livewire\Social;

use App\Domain\Activities\Actions\CreateActivityAction;
use App\Domain\Activities\DTOs\ActivityData;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Social\Actions\AssignConversationAction;
use App\Domain\Social\Actions\CreateLeadFromConversationAction;
use App\Domain\Social\Actions\SendSocialMessageAction;
use App\Domain\Social\Actions\SendWhatsAppTemplateAction;
use App\Domain\Social\Enums\ConversationStatus;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\WhatsAppTemplate;
use App\Domain\Timeline\Actions\SaveNoteAction;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * One inbox for every channel.
 *
 * Three columns, because that is what the work is: which conversation, what was
 * said, and what to do about it. The channel is a **filter** rather than a
 * second screen — an agent answering customers does not care which application
 * the message came from until they reply, and two screens would mean two places
 * to miss something.
 *
 * The right-hand panel is where a conversation becomes CRM work: the lead it
 * belongs to, a note, a follow-up task. Anything heavier — converting a lead,
 * building a deal with a pipeline and a value — is a form that already exists on
 * the record, and the panel links to it rather than reimplementing it badly in a
 * third of a screen.
 */
#[Title('Social inbox')]
class SocialInbox extends Component
{
    use AuthorizesRequests;

    /**
     * Filters live in the URL so a view of the inbox can be sent to somebody.
     */
    #[Url]
    public string $channel = '';

    #[Url]
    public string $status = '';

    /**
     * '', 'mine' or 'unassigned'.
     */
    #[Url]
    public string $owner = '';

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $conversation = null;

    public string $reply = '';

    /**
     * The template chosen for a conversation whose window has closed, and the
     * values its placeholders need.
     */
    public ?int $templateId = null;

    /**
     * @var array<int, string>
     */
    public array $templateValues = [];

    public string $note = '';

    public string $taskSubject = '';

    public string $taskDueAt = '';

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorize('viewAny', SocialConversation::class);
    }

    /**
     * Opening a conversation is reading it.
     */
    public function select(int $conversationId, AssignConversationAction $assign): void
    {
        $this->conversation = $conversationId;
        $this->reply = '';
        $this->error = null;
        $this->notice = null;

        $selected = $this->selected();

        if ($selected !== null) {
            $assign->markRead($selected);
        }
    }

    public function send(SendSocialMessageAction $send): void
    {
        $conversation = $this->requireSelected();

        $this->authorize('reply', $conversation);

        try {
            $send($conversation, $this->reply, auth()->user());
        } catch (RuntimeException $exception) {
            // Meta's refusal is the message. "Sending failed" would send an
            // agent looking in the wrong place.
            $this->error = $exception->getMessage();

            return;
        }

        $this->reply = '';
        $this->notice = 'Sent.';
    }

    /**
     * Send an approved template — the only thing Meta allows once the window
     * has closed.
     */
    public function sendTemplate(SendWhatsAppTemplateAction $send): void
    {
        $conversation = $this->requireSelected();

        $this->authorize('reply', $conversation);

        $template = $this->templateId === null
            ? null
            : WhatsAppTemplate::query()->find($this->templateId);

        if ($template === null) {
            $this->error = 'Choose a template to send.';

            return;
        }

        try {
            $send($conversation, $template, array_values($this->templateValues), auth()->user());
        } catch (RuntimeException $exception) {
            // Every refusal here is one this application made before asking
            // Meta, so the message is already in words somebody can act on.
            $this->error = $exception->getMessage();

            return;
        }

        $this->templateId = null;
        $this->templateValues = [];
        $this->notice = 'Template sent.';
    }

    /**
     * The approved templates this conversation could be sent, if any.
     *
     * @return array<string, string>
     */
    public function templateOptions(): array
    {
        $options = [];

        foreach (WhatsAppTemplate::query()->sendable()->orderBy('name')->limit(100)->get() as $template) {
            $options[(string) $template->getKey()] = $template->displayName();
        }

        return $options;
    }

    public function chosenTemplate(): ?WhatsAppTemplate
    {
        return $this->templateId === null
            ? null
            : WhatsAppTemplate::query()->find($this->templateId);
    }

    public function claim(AssignConversationAction $assign): void
    {
        $conversation = $this->requireSelected();

        $this->authorize('assign', $conversation);

        $assign->assign($conversation, auth()->user());
        $this->notice = 'This one is yours.';
    }

    public function release(AssignConversationAction $assign): void
    {
        $conversation = $this->requireSelected();

        $this->authorize('assign', $conversation);

        $assign->release($conversation);
        $this->notice = 'Back in the queue.';
    }

    public function close(AssignConversationAction $assign): void
    {
        $conversation = $this->requireSelected();

        $this->authorize('assign', $conversation);

        $assign->close($conversation);
        $this->notice = 'Closed. It reopens by itself if they write again.';
    }

    public function reopen(AssignConversationAction $assign): void
    {
        $conversation = $this->requireSelected();

        $this->authorize('assign', $conversation);

        $assign->reopen($conversation);
    }

    /**
     * Make a lead from a conversation that has none.
     *
     * The same action the first inbound message uses, so a lead made here and
     * one made automatically are the same lead, attribution and all.
     */
    public function createLead(CreateLeadFromConversationAction $create): void
    {
        $conversation = $this->requireSelected();

        $this->authorize('assign', $conversation);

        if ($conversation->isLinked()) {
            return;
        }

        $lead = $create($conversation, auth()->user());

        $this->notice = sprintf('%s is now a lead.', $lead->fullName());
    }

    public function addNote(SaveNoteAction $notes): void
    {
        $conversation = $this->requireSelected();

        $this->authorize('assign', $conversation);

        $subject = $conversation->subject();
        $body = trim($this->note);

        if ($subject === null) {
            // Honest rather than silent: a note has to hang off a record, and
            // this conversation has none yet.
            $this->error = 'Create a lead first — a note belongs to a record.';

            return;
        }

        if ($body === '') {
            return;
        }

        $notes->create($subject, auth()->user(), $body);

        $this->note = '';
        $this->notice = 'Note added.';
    }

    public function addTask(CreateActivityAction $createActivity): void
    {
        $conversation = $this->requireSelected();

        $this->authorize('assign', $conversation);

        $subject = $conversation->subject();

        if ($subject === null) {
            $this->error = 'Create a lead first — a task belongs to a record.';

            return;
        }

        if (trim($this->taskSubject) === '' || trim($this->taskDueAt) === '') {
            $this->error = 'A task needs something to do and a day to do it by.';

            return;
        }

        try {
            $createActivity(ActivityData::fromArray([
                'subject' => trim($this->taskSubject),
                'due_at' => trim($this->taskDueAt),
                // The record it is about, named by module rather than by class:
                // nothing from the browser gets to name a class.
                'related_module' => $subject instanceof Contact ? 'contacts' : 'leads',
                'related_id' => $subject->getKey(),
            ]), auth()->user());
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        $this->taskSubject = '';
        $this->taskDueAt = '';
        $this->notice = 'Task added.';
    }

    /**
     * The conversations this filter describes.
     *
     * @return EloquentCollection<int, SocialConversation>
     */
    public function conversations(): EloquentCollection
    {
        $channel = SocialChannel::tryFrom($this->channel);
        $status = ConversationStatus::tryFrom($this->status);
        $search = trim($this->search);

        return SocialConversation::query()
            ->with(['assignedTo', 'lead', 'contact'])
            ->when($channel !== null, fn ($query) => $query->onChannel($channel))
            ->when($status !== null, fn ($query) => $query->withStatus($status))
            ->when($this->owner === 'mine', fn ($query) => $query->where('assigned_to_id', auth()->id()))
            ->when($this->owner === 'unassigned', fn ($query) => $query->unassigned())
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('participant_name', 'like', '%'.$search.'%')
                    ->orWhere('participant_handle', 'like', '%'.$search.'%');
            }))
            ->latestFirst()
            ->limit(100)
            ->get();
    }

    public function selected(): ?SocialConversation
    {
        if ($this->conversation === null) {
            return null;
        }

        return SocialConversation::query()
            ->with(['messages.sender', 'assignedTo', 'lead', 'contact'])
            ->find($this->conversation);
    }

    /**
     * How many are waiting, for the heading to say so.
     */
    public function waitingCount(): int
    {
        return SocialConversation::query()->withStatus(ConversationStatus::Open)->count();
    }

    public function render(): View
    {
        $selected = $this->selected();

        return view('livewire.social.social-inbox', [
            'conversations' => $this->conversations(),
            'selected' => $selected,
            'subject' => $selected?->subject(),
            'waiting' => $this->waitingCount(),
            // Only WhatsApp has templates; Messenger's way past a closed window
            // is a message tag, which is not something an agent picks from a
            // list.
            'templates' => $selected?->channel() === SocialChannel::WhatsApp
                ? $this->templateOptions()
                : [],
            'chosenTemplate' => $this->chosenTemplate(),
        ]);
    }

    /**
     * The selected conversation, or a refusal.
     *
     * Every action goes through this rather than trusting the id in component
     * state: a conversation that has been deleted between render and click must
     * not reach an action with a null.
     */
    private function requireSelected(): SocialConversation
    {
        $conversation = $this->selected();

        if ($conversation === null) {
            throw new RuntimeException('That conversation is no longer here.');
        }

        return $conversation;
    }

    /**
     * What the lead or contact link points at.
     */
    public function subjectRoute(Lead|Contact|null $subject): ?string
    {
        return match (true) {
            $subject instanceof Lead => route('leads.show', $subject),
            $subject instanceof Contact => route('contacts.show', $subject),
            default => null,
        };
    }
}
