<?php

namespace App\Livewire\Leads;

use App\Domain\Leads\Capture\CaptureField;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\LeadCaptureForm;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The form builder.
 *
 * Chooses which of `CaptureField`'s fixed catalogue a public form shows, which
 * are required, and where its submissions land. It cannot invent a field key —
 * the key decides which lead column a public submission writes to, and a
 * builder that could name one would be handing that decision to a form.
 */
#[Title('Lead capture forms')]
class LeadCaptureForms extends Component
{
    use AuthorizesRequests;

    public bool $editing = false;

    public ?int $editingId = null;

    public string $name = '';

    /**
     * Whether this is an embedded form or a chat widget.
     *
     * The same record either way: both are a token in a URL, an owner who gets
     * the lead, a source to record and a switch. Only the interface differs.
     */
    public string $kind = 'form';

    public string $description = '';

    public string $ownerId = '';

    public string $source = '';

    public string $submitLabel = 'Send';

    public string $successMessage = '';

    public string $redirectUrl = '';

    public bool $isActive = true;

    /**
     * The chosen fields, in order.
     *
     * Typed as loosely as it really is: this is a wire-bound public property,
     * so the browser decides what arrives in it. `CaptureField::fromStored()`
     * is what turns that into a field, on save and on render alike.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $fields = [];

    public function mount(): void
    {
        $this->authorize('configureForms', LeadCaptureForm::class);
    }

    /**
     * @return Collection<int, LeadCaptureForm>
     */
    #[Computed]
    public function forms(): Collection
    {
        return LeadCaptureForm::query()->with('owner:id,name')->latest('id')->get();
    }

    /**
     * @return array<string, string>
     */
    public function fieldOptions(): array
    {
        return CaptureField::options();
    }

    /**
     * @return array<int, string>
     */
    public function ownerOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    public function sourceOptions(): array
    {
        return LeadSource::options();
    }

    public function add(): void
    {
        $this->authorize('configureForms', LeadCaptureForm::class);

        $this->resetEditor();

        // Something workable rather than a blank list: every capture form needs
        // a name and a way to reply.
        $this->fields = [
            ['key' => 'first_name', 'label' => 'First name', 'required' => true],
            ['key' => 'last_name', 'label' => 'Last name', 'required' => true],
            ['key' => 'email', 'label' => 'Email', 'required' => true],
            ['key' => 'description', 'label' => 'How can we help?', 'required' => false],
        ];

        $this->ownerId = (string) auth()->id();
        $this->kind = 'form';
        $this->editing = true;
    }

    public function edit(int $formId): void
    {
        $form = LeadCaptureForm::query()->whereKey($formId)->first();

        if ($form === null) {
            return;
        }

        $this->authorize('configureForms', LeadCaptureForm::class);

        $this->editingId = $form->id;
        $this->name = $form->name;
        $this->kind = $form->getAttributeValue('kind');
        $this->description = (string) $form->description;
        $this->ownerId = (string) $form->owner_id;
        $this->source = (string) $form->source;
        $this->submitLabel = $form->submit_label;
        $this->successMessage = (string) $form->success_message;
        $this->redirectUrl = (string) $form->redirect_url;
        $this->isActive = (bool) $form->is_active;
        $this->fields = array_map(
            fn (CaptureField $field): array => $field->toArray(),
            $form->captureFields(),
        );
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetEditor();
        $this->resetValidation();
    }

    public function addField(string $key): void
    {
        // Only from the catalogue, and only once.
        if (! CaptureField::has($key) || collect($this->fields)->contains('key', $key)) {
            return;
        }

        $this->fields[] = [
            'key' => $key,
            'label' => CaptureField::catalogue()[$key]['label'],
            'required' => false,
        ];
    }

    public function removeField(int $index): void
    {
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    public function save(): void
    {
        $form = $this->editingId === null
            ? null
            : LeadCaptureForm::query()->whereKey($this->editingId)->first();

        if ($this->editingId !== null && $form === null) {
            return;
        }

        $this->authorize('configureForms', LeadCaptureForm::class);

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'kind' => ['required', Rule::in(array_keys(LeadCaptureForm::kindOptions()))],
            'description' => ['nullable', 'string', 'max:1000'],
            'ownerId' => ['required', 'integer', 'exists:users,id'],
            'source' => ['nullable', 'string', Rule::in(array_keys(LeadSource::options()))],
            'submitLabel' => ['required', 'string', 'max:48'],
            'successMessage' => ['nullable', 'string', 'max:500'],
            // A URL, not free text: it is handed to a redirect, and anything
            // that is not one belongs nowhere near that.
            'redirectUrl' => ['nullable', 'url', 'max:255'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.key' => ['required', 'string', Rule::in(array_keys(CaptureField::catalogue()))],
        ], [], [
            'ownerId' => 'owner',
            'submitLabel' => 'button label',
            'redirectUrl' => 'redirect URL',
        ]);

        $form ??= new LeadCaptureForm(['token' => LeadCaptureForm::newToken()]);

        $form->forceFill([
            // The token is written once, on create. Changing it would break
            // every page that already embeds the form.
            'token' => $form->token ?? LeadCaptureForm::newToken(),
            'name' => $this->name,
            'kind' => $this->kind,
            'description' => $this->description === '' ? null : $this->description,
            'owner_id' => (int) $this->ownerId,
            'source' => $this->source === '' ? null : $this->source,
            'submit_label' => $this->submitLabel,
            'success_message' => $this->successMessage === '' ? null : $this->successMessage,
            'redirect_url' => $this->redirectUrl === '' ? null : $this->redirectUrl,
            'is_active' => $this->isActive,
            // Through the same reader the public page uses, so a stored form
            // can only ever hold what the catalogue recognises.
            'fields' => array_values(array_filter(array_map(
                fn (mixed $field): ?array => CaptureField::fromStored($field)?->toArray(),
                $this->fields,
            ))),
        ])->save();

        $this->resetEditor();
        unset($this->forms);

        $this->dispatch('notify', type: 'success', message: $form->name.' saved.');
    }

    public function delete(int $formId): void
    {
        $form = LeadCaptureForm::query()->whereKey($formId)->first();

        if ($form === null) {
            return;
        }

        $this->authorize('configureForms', LeadCaptureForm::class);

        $name = $form->name;
        $form->delete();

        unset($this->forms);

        $this->dispatch('notify', type: 'success', message: $name.' was removed. Its public link no longer works.');
    }

    private function resetEditor(): void
    {
        $this->editing = false;
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->ownerId = '';
        $this->source = '';
        $this->submitLabel = 'Send';
        $this->successMessage = '';
        $this->redirectUrl = '';
        $this->isActive = true;
        $this->fields = [];
    }

    public function render(): View
    {
        return view('livewire.leads.lead-capture-forms');
    }
}
