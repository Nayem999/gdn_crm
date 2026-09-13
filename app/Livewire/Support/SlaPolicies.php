<?php

namespace App\Livewire\Support;

use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Models\SlaPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What the company promises, and how long it has.
 *
 * The targets are edited on the policy itself rather than behind a second
 * screen: "four hours to answer an urgent ticket, one day for a normal one" is
 * one decision, and splitting it across two pages is how a policy ends up half
 * configured.
 *
 * An empty target means **no promise at that priority**, which is different
 * from zero minutes. The form says so, because a blank box that quietly meant
 * "immediately" would breach every ticket the moment it was saved.
 */
#[Title('SLA policies')]
class SlaPolicies extends Component
{
    use AuthorizesRequests;

    public ?int $editingId = null;

    public bool $editing = false;

    public string $name = '';

    public string $description = '';

    public bool $isDefault = false;

    public bool $isActive = true;

    /**
     * Set in resetForm() from SlaPolicy::DEFAULT_WARN_PERCENT rather than
     * declared here: a cast is not a constant expression, and repeating the
     * number as a literal would let the two drift.
     */
    public string $warnAtPercent = '';

    /**
     * Minutes per priority, keyed by the stored rank.
     *
     * Strings, because that is what a text input carries, and an empty one has
     * to survive the round trip as "no promise" rather than becoming 0.
     *
     * @var array<int, array{first_response: string, resolution: string}>
     */
    public array $targets = [];

    public function mount(): void
    {
        $this->authorize('viewAny', SlaPolicy::class);

        $this->resetForm();
    }

    /**
     * @return Collection<int, SlaPolicy>
     */
    #[Computed]
    public function policies(): Collection
    {
        return SlaPolicy::query()
            ->with('targets')
            ->withCount('tickets')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function priorities(): array
    {
        $options = [];

        foreach (TicketPriority::cases() as $priority) {
            $options[$priority->value] = $priority->label();
        }

        return $options;
    }

    public function create(): void
    {
        $this->authorize('create', SlaPolicy::class);

        $this->resetForm();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $policy = SlaPolicy::query()->with('targets')->findOrFail($id);

        $this->authorize('update', $policy);

        $this->editingId = $policy->id;
        $this->name = $policy->name;
        $this->description = (string) $policy->description;
        $this->isDefault = $policy->is_default;
        $this->isActive = $policy->is_active;
        $this->warnAtPercent = (string) $policy->warn_at_percent;

        $this->targets = [];

        foreach (TicketPriority::cases() as $priority) {
            $target = $policy->targetFor($priority);

            $this->targets[$priority->value] = [
                'first_response' => $target === null ? '' : (string) $target->first_response_minutes,
                'resolution' => $target === null ? '' : (string) $target->resolution_minutes,
            ];
        }

        $this->editing = true;
    }

    public function save(): void
    {
        $policy = $this->editingId === null ? null : SlaPolicy::query()->findOrFail($this->editingId);

        $policy === null
            ? $this->authorize('create', SlaPolicy::class)
            : $this->authorize('update', $policy);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            // 1 to 99: a warning at 0 would fire the moment the clock starts
            // and one at 100 would arrive with the breach.
            'warnAtPercent' => ['required', 'integer', 'min:1', 'max:99'],
            'targets.*.first_response' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'targets.*.resolution' => ['nullable', 'integer', 'min:1', 'max:525600'],
        ], attributes: ['warnAtPercent' => 'warning point']);

        DB::transaction(function () use (&$policy) {
            $policy ??= new SlaPolicy;

            $policy->forceFill([
                'name' => $this->name,
                'description' => $this->description === '' ? null : $this->description,
                'is_active' => $this->isActive,
                'is_default' => $this->isDefault,
                'warn_at_percent' => (int) $this->warnAtPercent,
            ])->save();

            // Exactly one default among the live rows. Done here rather than
            // with a unique index because the table soft-deletes, and a removed
            // policy that was the default must not block the next one.
            if ($this->isDefault) {
                SlaPolicy::query()->whereKeyNot($policy->id)->update(['is_default' => false]);
            }

            foreach (TicketPriority::cases() as $priority) {
                $row = $this->targets[$priority->value] ?? ['first_response' => '', 'resolution' => ''];

                $first = $this->minutes($row['first_response']);
                $resolution = $this->minutes($row['resolution']);

                if ($first === null && $resolution === null) {
                    // No promise at this priority: the row goes rather than
                    // being stored as a pair of nulls somebody has to read as
                    // "nothing".
                    $policy->targets()->where('priority', $priority->value)->delete();

                    continue;
                }

                $policy->targets()->updateOrCreate(
                    ['priority' => $priority->value],
                    ['first_response_minutes' => $first, 'resolution_minutes' => $resolution],
                );
            }
        });

        unset($this->policies);

        $this->editing = false;
        $this->resetForm();

        $this->dispatch('notify', type: 'success', message: 'The policy has been saved.');
    }

    public function makeDefault(int $id): void
    {
        $policy = SlaPolicy::query()->findOrFail($id);

        $this->authorize('update', $policy);

        DB::transaction(function () use ($policy) {
            SlaPolicy::query()->whereKeyNot($policy->id)->update(['is_default' => false]);
            $policy->forceFill(['is_default' => true, 'is_active' => true])->save();
        });

        unset($this->policies);
    }

    public function delete(int $id): void
    {
        $policy = SlaPolicy::query()->findOrFail($id);

        $this->authorize('delete', $policy);

        // Soft-deleted, and the tickets keep their due times: a promise already
        // made does not stop existing because somebody tidied the list.
        $policy->delete();

        unset($this->policies);

        $this->dispatch('notify', type: 'success', message: $policy->name.' has been removed.');
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetForm();
    }

    /**
     * An empty box is no promise, not zero minutes.
     */
    private function minutes(string $value): ?int
    {
        $value = trim($value);

        return $value === '' ? null : max(1, (int) $value);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->isDefault = false;
        $this->isActive = true;
        $this->warnAtPercent = (string) SlaPolicy::DEFAULT_WARN_PERCENT;
        $this->targets = [];

        foreach (TicketPriority::cases() as $priority) {
            $this->targets[$priority->value] = ['first_response' => '', 'resolution' => ''];
        }

        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.support.sla-policies');
    }
}
