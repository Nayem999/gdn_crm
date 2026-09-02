<?php

namespace App\Domain\Auth\Listeners;

use App\Domain\Auth\Enums\LoginEvent;
use App\Domain\Auth\Models\LoginHistory;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Writes an audit row for every sign-in, sign-out, failed attempt and lockout.
 */
class RecordLoginHistory
{
    public function handleLogin(Login $event): void
    {
        $this->record(LoginEvent::Login, $event->user);
    }

    public function handleLogout(Logout $event): void
    {
        $this->record(LoginEvent::Logout, $event->user);
    }

    public function handleFailed(Failed $event): void
    {
        $this->record(
            LoginEvent::Failed,
            $event->user,
            $this->emailFromCredentials($event->credentials)
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $this->record(
            LoginEvent::Lockout,
            null,
            (string) $event->request->input(config('fortify.username', 'email'), ''),
            $event->request
        );
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            Lockout::class => 'handleLockout',
        ];
    }

    private function record(LoginEvent $event, ?Authenticatable $user, ?string $email = null, ?Request $request = null): void
    {
        $request ??= request();

        LoginHistory::query()->create([
            'user_id' => $user?->getAuthIdentifier(),
            'email' => $email ?? $this->emailFromUser($user),
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function emailFromCredentials(array $credentials): string
    {
        $field = config('fortify.username', 'email');

        return (string) ($credentials[$field] ?? '');
    }

    private function emailFromUser(?Authenticatable $user): string
    {
        return $user instanceof User ? $user->email : '';
    }
}
