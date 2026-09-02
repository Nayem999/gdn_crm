<?php

namespace App\Livewire\Users;

use App\Domain\Users\Actions\AcceptInvitationAction;
use App\Domain\Users\Models\UserInvitation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AcceptInvitation extends Component
{
    /** Never re-accepted from the client, so it can't be swapped mid-flow. */
    #[Locked]
    public string $token = '';

    #[Locked]
    public string $email = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $invitation = UserInvitation::findPendingByToken($token);

        // A used, expired or unknown token is indistinguishable from the outside,
        // so nothing leaks about which addresses have been invited.
        if ($invitation === null) {
            throw new NotFoundHttpException('This invitation link is no longer valid.');
        }

        $this->token = $token;
        $this->email = $invitation->email;
        $this->name = (string) $invitation->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function accept(AcceptInvitationAction $action): void
    {
        $validated = $this->validate();

        $invitation = UserInvitation::findPendingByToken($this->token);

        if ($invitation === null) {
            $this->addError('token', 'This invitation link is no longer valid.');

            return;
        }

        try {
            $user = $action($invitation, $validated['name'], $validated['password']);
        } catch (RuntimeException $exception) {
            $this->addError('token', $exception->getMessage());

            return;
        }

        // SessionGuard::login() regenerates the session id itself, so there is no
        // separate fixation guard to add here.
        auth()->login($user);

        $this->redirectRoute('dashboard', navigate: false);
    }

    public function render(): View
    {
        // Sits on the guest shell rather than the app shell, since the visitor
        // has no account yet.
        return view('livewire.users.accept-invitation')
            ->layout('components.layouts.guest', [
                'title' => 'Accept invitation',
                'heading' => 'Accept your invitation',
                'subheading' => 'Set a password to finish creating your account for '.$this->email.'.',
            ]);
    }
}
