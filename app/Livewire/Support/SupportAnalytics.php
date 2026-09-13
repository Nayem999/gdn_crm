<?php

namespace App\Livewire\Support;

use App\Domain\Support\Analytics\AgentPerformance;
use App\Domain\Support\Analytics\SupportMetrics;
use App\Domain\Support\Analytics\SupportPeriod;
use App\Domain\Support\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What the desk did, over a window.
 *
 * Every figure is drawn through the viewer's own access level, so a screen can
 * never report a number taken from tickets that person could not open one by
 * one. That is why the metrics take the user rather than reading auth() for
 * themselves — a queued report in a later phase runs with no session.
 */
#[Title('Support analytics')]
class SupportAnalytics extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'period', except: '30')]
    public string $periodKey = '30';

    public function mount(): void
    {
        $this->authorize('analytics', Ticket::class);
    }

    public function period(): SupportPeriod
    {
        return SupportPeriod::fromKey($this->periodKey);
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return SupportPeriod::options();
    }

    /**
     * @return array{raised: int, resolved: int, open_now: int, reopened: int, breached: int}
     */
    #[Computed]
    public function volume(): array
    {
        return app(SupportMetrics::class)->volume($this->period(), auth()->user());
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function byDay(): array
    {
        return app(SupportMetrics::class)->volumeByDay($this->period(), auth()->user());
    }

    /**
     * @return array{count: int, average: float|null, median: float|null, within_sla: float|null}
     */
    #[Computed]
    public function resolution(): array
    {
        return app(SupportMetrics::class)->resolutionTime($this->period(), auth()->user());
    }

    /**
     * @return array{count: int, average: float|null, median: float|null, unanswered: int}
     */
    #[Computed]
    public function firstResponse(): array
    {
        return app(SupportMetrics::class)->firstResponseTime($this->period(), auth()->user());
    }

    /**
     * @return array<int, AgentPerformance>
     */
    #[Computed]
    public function agents(): array
    {
        return app(SupportMetrics::class)->agentPerformance($this->period(), auth()->user());
    }

    /**
     * @return array{priority: array<string, int>, source: array<string, int>, status: array<string, int>}
     */
    #[Computed]
    public function breakdown(): array
    {
        return app(SupportMetrics::class)->breakdown($this->period(), auth()->user());
    }

    /**
     * The tallest bar in the daily chart, so the others can be drawn against
     * it. At least one, or an empty window divides by zero.
     *
     * Takes the series rather than reading the computed property, so the view
     * hands it the same cached array it is drawing from instead of running the
     * query a second time.
     *
     * @param  array<string, int>  $days
     */
    public function busiestDay(array $days): int
    {
        return max(1, ...array_values($days) ?: [1]);
    }

    public function updatedPeriodKey(): void
    {
        // Every figure is drawn from the window, so all of them go when it
        // changes — a stale one beside a fresh one is the worst outcome here.
        unset($this->volume, $this->byDay, $this->resolution, $this->firstResponse, $this->agents, $this->breakdown);
    }

    /**
     * Hours as something readable, or a dash when there is nothing to say.
     */
    public function hours(?float $hours): string
    {
        if ($hours === null) {
            return '—';
        }

        if ($hours < 1) {
            return round($hours * 60).' min';
        }

        return $hours < 48
            ? round($hours, 1).' h'
            : round($hours / 24, 1).' d';
    }

    public function minutes(?float $minutes): string
    {
        return $minutes === null ? '—' : $this->hours($minutes / 60);
    }

    public function render(): View
    {
        return view('livewire.support.support-analytics');
    }
}
