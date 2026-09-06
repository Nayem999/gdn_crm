<?php

namespace App\Livewire\Contacts;

use App\Domain\Contacts\Actions\DeleteContactAction;
use App\Domain\Contacts\Actions\SetPrimaryContactAction;
use App\Domain\Contacts\ContactDuplicates;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Shared\Concerns\FindsDuplicates;
use App\Domain\Shared\Duplicates\DuplicateSource;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One contact's profile, and who else works at their account.
 *
 * Task 2.8 hangs the record timeline off this page.
 */
class ContactShow extends Component
{
    use AuthorizesRequests;
    use FindsDuplicates;

    #[Locked]
    public int $contactId;

    public function mount(Contact $contact): void
    {
        $this->authorize('view', $contact);

        $this->contactId = $contact->id;
    }

    /**
     * Trashed records are included so a merged one stays readable: keeping it
     * is what makes its history survive, and a page nobody can open is not
     * kept in any useful sense. An ordinary deletion is still gone.
     */
    public function contact(): Contact
    {
        $contact = Contact::withTrashed()
            ->with(['account', 'owner'])
            ->findOrFail($this->contactId);

        abort_if($contact->trashed() && ! $contact->isMerged(), 404);

        return $contact;
    }

    /**
     * Other people at the same account, so the page answers "who else is
     * there?" without a round trip.
     *
     * Scoped: a colleague owned by someone outside the viewer's access level
     * must not become visible just because they share an account.
     *
     * @return Collection<int, Contact>
     */
    public function colleagues(): Collection
    {
        $contact = $this->contact();

        if ($contact->account_id === null) {
            return collect();
        }

        return Contact::query()
            ->visibleTo(auth()->user())
            ->where('account_id', $contact->account_id)
            ->whereKeyNot($contact->getKey())
            ->orderByDesc('is_primary')
            ->orderBy('last_name')
            ->get();
    }

    public function makePrimary(): void
    {
        $contact = $this->contact();

        $this->authorize('update', $contact);

        if (! app(SetPrimaryContactAction::class)->promote($contact)) {
            $this->dispatch('notify', type: 'error', message: 'A contact needs an account before it can be the primary one.');

            return;
        }

        $this->dispatch('contact-updated', message: $contact->fullName().' is now the primary contact.');
    }

    public function delete(): void
    {
        $contact = $this->contact();

        $this->authorize('delete', $contact);

        app(DeleteContactAction::class)($contact);

        session()->flash('status', $contact->fullName().' was removed.');

        $this->redirectRoute('contacts.index', navigate: true);
    }

    public function duplicateSource(): ?DuplicateSource
    {
        return app(ContactDuplicates::class);
    }

    public function render(): View
    {
        $contact = $this->contact();

        return view('livewire.contacts.contact-show', [
            'contact' => $contact,
            'colleagues' => $this->colleagues(),
            'duplicates' => $this->duplicatesOf($contact),
        ])->title($contact->fullName());
    }
}
