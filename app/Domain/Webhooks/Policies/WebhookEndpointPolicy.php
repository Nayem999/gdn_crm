<?php

namespace App\Domain\Webhooks\Policies;

use App\Domain\Webhooks\Models\WebhookEndpoint;
use App\Models\User;

/**
 * Who may point the application at somebody else's server.
 *
 * One permission for the lot. An endpoint is an outbound promise carrying
 * customer data, so "who can add one" and "who can change one" are the same
 * question, and splitting them would only make it easier to grant half of it by
 * accident.
 */
class WebhookEndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('api.webhooks');
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('api.webhooks');
    }

    public function create(User $user): bool
    {
        return $user->can('api.webhooks');
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('api.webhooks');
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('api.webhooks');
    }
}
