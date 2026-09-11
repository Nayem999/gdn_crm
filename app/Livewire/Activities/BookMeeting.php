<?php

namespace App\Livewire\Activities;

use App\Domain\Activities\Actions\BookMeetingAction;
use App\Domain\Activities\ActivityRelations;
use App\Domain\Activities\Models\Activity;
use App\Domain\Activities\Scheduling\AvailabilityFinder;
use App\Domain\Activities\Scheduling\Slot;
use App\Domain\Activities\Scheduling\WorkingHours;
use App\Domain\Settings\DisplayTime;
use App\Models\User;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Book a meeting with the person or company whose page this is.
 *
 * The record reaches the component as a **module key and an id**, never a class
 * name, and is resolved through ActivityRelations against the viewer's own
 * scope — the same rule the activity form follows.
 *
 * The slot list is a snapshot and is treated as one: the action re-checks the
 * span before it writes, so two people looking at the same afternoon cannot
 * both book it.
 */
class BookMeeting extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $module;

    #[Locked]
    public int $recordId;

    public bool $open = false;

    /** The day being looked at, Y-m-d on the office clock. */
    public string $date = '';

    public int $minutes = 30;

    public int $ownerId = 0;

    public string $subject = '';

    public string $location = '';

    public string $description = '';

    /** The chosen slot, as Slot::value() writes it. */
    public string $slot = '';

    public function mount(string $module, int $record): void
    {
        if (! ActivityRelations::has($module)) {
            abort(404);
        }

        $this->module = $module;
        $this->recordId = $record;

        // Reaching the record is the first gate; being allowed to schedule
        // anything is the second.
        $this->subjectRecord();

        $this->ownerId = $this->currentUser()->id;
        $this->minutes = WorkingHours::defaultMeetingMinutes();
        $this->date = $this->nextWorkingDay()->format('Y-m-d');
    }

    /**
     * The record the meeting is about, through the viewer's own scope.
     */
    public function subjectRecord(): Model
    {
        $record = ActivityRelations::resolve($this->module, $this->recordId, $this->currentUser());

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    public function canBook(): bool
    {
        return $this->currentUser()->can('create', Activity::class);
    }

    public function canAssign(): bool
    {
        return $this->currentUser()->can('activities.assign');
    }

    // -- The slot list --------------------------------------------------------

    public function chosenDay(): Carbon
    {
        try {
            $day = Carbon::createFromFormat('Y-m-d', $this->date, DisplayTime::timezone());
        } catch (InvalidFormatException) {
            $day = null;
        }

        return $day ?: $this->nextWorkingDay();
    }

    /**
     * @return array<int, Slot>
     */
    #[Computed]
    public function slots(): array
    {
        return app(AvailabilityFinder::class)->slotsFor(
            $this->resolvedOwnerId(),
            $this->chosenDay(),
            $this->minutes,
        );
    }

    public function isWorkingDay(): bool
    {
        return WorkingHours::isWorkingDay($this->chosenDay());
    }

    public function chooseSlot(string $value): void
    {
        // Only a slot this screen actually offered, and only a free one. The
        // value is a time, so nothing is looked up by it — but accepting one
        // the list did not contain would let a booking land outside the
        // working day.
        foreach ($this->slots() as $slot) {
            if ($slot->value() === $value && $slot->isFree()) {
                $this->slot = $value;

                return;
            }
        }
    }

    public function updatedDate(): void
    {
        $this->slot = '';
        unset($this->slots);
    }

    public function updatedMinutes(): void
    {
        $this->slot = '';
        unset($this->slots);
    }

    public function updatedOwnerId(): void
    {
        $this->slot = '';
        unset($this->slots);
    }

    public function step(int $days): void
    {
        $this->date = $this->chosenDay()->addDays($days)->format('Y-m-d');
        $this->updatedDate();
    }

    // -- Booking --------------------------------------------------------------

    public function book(): void
    {
        $this->authorize('create', Activity::class);

        $this->validate([
            'subject' => ['required', 'string', 'min:2', 'max:255'],
            'slot' => ['required', 'string'],
            'minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], [], ['slot' => 'time']);

        try {
            $activity = app(BookMeetingAction::class)(
                slot: $this->slot,
                minutes: $this->minutes,
                ownerId: $this->resolvedOwnerId(),
                subject: $this->subject,
                actor: $this->currentUser(),
                relatedModule: $this->module,
                relatedId: $this->recordId,
                location: $this->location === '' ? null : $this->location,
                description: $this->description === '' ? null : $this->description,
            );
        } catch (RuntimeException $e) {
            // A taken slot is not a validation failure of the form, but it is
            // the same thing to the person looking at it: say so on the field
            // they would otherwise press again.
            unset($this->slots);
            $this->slot = '';
            $this->addError('slot', $e->getMessage());

            return;
        }

        $this->reset(['slot', 'subject', 'location', 'description']);
        $this->open = false;
        unset($this->slots);

        $this->dispatch('notify', type: 'success', message: 'Meeting booked for '.DisplayTime::dateTime($activity->due_at).'.');

        // The record's timeline gains a strand entry, so it needs to re-read.
        $this->dispatch('timeline-changed');
    }

    /**
     * Whose diary is being booked.
     *
     * Somebody without the assign permission books their own, whatever the
     * payload says — the same rule the activity form applies to its owner
     * field.
     */
    private function resolvedOwnerId(): int
    {
        if (! $this->canAssign()) {
            return $this->currentUser()->id;
        }

        return User::query()->whereKey($this->ownerId)->exists()
            ? $this->ownerId
            : $this->currentUser()->id;
    }

    /**
     * Today if it is a working day, otherwise the next one. Opening the picker
     * on a Saturday with "no slots" is a worse first impression than opening it
     * on Monday.
     */
    private function nextWorkingDay(): Carbon
    {
        $day = DisplayTime::now()->startOfDay();

        for ($i = 0; $i < 7; $i++) {
            if (WorkingHours::isWorkingDay($day)) {
                return $day;
            }

            $day->addDay();
        }

        return DisplayTime::now()->startOfDay();
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    // -- Chrome ---------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    public function lengthOptions(): array
    {
        return WorkingHours::meetingLengths();
    }

    /**
     * @return array<int, string>
     */
    public function ownerOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function render(): View
    {
        return view('livewire.activities.book-meeting');
    }
}
