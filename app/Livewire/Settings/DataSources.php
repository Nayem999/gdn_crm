<?php

namespace App\Livewire\Settings;

use App\Domain\Ingestion\Actions\ApplyBlueprintAction;
use App\Domain\Ingestion\Actions\CreateDataSourceAction;
use App\Domain\Ingestion\Actions\DeleteDataSourceAction;
use App\Domain\Ingestion\Actions\IssueSourceSecretAction;
use App\Domain\Ingestion\Actions\RevokeSourceSecretAction;
use App\Domain\Ingestion\Actions\UpdateDataSourceAction;
use App\Domain\Ingestion\DTOs\DataSourceData;
use App\Domain\Ingestion\Enums\DataSourceType;
use App\Domain\Ingestion\IngestionBlueprints;
use App\Domain\Ingestion\IngestionTargets;
use App\Domain\Ingestion\IpRange;
use App\Domain\Ingestion\Models\DataSource;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Where records are allowed to come in from.
 *
 * The same inline-form shape the webhooks screen uses: a settings screen is a
 * short list somebody visits to change one thing, not a data-view list of
 * business records.
 *
 * There is no secret here yet. Task 8.2 owns key generation, one-time display
 * and rotation, and this screen will grow a panel for it — deliberately after
 * the source itself exists, so that a key is minted for something already
 * pointed at a module rather than the other way round.
 */
#[Title('Data sources')]
class DataSources extends Component
{
    use AuthorizesRequests;

    public bool $editing = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public ?string $description = null;

    public string $type = 'push';

    public ?string $target_module = null;

    public bool $is_active = true;

    public bool $is_sandbox = false;

    public bool $requires_key = true;

    public bool $requires_signature = true;

    /**
     * Addresses allowed to post, one per line. Free text on the way in and a
     * list on the way out, because that is how somebody pastes a set of them.
     */
    public string $ip_allowlist = '';

    /**
     * A ready-made configuration to start from, on a new source only.
     *
     * Offered when creating and not when editing: a template applied to a
     * source somebody has already tuned would replace their rules with the
     * example's, which is the opposite of helpful.
     */
    public ?string $blueprint = null;

    /**
     * A freshly minted pair, in plain text, for as long as this page is open.
     *
     * Held in component state only until the next full page load. It is never
     * read back from the database — the key cannot be, and the signing secret
     * deliberately is not, because a screen that offered to show it again would
     * be a screen that decrypts a credential on demand.
     */
    public ?string $revealedKey = null;

    public ?string $revealedSigningSecret = null;

    #[Locked]
    public ?int $revealedFor = null;

    public function mount(): void
    {
        $this->authorize('viewAny', DataSource::class);
    }

    /**
     * @return Collection<int, DataSource>
     */
    public function sources(): Collection
    {
        return DataSource::query()
            ->withCount('events')
            ->with('createdBy:id,name')
            ->orderBy('name')
            ->get();
    }

    public function canManage(): bool
    {
        return auth()->user()?->can('integrations.manage') === true;
    }

    /**
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        return DataSourceType::options();
    }

    /**
     * @return array<string, string>
     */
    public function targetOptions(): array
    {
        return IngestionTargets::options();
    }

    public function chosenType(): DataSourceType
    {
        return DataSourceType::tryFrom($this->type) ?? DataSourceType::Push;
    }

    // -- The form ------------------------------------------------------------

    public function add(): void
    {
        $this->authorize('create', DataSource::class);

        $this->reset(['editingId', 'name', 'description', 'target_module', 'ip_allowlist', 'blueprint']);
        $this->type = DataSourceType::Push->value;
        $this->is_active = true;
        $this->is_sandbox = false;
        // The safe arrangement is the one somebody gets without choosing.
        $this->requires_key = true;
        $this->requires_signature = true;
        $this->editing = true;
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $source = DataSource::query()->findOrFail($id);

        $this->authorize('update', $source);

        $this->editingId = $source->id;
        $this->name = $source->name;
        $this->description = $source->description;
        $this->type = $source->type()->value;
        $this->target_module = $source->target_module;
        $this->is_active = $source->is_active;
        $this->is_sandbox = $source->is_sandbox;
        $this->requires_key = $source->requires_key;
        $this->requires_signature = $source->requires_signature;
        $this->ip_allowlist = implode('
', $source->ip_allowlist ?? []);
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'editingId', 'name', 'description', 'target_module', 'ip_allowlist', 'blueprint']);
        $this->resetValidation();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'string', Rule::in(array_keys(DataSourceType::options()))],
            // Rule::in over the registry, so the list the form offers and the
            // list the action accepts cannot drift apart.
            'target_module' => ['required', 'string', Rule::in(IngestionTargets::keys())],
            'is_active' => ['boolean'],
            'is_sandbox' => ['boolean'],
            'requires_key' => ['boolean'],
            'requires_signature' => ['boolean'],
            'ip_allowlist' => ['nullable', 'string', 'max:2000'],
            // Matched against the registry, so nothing from a form can name a
            // configuration that does not exist.
            'blueprint' => ['nullable', 'string', Rule::in(IngestionBlueprints::keys())],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'target_module' => 'target module',
            'is_active' => 'enabled',
            'is_sandbox' => 'sandbox mode',
            'requires_key' => 'key required',
            'requires_signature' => 'signature required',
            'ip_allowlist' => 'allowed addresses',
        ];
    }

    /**
     * A template decides which module it writes into, so choosing one moves
     * that control rather than leaving the two disagreeing on screen.
     */
    public function updatedBlueprint(): void
    {
        $blueprint = $this->blueprint === null ? null : IngestionBlueprints::find($this->blueprint);

        if ($blueprint !== null) {
            $this->target_module = $blueprint['target_module'];
        }
    }

    /**
     * @return array<string, string>
     */
    public function blueprintOptions(): array
    {
        return IngestionBlueprints::options();
    }

    public function save(): void
    {
        $source = $this->source();

        $source === null
            ? $this->authorize('create', DataSource::class)
            : $this->authorize('update', $source);

        $this->validate();

        // A source that authenticates nothing is an open door with a uuid on
        // it. Refused here rather than left to the endpoint, where it would be
        // a working configuration nobody had decided to make.
        if (! $this->requires_key && ! $this->requires_signature) {
            $this->addError('requires_key', 'A source must check a key, a signature, or both.');

            return;
        }

        $allowlist = $this->parsedAllowlist();
        $invalid = array_values(array_filter($allowlist, fn (string $entry) => ! IpRange::isValid($entry)));

        if ($invalid !== []) {
            // Refused rather than stored: a typo in an allowlist is a rule that
            // silently matches nothing, which looks exactly like a rule that
            // is working.
            $this->addError('ip_allowlist', 'Not an address or range: '.implode(', ', array_slice($invalid, 0, 3)).'.');

            return;
        }

        $data = DataSourceData::fromArray([
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'target_module' => $this->target_module,
            'is_active' => $this->is_active,
            'is_sandbox' => $this->is_sandbox,
            'requires_key' => $this->requires_key,
            'requires_signature' => $this->requires_signature,
            'ip_allowlist' => $allowlist,
        ]);

        try {
            $saved = $source === null
                ? app(CreateDataSourceAction::class)($data, $this->currentUser())
                : app(UpdateDataSourceAction::class)($source, $data);

            // Only on a new source. Applying a template over one somebody has
            // already tuned would replace their rules with the example's.
            if ($source === null && $this->blueprint !== null) {
                app(ApplyBlueprintAction::class)($saved, $this->blueprint);
            }
        } catch (RuntimeException $exception) {
            $this->addError('target_module', $exception->getMessage());

            return;
        }

        $this->editing = false;
        $this->editingId = null;
        $this->dispatch('data-source-saved');
    }

    // -- Credentials ---------------------------------------------------------

    public function canManageSecrets(): bool
    {
        return auth()->user()?->can('integrations.secrets') === true;
    }

    /**
     * Mint a key. The same call rotates one, because they are the same act —
     * see IssueSourceSecretAction.
     */
    public function issueSecret(int $id): void
    {
        $source = DataSource::query()->findOrFail($id);

        $this->authorize('manageSecrets', $source);

        $rotating = $source->hasSecret();

        $credentials = app(IssueSourceSecretAction::class)($source);

        // The one and only time these values are on a screen.
        $this->revealedKey = $credentials->key;
        $this->revealedSigningSecret = $credentials->signingSecret;
        $this->revealedFor = $source->id;

        $grace = $source->fresh()?->graceEndsAt();

        $this->dispatch(
            'notify',
            type: 'success',
            message: $rotating
                ? ($grace === null
                    ? 'Rotated. The old key stopped working immediately.'
                    : 'Rotated. The old key keeps working until '.$grace->format('j M Y, H:i').'.')
                : 'Key issued. Copy it now — it cannot be shown again.',
        );
    }

    public function revokeSecret(int $id): void
    {
        $source = DataSource::query()->findOrFail($id);

        $this->authorize('manageSecrets', $source);

        app(RevokeSourceSecretAction::class)($source);

        $this->dismissSecret();

        $this->dispatch(
            'notify',
            type: 'success',
            message: $source->name.' can no longer be authenticated. Issue a new key when the integration is ready.',
        );
    }

    /**
     * Put the revealed pair away.
     *
     * Pressed once the administrator has copied it. It also goes on any full
     * page load, because it lives in component state and nothing reads it back
     * from the database — so a refresh loses it, permanently, which is the
     * behaviour the panel warns about.
     */
    public function dismissSecret(): void
    {
        $this->revealedKey = null;
        $this->revealedSigningSecret = null;
        $this->revealedFor = null;
    }

    // -- The switches --------------------------------------------------------

    /**
     * Turning a source off is the fastest thing an administrator can do when an
     * integration starts sending rubbish, so it is one click from the list
     * rather than a trip through the form.
     *
     * Not through UpdateDataSourceAction: that action exists to guard the
     * target module, and a toggle cannot change it. If anything else ever
     * switches a source off — 8.9's failure alerting is the obvious candidate —
     * this should become an action of its own, so the two paths cannot end up
     * disagreeing about what else happens when one goes quiet.
     */
    public function toggleActive(int $id): void
    {
        $source = DataSource::query()->findOrFail($id);

        $this->authorize('update', $source);

        $source->update(['is_active' => ! $source->is_active]);

        $this->dispatch(
            'notify',
            type: 'success',
            message: $source->name.' is '.($source->is_active ? 'accepting deliveries.' : 'switched off.'),
        );
    }

    public function toggleSandbox(int $id): void
    {
        $source = DataSource::query()->findOrFail($id);

        $this->authorize('update', $source);

        $source->update(['is_sandbox' => ! $source->is_sandbox]);

        $this->dispatch(
            'notify',
            type: 'success',
            message: $source->is_sandbox
                ? $source->name.' is in sandbox: deliveries are captured but nothing is written.'
                : $source->name.' is live: deliveries now write records.',
        );
    }

    public function delete(int $id): void
    {
        $source = DataSource::query()->findOrFail($id);

        $this->authorize('delete', $source);

        app(DeleteDataSourceAction::class)($source);

        $this->dispatch('notify', type: 'success', message: $source->name.' was removed.');
    }

    /**
     * The allowlist box, as a list. Blank lines and stray spaces dropped,
     * because people paste these from a spreadsheet.
     *
     * @return array<int, string>
     */
    public function parsedAllowlist(): array
    {
        $entries = preg_split('/[
,]+/', $this->ip_allowlist) ?: [];

        return array_values(array_filter(array_map('trim', $entries), fn (string $entry) => $entry !== ''));
    }

    private function source(): ?DataSource
    {
        return $this->editingId === null
            ? null
            : DataSource::query()->findOrFail($this->editingId);
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
        return view('livewire.settings.data-sources', [
            'sources' => $this->sources(),
        ]);
    }
}
