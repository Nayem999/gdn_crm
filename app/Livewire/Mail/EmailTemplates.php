<?php

namespace App\Livewire\Mail;

use App\Domain\Mail\Actions\RenderEmailTemplateAction;
use App\Domain\Mail\Models\EmailTemplate;
use App\Domain\Mail\Templates\MergeFields;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Write the wording a salesperson sends to a customer.
 *
 * The editor knows which module the template is for, which is what lets it
 * offer the right merge fields and — more usefully — warn about a token that
 * will never resolve. An unknown token renders as nothing, so without the
 * warning the first sign of a typo is a customer receiving "Dear ,".
 */
#[Title('Email templates')]
class EmailTemplates extends Component
{
    use AuthorizesRequests;

    public ?int $editing = null;

    public bool $showForm = false;

    public string $name = '';

    public string $module = 'contact';

    public string $subject = '';

    public string $body = '';

    public bool $is_active = true;

    public bool $track_opens = false;

    public bool $track_clicks = false;

    public ?string $preview = null;

    public function mount(): void
    {
        $this->authorize('viewAny', EmailTemplate::class);
    }

    /**
     * @return Collection<int, EmailTemplate>
     */
    public function templates(): Collection
    {
        return EmailTemplate::query()->orderBy('module')->orderBy('name')->get();
    }

    public function canUpdate(): bool
    {
        return auth()->user()?->can('email-templates.update') ?? false;
    }

    /**
     * The field list for the picker, keyed by the token exactly as it should be
     * typed.
     *
     * Keyed by the finished token rather than by the field name because Blade
     * cannot print a literal pair of braces from an expression — the closing
     * pair ends the echo — and building the example here is clearer than the
     * escaping gymnastics that would be needed in the view.
     *
     * @return array<string, string>
     */
    public function mergeFields(): array
    {
        $examples = [];

        foreach (MergeFields::for($this->module) as $token => $label) {
            $examples['{{'.$token.'}}'] = $label;
        }

        return $examples;
    }

    /**
     * Tokens the draft uses that its module does not offer.
     *
     * @return array<int, string>
     */
    public function unknownFields(): array
    {
        $template = new EmailTemplate([
            'module' => $this->module,
            'subject' => $this->subject,
            'body' => $this->body,
        ]);

        return $template->unknownFields();
    }

    public function create(): void
    {
        $this->authorize('create', EmailTemplate::class);

        $this->reset(['editing', 'name', 'subject', 'body', 'preview']);
        $this->module = 'contact';
        $this->is_active = true;
        $this->track_opens = false;
        $this->track_clicks = false;
        $this->showForm = true;
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $template = EmailTemplate::query()->findOrFail($id);

        $this->authorize('update', $template);

        $this->editing = $template->id;
        $this->name = $template->name;
        $this->module = $template->getAttributeValue('module');
        $this->subject = $template->getAttributeValue('subject');
        $this->body = $template->body;
        $this->is_active = $template->is_active;
        $this->track_opens = $template->track_opens;
        $this->track_clicks = $template->track_clicks;
        $this->showForm = true;
        $this->preview = null;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'name', 'subject', 'body', 'preview', 'showForm']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $template = $this->editing === null ? null : EmailTemplate::query()->findOrFail($this->editing);

        $template === null
            ? $this->authorize('create', EmailTemplate::class)
            : $this->authorize('update', $template);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'module' => ['required', Rule::in(array_keys(MergeFields::modules()))],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $values = [
            'name' => $this->name,
            'module' => $this->module,
            'subject' => $this->subject,
            'body' => $this->body,
            'is_active' => $this->is_active,
            'track_opens' => $this->track_opens,
            'track_clicks' => $this->track_clicks,
        ];

        if ($template === null) {
            $values['created_by'] = auth()->id();
            EmailTemplate::query()->create($values);
        } else {
            $template->update($values);
        }

        $this->cancel();
        $this->dispatch('template-saved');
    }

    public function delete(int $id): void
    {
        $template = EmailTemplate::query()->findOrFail($id);

        $this->authorize('delete', $template);

        $template->delete();

        if ($this->editing === $template->id) {
            $this->cancel();
        }
    }

    /**
     * Render the draft against nothing.
     *
     * Deliberately against no record: the point of the preview is to show what
     * the wording looks like and which tokens vanish, and picking a real
     * customer to demonstrate that on is a worse way to find out.
     */
    public function previewDraft(): void
    {
        $this->preview = app(RenderEmailTemplateAction::class)->handle(
            new EmailTemplate([
                'module' => $this->module,
                'subject' => $this->subject,
                'body' => $this->body,
            ]),
            null,
            auth()->user(),
        )->html;
    }

    public function render(): View
    {
        return view('livewire.mail.email-templates', [
            'templates' => $this->templates(),
            'modules' => MergeFields::modules(),
        ]);
    }
}
