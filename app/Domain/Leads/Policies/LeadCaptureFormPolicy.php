<?php

namespace App\Domain\Leads\Policies;

use App\Domain\Leads\Models\LeadCaptureForm;
use App\Models\User;

/**
 * Configuring a public form is an administrative act — it opens a write path
 * into the CRM from the internet — so it is its own permission rather than
 * folded into `leads.create`.
 */
class LeadCaptureFormPolicy
{
    public function configureForms(User $user): bool
    {
        return $user->can('leads.forms');
    }

    public function viewAny(User $user): bool
    {
        return $user->can('leads.forms');
    }

    public function view(User $user, LeadCaptureForm $form): bool
    {
        return $user->can('leads.forms');
    }

    public function create(User $user): bool
    {
        return $user->can('leads.forms');
    }

    public function update(User $user, LeadCaptureForm $form): bool
    {
        return $user->can('leads.forms');
    }

    public function delete(User $user, LeadCaptureForm $form): bool
    {
        return $user->can('leads.forms');
    }
}
