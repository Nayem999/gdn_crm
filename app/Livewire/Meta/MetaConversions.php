<?php

namespace App\Livewire\Meta;

use App\Domain\Meta\Conversions\Actions\SendConversionAction;
use App\Domain\Meta\Conversions\Enums\ConversionStatus;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaConversionEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * What this CRM has told Meta, and what Meta said back.
 *
 * The screen answers one question people actually ask — "is Meta being told
 * about the business we win?" — which is unanswerable without it: the API
 * accepts a request and reports the rejection *inside* a 200, so an integration
 * that is failing every event looks exactly like one that is working.
 *
 * Retry is deliberately a button rather than an endless queue. The failures are
 * almost always a token or a dataset id, and a person has to fix those before
 * another attempt can do anything but fail again.
 */
#[Title('Meta conversions')]
class MetaConversions extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url(as: 'status', except: '')]
    public string $status = '';

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->authorize('viewAny', MetaAccount::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Send one again, now that whatever was wrong has been put right.
     */
    public function retry(int $eventId): void
    {
        $this->authorize('update', MetaAccount::query()->latest('id')->firstOrFail());

        $event = MetaConversionEvent::query()->find($eventId);

        if ($event === null || ! $event->status()->isRetryable()) {
            return;
        }

        $sent = app(SendConversionAction::class)($event);

        $this->notice = $sent ? 'Meta accepted it this time.' : null;
        $this->error = $sent ? null : ($event->fresh()->error ?? 'Meta refused it again.');
    }

    /**
     * @return LengthAwarePaginator<int, MetaConversionEvent>
     */
    public function events(): LengthAwarePaginator
    {
        return MetaConversionEvent::query()
            ->with('subject')
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->latest('id')
            ->paginate(25);
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return MetaConversionEvent::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    public function datasetId(): ?string
    {
        return app(MetaConfiguration::class)->datasetId();
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        $options = ['' => 'Every event'];

        foreach (ConversionStatus::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function render(): View
    {
        return view('livewire.meta.meta-conversions', [
            'events' => $this->events(),
            'counts' => $this->counts(),
        ]);
    }
}
