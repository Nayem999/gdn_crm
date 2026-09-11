<?php

namespace App\Livewire\Calendar;

use App\Domain\Activities\Calendar\CalendarBuilder;
use App\Domain\Activities\Calendar\CalendarGrid;
use App\Domain\Activities\Calendar\CalendarPeriod;
use App\Domain\Activities\Calendar\CalendarScale;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Models\Activity;
use App\Domain\Settings\DisplayTime;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tasks, calls and meetings on a month, week or day grid.
 *
 * Deliberately not a fifth mode of the data-view kit. The kit's views all
 * answer "which records match" and page through the answer; a calendar answers
 * "what is happening when", has no pager, and has to read a window chosen in
 * the office's timezone rather than the stored one. Sharing the machinery would
 * mean teaching the kit about both, and the board is already the one screen
 * that queries per column rather than per page.
 *
 * Everything the browser sends is checked before it reaches the query: the
 * scale and the type against their enums, the anchor against Carbon. The query
 * itself is `visibleTo()`, so an activity outside the viewer's access level is
 * not on the calendar at any scale.
 */
#[Title('Calendar')]
class ActivityCalendar extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'view', except: 'month')]
    public string $scale = 'month';

    /**
     * The day the period is built around, as Y-m-d on the office clock.
     */
    #[Url(as: 'on', except: '')]
    public string $anchor = '';

    #[Url(as: 'mine', except: false)]
    public bool $mineOnly = false;

    #[Url(as: 'type', except: '')]
    public string $type = '';

    #[Url(as: 'done', except: true)]
    public bool $showCompleted = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Activity::class);

        // Normalised on the way in rather than on every read: the anchor is in
        // the URL, so it arrives from wherever a link was pasted from.
        $this->anchor = $this->resolveAnchor($this->anchor)->format('Y-m-d');

        if (CalendarScale::tryFrom($this->scale) === null) {
            $this->scale = CalendarScale::Month->value;
        }
    }

    // -- What is on screen ---------------------------------------------------

    public function currentScale(): CalendarScale
    {
        return CalendarScale::tryFrom($this->scale) ?? CalendarScale::Month;
    }

    public function period(): CalendarPeriod
    {
        return CalendarPeriod::for($this->currentScale(), $this->resolveAnchor($this->anchor));
    }

    #[Computed]
    public function grid(): CalendarGrid
    {
        return app(CalendarBuilder::class)->build($this->query(), $this->period());
    }

    /**
     * @return Builder<Activity>
     */
    private function query(): Builder
    {
        $query = Activity::query()->visibleTo(auth()->user());

        if ($this->mineOnly) {
            $query->where('activities.owner_id', auth()->id());
        }

        // tryFrom, so a type the enum does not know is no filter at all rather
        // than a WHERE that matches nothing and looks like an empty calendar.
        $type = ActivityType::tryFrom($this->type);

        if ($type !== null) {
            $query->where('activities.type', $type->value);
        }

        if (! $this->showCompleted) {
            $query->where('activities.status', '!=', ActivityStatus::Completed->value);
        }

        return $query;
    }

    // -- Moving about --------------------------------------------------------

    public function setScale(string $scale): void
    {
        $resolved = CalendarScale::tryFrom($scale);

        if ($resolved === null) {
            return;
        }

        $this->scale = $resolved->value;
        unset($this->grid);
    }

    public function next(): void
    {
        $this->moveTo($this->period()->step(1)->anchor);
    }

    public function previous(): void
    {
        $this->moveTo($this->period()->step(-1)->anchor);
    }

    public function today(): void
    {
        $this->moveTo(DisplayTime::now());
    }

    /**
     * Clicking a day in the month grid drops into that day.
     */
    public function openDay(string $day): void
    {
        $this->moveTo($this->resolveAnchor($day));
        $this->scale = CalendarScale::Day->value;
        unset($this->grid);
    }

    private function moveTo(Carbon $day): void
    {
        $this->anchor = $day->format('Y-m-d');
        unset($this->grid);
    }

    /**
     * A date from the URL, or today when it is not one.
     *
     * Carbon parses a surprising amount of rubbish into a date, and throws on
     * the rest. Either way the calendar shows today rather than a 500 — the
     * anchor is the one piece of state a person can paste.
     */
    private function resolveAnchor(string $value): Carbon
    {
        if (trim($value) === '') {
            return DisplayTime::now();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, DisplayTime::timezone())
                ?: DisplayTime::now();
        } catch (InvalidFormatException) {
            return DisplayTime::now();
        }
    }

    // -- Chrome --------------------------------------------------------------

    /**
     * @return array<int, CalendarScale>
     */
    public function scales(): array
    {
        return CalendarScale::all();
    }

    /**
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        return ActivityType::options();
    }

    public function canCreate(): bool
    {
        return auth()->user()?->can('create', Activity::class) ?? false;
    }

    /**
     * The hours drawn down the side of a week or day grid.
     *
     * @return array<int, int>
     */
    public function hours(): array
    {
        return range(0, 23);
    }

    public function render(): View
    {
        return view('livewire.calendar.activity-calendar');
    }
}
