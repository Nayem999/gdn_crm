<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The keys an integration authenticates with.
 *
 * **A key belongs to a person, and acts as that person.** That is the whole
 * security model: an integration sees exactly what its owner sees, obeys the
 * same policies, and stops working the day the account is disabled. The
 * alternative — a service account with its own permissions — is a second set of
 * rules to keep in step, and the one that drifts is the one nobody looks at.
 *
 * The plain text is shown once and never again. It is stored hashed, so
 * "show it to me again" is not a feature that was left out; it is not possible,
 * and saying so plainly is better than a screen that looks like it lost it.
 */
#[Title('API keys')]
class ApiTokens extends Component
{
    public string $name = '';

    /**
     * Read-only or read and write. A key that can only read is the right
     * default for the reporting tool somebody is about to point at this.
     */
    public string $access = 'read';

    /**
     * The key, in plain text, for exactly as long as this page is open.
     */
    public ?string $plainTextToken = null;

    public function mount(): void
    {
        abort_unless($this->canManage(), 403);
    }

    public function canManage(): bool
    {
        return auth()->user()?->can('api.tokens') ?? false;
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function tokens(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection;
        }

        /** @var Collection<int, PersonalAccessToken> $tokens */
        $tokens = $user->tokens()->orderByDesc('id')->get();

        return $tokens;
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'access' => ['required', Rule::in(['read', 'write'])],
        ], [], ['name' => 'key name']);

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        // A write key can read as well; a read key cannot write. Spelling both
        // out rather than relying on "write implies read" keeps the check at
        // the route honest — it asks for exactly one ability.
        $abilities = $this->access === 'write' ? ['read', 'write'] : ['read'];

        $this->plainTextToken = $user->createToken($this->name, $abilities)->plainTextToken;

        $this->reset(['name']);
        $this->access = 'read';
    }

    public function revoke(int $tokenId): void
    {
        abort_unless($this->canManage(), 403);

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        // Scoped to the signed-in person's own tokens: an id from the browser
        // must not be able to revoke somebody else's integration.
        $user->tokens()->whereKey($tokenId)->delete();

        $this->plainTextToken = null;
    }

    public function dismissToken(): void
    {
        $this->plainTextToken = null;
    }

    public function render(): View
    {
        return view('livewire.settings.api-tokens', [
            'tokens' => $this->tokens(),
        ]);
    }
}
