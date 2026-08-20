<?php

namespace App\Domain\Company\Policies;

use App\Domain\Company\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function view(User $user, Company $company): bool
    {
        return $user->can('company.view');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->can('company.update');
    }
}
