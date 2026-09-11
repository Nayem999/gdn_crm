<?php

namespace App\Livewire\Timeline;

use App\Domain\Timeline\Actions\DeleteDocumentAction;
use App\Domain\Timeline\Actions\DeleteNoteAction;
use App\Domain\Timeline\Actions\SaveNoteAction;
use App\Domain\Timeline\Actions\UploadDocumentAction;
use App\Domain\Timeline\Enums\TimelineEntryKind;
use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;
use App\Domain\Timeline\TimelineBuilder;
use App\Domain\Timeline\TimelinePage;
use App\Domain\Timeline\TimelineRegistry;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Notes, documents and history for one record, on that record's own page.
 *
 * The subject reaches this component as a module key and an id, so it is
 * resolved through TimelineRegistry and then loaded and authorised — an
 * unlisted module 404s rather than becoming a class name, and a guessed id
 * reaches nothing outside the viewer's access level.
 */
class RecordTimeline extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    #[Locked]
    public string $module;

    #[Locked]
    public int $recordId;

    /**
     * Which strands are shown. Empty means all of them.
     *
     * @var array<int, string>
     */
    public array $kinds = [];

    /**
     * How many entries are on screen. "Load more" grows it rather than paging,
     * because a timeline is read downwards.
     */
    public int $visible = self::PAGE_SIZE;

    public const PAGE_SIZE = 20;

    // -- The note composer ----------------------------------------------------

    public string $body = '';

    /**
     * The note being edited, or null when the composer is writing a new one.
     */
    public ?int $editingNoteId = null;

    // -- The document uploader ------------------------------------------------

    public bool $attaching = false;

    public ?TemporaryUploadedFile $upload = null;

    public string $documentTitle = '';

    public string $documentDescription = '';

    public function mount(string $module, int $record): void
    {
        if (! TimelineRegistry::has($module)) {
            abort(404);
        }

        $this->module = $module;
        $this->recordId = $record;

        // Reading the timeline is reading the record: if the record is out of
        // reach, so is everything hanging off it.
        $this->authorize('view', $this->subject());
    }

    /**
     * The record this timeline belongs to.
     *
     * Loaded withTrashed for the same reason its own page is: a merged record
     * stays readable, and its history is the point of keeping it.
     */
    public function subject(): Model
    {
        $class = TimelineRegistry::modelClass($this->module);

        if ($class === null) {
            abort(404);
        }

        $model = new $class;

        // Dropping the scope rather than calling withTrashed(), which only
        // exists on models that soft-delete: the registry may one day hold a
        // module that does not, and this works for both.
        $subject = $model->newQuery()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereKey($this->recordId)
            ->first();

        if ($subject === null) {
            abort(404);
        }

        return $subject;
    }

    // -- Reading --------------------------------------------------------------

    /**
     * The strands this viewer may see at all.
     *
     * Activities are a module of their own, with their own permission: someone
     * who may read an account but not its calls gets no chip for them, and the
     * builder leaves the strand out regardless of what the chip state says.
     *
     * @return array<int, TimelineEntryKind>
     */
    public function availableKinds(): array
    {
        return array_values(array_filter(
            TimelineEntryKind::cases(),
            fn (TimelineEntryKind $kind): bool => $kind !== TimelineEntryKind::Activity
                || $this->currentUser()->can('activities.view'),
        ));
    }

    /**
     * @return array<int, TimelineEntryKind>
     */
    public function selectedKinds(): array
    {
        $selected = [];

        foreach ($this->kinds as $value) {
            $kind = TimelineEntryKind::tryFrom((string) $value);

            if ($kind !== null) {
                $selected[] = $kind;
            }
        }

        return $selected;
    }

    public function page(): TimelinePage
    {
        return app(TimelineBuilder::class)->for(
            $this->subject(),
            $this->visible,
            $this->selectedKinds(),
            $this->currentUser(),
        );
    }

    public function toggleKind(string $value): void
    {
        $kind = TimelineEntryKind::tryFrom($value);

        if ($kind === null) {
            return;
        }

        $this->kinds = in_array($kind->value, $this->kinds, true)
            ? array_values(array_diff($this->kinds, [$kind->value]))
            : [...$this->kinds, $kind->value];

        $this->visible = self::PAGE_SIZE;
    }

    public function showAllKinds(): void
    {
        $this->kinds = [];
        $this->visible = self::PAGE_SIZE;
    }

    public function isKindSelected(string $value): bool
    {
        return in_array($value, $this->kinds, true);
    }

    public function loadMore(): void
    {
        $this->visible += self::PAGE_SIZE;
    }

    /**
     * Something elsewhere on the page added to this record's timeline.
     *
     * A named event rather than Livewire's magic `$refresh`: the page can hold
     * several components, and this says which of them is meant to re-read. The
     * booking modal is the first to fire it; anything later that writes a
     * strand entry should fire the same one.
     */
    #[On('timeline-changed')]
    public function timelineChanged(): void
    {
        $this->visible = self::PAGE_SIZE;
    }

    // -- Notes ----------------------------------------------------------------

    public function canAddNote(): bool
    {
        return $this->currentUser()->can('create', [Note::class, $this->subject()]);
    }

    public function saveNote(): void
    {
        $this->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
        ], [], ['body' => 'note']);

        $action = app(SaveNoteAction::class);

        if ($this->editingNoteId !== null) {
            $note = $this->note($this->editingNoteId);

            $this->authorize('update', $note);

            $action->update($note, $this->body);
            $this->dispatch('notify', type: 'success', message: 'Note updated.');
        } else {
            $subject = $this->subject();

            $this->authorize('create', [Note::class, $subject]);

            $action->create($subject, $this->currentUser(), $this->body);
            $this->dispatch('notify', type: 'success', message: 'Note added.');
        }

        $this->cancelNote();
    }

    public function editNote(int $noteId): void
    {
        $note = $this->note($noteId);

        $this->authorize('update', $note);

        $this->editingNoteId = $note->id;
        $this->body = $note->body;
        $this->resetErrorBag('body');
    }

    public function cancelNote(): void
    {
        $this->editingNoteId = null;
        $this->body = '';
        $this->resetErrorBag('body');
    }

    public function deleteNote(int $noteId): void
    {
        $note = $this->note($noteId);

        $this->authorize('delete', $note);

        app(DeleteNoteAction::class)($note);

        if ($this->editingNoteId === $noteId) {
            $this->cancelNote();
        }

        $this->dispatch('notify', type: 'success', message: 'Note removed.');
    }

    /**
     * A note is looked up through the subject's own relation, never by id
     * alone: that is what stops an id from another record being edited through
     * this component.
     */
    private function note(int $noteId): Note
    {
        /** @var Note|null $note */
        $note = Note::query()
            ->where('notable_type', $this->subject()->getMorphClass())
            ->where('notable_id', $this->recordId)
            ->whereKey($noteId)
            ->first();

        if ($note === null) {
            abort(404);
        }

        return $note;
    }

    // -- Documents ------------------------------------------------------------

    public function canAttach(): bool
    {
        return $this->currentUser()->can('create', [Document::class, $this->subject()]);
    }

    public function startAttaching(): void
    {
        $this->attaching = true;
    }

    public function cancelAttaching(): void
    {
        $this->attaching = false;
        $this->upload = null;
        $this->documentTitle = '';
        $this->documentDescription = '';
        $this->resetErrorBag(['upload', 'documentTitle', 'documentDescription']);
    }

    /**
     * Named attachDocument rather than upload: `$wire.upload()` is Livewire's
     * own file-upload primitive, and a component method of that name is
     * shadowed on the client.
     */
    public function attachDocument(): void
    {
        $subject = $this->subject();

        $this->authorize('create', [Document::class, $subject]);

        $this->validate([
            // 10MB, matching the import screen. It has to sit below Livewire's
            // own temporary-upload cap (12MB, config/livewire.php) or the
            // rejection comes from the framework with a message about a
            // temporary file rather than from here about a document.
            'upload' => ['required', 'file', 'max:10240'],
            'documentTitle' => ['nullable', 'string', 'max:255'],
            'documentDescription' => ['nullable', 'string', 'max:500'],
        ], [], [
            'upload' => 'file',
            'documentTitle' => 'title',
            'documentDescription' => 'description',
        ]);

        app(UploadDocumentAction::class)(
            $subject,
            $this->currentUser(),
            $this->upload,
            $this->documentTitle,
            $this->documentDescription,
        );

        $this->cancelAttaching();

        $this->dispatch('notify', type: 'success', message: 'Document attached.');
    }

    public function deleteDocument(int $documentId): void
    {
        $document = $this->document($documentId);

        $this->authorize('delete', $document);

        app(DeleteDocumentAction::class)($document);

        $this->dispatch('notify', type: 'success', message: 'Document removed.');
    }

    private function document(int $documentId): Document
    {
        /** @var Document|null $document */
        $document = Document::query()
            ->where('documentable_type', $this->subject()->getMorphClass())
            ->where('documentable_id', $this->recordId)
            ->whereKey($documentId)
            ->first();

        if ($document === null) {
            abort(404);
        }

        return $document;
    }

    // -- Rendering ------------------------------------------------------------

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    public function render(): View
    {
        return view('livewire.timeline.record-timeline', [
            'page' => $this->page(),
            'filterKinds' => $this->availableKinds(),
            'canAddNote' => $this->canAddNote(),
            'canAttach' => $this->canAttach(),
        ]);
    }
}
