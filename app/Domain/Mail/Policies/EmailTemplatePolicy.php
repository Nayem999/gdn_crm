<?php

namespace App\Domain\Mail\Policies;

use App\Domain\Mail\Models\EmailTemplate;
use App\Models\User;

/**
 * Who may read and write email templates.
 *
 * A template is wording a customer reads under the company's name, so changing
 * one is its own permission rather than something everybody who can send an
 * email has.
 */
class EmailTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('email-templates.view');
    }

    public function view(User $user, EmailTemplate $template): bool
    {
        return $user->can('email-templates.view');
    }

    public function create(User $user): bool
    {
        return $user->can('email-templates.update');
    }

    public function update(User $user, EmailTemplate $template): bool
    {
        return $user->can('email-templates.update');
    }

    public function delete(User $user, EmailTemplate $template): bool
    {
        return $user->can('email-templates.update');
    }
}
