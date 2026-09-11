<?php

namespace App\Livewire\Activities;

use App\Domain\Activities\Actions\CancelActivityAction;
use App\Domain\Activities\Actions\CompleteActivityAction;
use App\Domain\Activities\Actions\CreateActivityAction;
use App\Domain\Activities\Actions\ReopenActivityAction;
use App\Domain\Activities\Actions\UpdateActivityAction;
use App\Domain\Activities\ActivityRelations;
use App\Domain\Activities\DTOs\ActivityData;
use App\Domain\Activities\Enums\ActivityPriority;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Enums\RecurrenceFrequency;
use App\Domain\Activities\Models\Activity;
use App\Domain\CustomFields\Concerns\WithCustomFieldForm;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Create and edit one task, call or meeting.
 *
 * There is no status control. The complete, reopen and cancel actions own
 * `status`, and the tick on the list and the buttons here are the ways to move
 * it — a form field that could set it would be a second path to "done" and the
 * two would eventually disagree about `completed_at`.
 */
class ActivityForm extends Component
{
    use AuthorizesRequests;
    use WithCustomFieldForm;

    /**
     * How many rows one page of the record picker returns.
     */
    public const PER_PAGE = 25;

    /**
     * The reminder lead times offered. A free-text number of minutes is a worse
     * control for the same job: nobody wants to be reminded in 37 minutes.
     */
    public const REMINDER_CHOICES = [0, 5, 15, 30, 60, 120, 1440];

    #[Locked]
    public ?int $activityId = null;

    public string $type = 'task';

    public string $subject = '';

    public ?string $description = null;

    public string $priority = '2';

    public ?string $due_date = null;

    public ?string $due_time = '09:00';

    public bool $all_day = false;

    public ?string $duration_minutes = null;

    public ?string $location = null;

    public ?string $reminder_minutes_before = null;

    public ?string $recurrence_frequency = null;

    public string $recurrence_interval = '1';

    /**
     * How the series stops: never, after so many occurrences, or on a date.
     * Kept as its own control because "repeat 5 times" and "repeat until March"
     * are two ways of saying one thing and offering both at once invites a
     * record that says both.
     */
    public string $recurrence_end = 'never';

    public ?string $recurrence_count = null;

    public ?string $recurrence_until = null;

    /**
     * Pre-selected when the form is opened from a record's page.
     */
    #[Url(as: 'about', except: null)]
    public ?string $related_module = null;

    #[Url(as: 'record', except: null)]
    public ?string $related_id = null;

    public ?string $owner_id = null;

    /**
     * The module whose custom fields this form shows.
     */
    public function customFieldModule(): string
    {
        return 'activities';
    }

    public function mount(?Activity $activity = null): void
    {
        if ($activity?->exists) {
            $this->authorize('update', $activity);

            $this->activityId = $activity->id;
            $this->type = $activity->type()->value;
            $this->subject = $activity->subject;
            $this->description = $activity->description;
            $this->priority = (string) $activity->priority()->value;
            $this->due_date = $activity->due_at->format('Y-m-d');
            $this->due_time = $activity->due_at->format('H:i');
            $this->all_day = $activity->all_day;
            $this->duration_minutes = $activity->duration_minutes === null ? null : (string) $activity->duration_minutes;
            $this->location = $activity->location;
            $this->reminder_minutes_before = $activity->reminder_minutes_before === null
                ? null
                : (string) $activity->reminder_minutes_before;
            $this->recurrence_frequency = $activity->recurrence_frequency;
            $this->recurrence_interval = (string) max(1, $activity->recurrence_interval);
            $this->recurrence_count = $activity->recurrence_count === null ? null : (string) $activity->recurrence_count;
            $this->recurrence_until = $activity->recurrence_until?->format('Y-m-d');
            $this->recurrence_end = match (true) {
                $activity->recurrence_count !== null => 'after',
                $activity->recurrence_until !== null => 'on',
                default => 'never',
            };
            $this->owner_id = (string) $activity->owner_id;

            $related = $activity->related;

            if ($related !== null) {
                $this->related_module = ActivityRelations::keyFor($related);
                $this->related_id = (string) $related->getKey();
            }

            $this->loadCustomFields($activity);

            return;
        }

        $this->authorize('create', Activity::class);

        $this->owner_id = (string) auth()->id();
        $this->due_date = now()->format('Y-m-d');

        // A record handed in by the URL still has to be one this person can
        // reach, or the form would name somebody else's account back to them.
        if ($this->related_module !== null && $this->related_id !== null && $this->relatedRecord() === null) {
            $this->related_module = null;
            $this->related_id = null;
        }

        $this->loadCustomFields();
    }

    public function activity(): ?Activity
    {
        return $this->activityId === null
            ? null
            : Activity::query()->whereKey($this->activityId)->first();
    }

    public function isEditing(): bool
    {
        return $this->activityId !== null;
    }

    /**
     * The record this activity is about, when the form is holding one that the
     * viewer can actually reach.
     */
    public function relatedRecord(): ?Model
    {
        $user = auth()->user();

        if ($user === null || $this->related_module === null || $this->related_id === null || $this->related_id === '') {
            return null;
        }

        return ActivityRelations::resolve($this->related_module, (int) $this->related_id, $user);
    }

    public function chosenType(): ActivityType
    {
        return ActivityType::tryFrom($this->type) ?? ActivityType::Task;
    }

    public function repeats(): bool
    {
        return RecurrenceFrequency::tryFrom((string) $this->recurrence_frequency) !== null;
    }

    /**
     * How the chosen interval reads back, so the person can see that "every 2
     * weeks" is what they asked for.
     */
    public function intervalHint(): string
    {
        $frequency = RecurrenceFrequency::tryFrom((string) $this->recurrence_frequency);

        if ($frequency === null) {
            return '';
        }

        return $frequency->intervalLabel(max(1, (int) $this->recurrence_interval)).'.';
    }

    /**
     * Whether this row is one appointment out of a series, in which case the
     * recurrence controls are not offered: editing an occurrence edits that
     * appointment, not the rule behind it.
     */
    public function isOccurrence(): bool
    {
        return $this->activity()?->isOccurrence() === true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(ActivityType::options()))],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'integer', 'in:'.implode(',', array_keys(ActivityPriority::options()))],
            'due_date' => ['required', 'date'],
            // Required unless the activity takes the whole day, which is the
            // one case where a time would be invented rather than chosen.
            'due_time' => ['exclude_if:all_day,true', 'required', 'date_format:H:i'],
            'all_day' => ['boolean'],
            // A day is the ceiling: anything longer is a project, not a meeting.
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'location' => ['nullable', 'string', 'max:255'],
            'reminder_minutes_before' => ['nullable', 'integer', 'in:'.implode(',', self::REMINDER_CHOICES)],
            'recurrence_frequency' => ['nullable', 'string', 'in:'.implode(',', array_keys(RecurrenceFrequency::options()))],
            'recurrence_interval' => ['required', 'integer', 'min:1', 'max:365'],
            'recurrence_end' => ['required', 'string', 'in:never,after,on'],
            'recurrence_count' => ['exclude_unless:recurrence_end,after', 'required', 'integer', 'min:2', 'max:365'],
            'recurrence_until' => ['exclude_unless:recurrence_end,on', 'required', 'date', 'after:due_date'],
            'related_module' => ['nullable', 'string', 'in:'.implode(',', ActivityRelations::keys())],
            'related_id' => ['nullable', 'integer', 'required_with:related_module'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'due_date' => 'due date',
            'due_time' => 'due time',
            'all_day' => 'all day',
            'duration_minutes' => 'duration',
            'reminder_minutes_before' => 'reminder',
            'recurrence_frequency' => 'repeat',
            'recurrence_interval' => 'repeat interval',
            'recurrence_count' => 'number of occurrences',
            'recurrence_until' => 'repeat end date',
            'related_module' => 'record type',
            'related_id' => 'record',
            'owner_id' => 'owner',
        ];
    }

    /**
     * Choosing another kind of record clears the chosen one: the picker below
     * lists a different table, and keeping the old id would attach a contact's
     * id to an account.
     */
    public function updatedRelatedModule(): void
    {
        $this->related_id = null;
    }

    public function save(): void
    {
        $activity = $this->activity();

        if ($activity === null) {
            $this->authorize('create', Activity::class);
        } else {
            $this->authorize('update', $activity);
        }

        $this->validate();

        $user = $this->currentUser();

        // Checked again after validation: `in` proves the module is one of ours
        // and `integer` proves the id is a number, never that this person may
        // reach the record behind it.
        if ($this->related_module !== null && $this->related_id !== null && $this->relatedRecord() === null) {
            $this->addError('related_id', 'That record is not one you can work with.');

            return;
        }

        $this->validateCustomFields($user);

        $data = ActivityData::fromArray([
            'type' => $this->type,
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => $this->priority,
            'due_at' => $this->all_day
                ? $this->due_date
                : $this->due_date.' '.$this->due_time,
            'all_day' => $this->all_day,
            'duration_minutes' => $this->duration_minutes,
            'location' => $this->location,
            'reminder_minutes_before' => $this->reminder_minutes_before,
            'recurrence_frequency' => $this->isOccurrence() ? null : $this->recurrence_frequency,
            'recurrence_interval' => $this->recurrence_interval,
            'recurrence_count' => $this->recurrence_end === 'after' ? $this->recurrence_count : null,
            'recurrence_until' => $this->recurrence_end === 'on' ? $this->recurrence_until : null,
            'related_module' => $this->related_module,
            'related_id' => $this->related_id,
            'owner_id' => $this->canAssign($activity) ? $this->owner_id : $activity?->owner_id,
        ]);

        try {
            $saved = $activity === null
                ? app(CreateActivityAction::class)($data, $user)
                : app(UpdateActivityAction::class)($activity, $data, $user);
        } catch (RuntimeException $exception) {
            $this->addError('subject', $exception->getMessage());

            return;
        }

        $saved->saveCustomFields($this->customFields);

        session()->flash('status', $saved->subject.' was saved.');

        $this->redirectRoute('activities.index', navigate: true);
    }

    // -- Status, from the form ----------------------------------------------

    public function complete(): void
    {
        $activity = $this->activity();

        if ($activity === null) {
            return;
        }

        $this->authorize('update', $activity);

        app(CompleteActivityAction::class)($activity, $activity->completion_notes);

        session()->flash('status', $activity->subject.' is done.');

        $this->redirectRoute('activities.index', navigate: true);
    }

    public function reopen(): void
    {
        $activity = $this->activity();

        if ($activity === null) {
            return;
        }

        $this->authorize('update', $activity);

        app(ReopenActivityAction::class)($activity);

        $this->dispatch('notify', type: 'success', message: $activity->subject.' is back on the list.');
    }

    public function cancelActivity(): void
    {
        $activity = $this->activity();

        if ($activity === null) {
            return;
        }

        $this->authorize('update', $activity);

        app(CancelActivityAction::class)($activity);

        session()->flash('status', $activity->subject.' was called off.');

        $this->redirectRoute('activities.index', navigate: true);
    }

    /**
     * Whether this person may choose the owner. Without it the field is not
     * rendered and a submitted owner is ignored server-side.
     */
    public function canAssign(?Activity $activity = null): bool
    {
        $activity ??= $this->activity();

        return $activity === null
            ? auth()->user()?->can('create', Activity::class) === true
            : auth()->user()?->can('assign', $activity) === true;
    }

    // -- Pickers -------------------------------------------------------------

    /**
     * The chosen kind of record, searched inside the viewer's access level.
     *
     * @return array<string, mixed>
     */
    public function searchRelated(?string $term = null, mixed $page = 1): array
    {
        // Typed loosely because Tom Select sends null on a preload and on a
        // cleared box; a string parameter turns that into a silent 500.
        $term = (string) ($term ?? '');
        $page = max(1, (int) $page);
        $user = auth()->user();

        if ($user === null || $this->related_module === null) {
            return ['options' => [], 'hasMore' => false];
        }

        $query = ActivityRelations::visibleQuery($this->related_module, $user, $term);

        if ($query === null) {
            return ['options' => [], 'hasMore' => false];
        }

        $rows = $query
            ->limit(self::PER_PAGE + 1)
            ->offset(($page - 1) * self::PER_PAGE)
            ->get();

        return [
            'options' => $rows->take(self::PER_PAGE)
                ->map(fn (Model $record) => [
                    'value' => (string) $record->getKey(),
                    'label' => ActivityRelations::label($record),
                    'description' => ActivityRelations::description($record),
                ])
                ->values()
                ->all(),
            'hasMore' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        return ActivityType::options();
    }

    /**
     * Int-keyed, because the ranks are numeric. See ActivityPriority::options().
     *
     * @return array<int, string>
     */
    public function priorityOptions(): array
    {
        return ActivityPriority::options();
    }

    /**
     * @return array<string, string>
     */
    public function relatedModuleOptions(): array
    {
        return ActivityRelations::options();
    }

    /**
     * @return array<string, string>
     */
    public function frequencyOptions(): array
    {
        return RecurrenceFrequency::options();
    }

    /**
     * Keyed by minutes before the due time. Written as strings for the
     * dropdown, but a numeric string key is an int once PHP holds it.
     *
     * @return array<int, string>
     */
    public function reminderOptions(): array
    {
        $options = [];

        foreach (self::REMINDER_CHOICES as $minutes) {
            $options[(string) $minutes] = match (true) {
                $minutes === 0 => 'At the time it is due',
                $minutes < 60 => $minutes.' minutes before',
                $minutes < 1440 => ($minutes / 60).' '.str('hour')->plural((int) ($minutes / 60)).' before',
                default => '1 day before',
            };
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function recurrenceEndOptions(): array
    {
        return [
            'never' => 'Keep repeating',
            'after' => 'After a number of times',
            'on' => 'On a date',
        ];
    }

    /**
     * PHP casts a numeric string array key back to int, so the id keys here are
     * ints however they are written — typed as such rather than pretending.
     *
     * @return array<int, string>
     */
    public function ownerOptions(): array
    {
        $options = [];

        foreach (User::query()->orderBy('name')->get() as $user) {
            $options[$user->id] = $user->name;
        }

        return $options;
    }

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
        return view('livewire.activities.activity-form')
            ->title($this->isEditing() ? 'Edit activity' : 'Add activity');
    }
}
