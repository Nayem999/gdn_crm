<?php

namespace App\Domain\Ingestion\Policies;

use App\Domain\Ingestion\Models\DataSource;
use App\Models\User;

/**
 * Who may let an outside system write into the CRM.
 *
 * Three permissions, split where the risk changes rather than per verb.
 * Reading the list and the event log is something a support person may need in
 * order to answer "did that come through"; creating a source, pointing it at a
 * module or switching one on decides what may enter the database; and minting
 * the credential that lets an outside system do so is different again.
 *
 * There is no access level here. A data source is installation configuration,
 * not somebody's record, so the only question is the permission.
 */
class DataSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('integrations.view');
    }

    public function view(User $user, DataSource $source): bool
    {
        return $user->can('integrations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('integrations.manage');
    }

    /**
     * Enabling, disabling and the sandbox toggle all go through update: each of
     * them changes whether real records appear in the database, which is the
     * same decision as editing the source.
     */
    public function update(User $user, DataSource $source): bool
    {
        return $user->can('integrations.manage');
    }

    public function delete(User $user, DataSource $source): bool
    {
        return $user->can('integrations.manage');
    }

    /**
     * Minting, rotating and revoking the keys a source authenticates with.
     *
     * Its own permission, for the reason `settings.secrets` is separate from
     * `settings.update`: somebody can be trusted to switch a misbehaving source
     * off without being handed the ability to issue a credential that writes
     * into the database.
     */
    public function manageSecrets(User $user, DataSource $source): bool
    {
        return $user->can('integrations.secrets');
    }
}
