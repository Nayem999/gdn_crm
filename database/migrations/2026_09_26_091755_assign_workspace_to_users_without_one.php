<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives a workspace to users created without one.
 *
 * Until User started stamping tenant_id on creation, nothing that creates a
 * user set it — the users screen, invitations, sign-up, the installer — so
 * everybody added since tenancy arrived was served no workspace, and every
 * tenant-scoped list (leads, so far) came back empty for them.
 *
 * An invited user joins their inviter's workspace, which the invitation still
 * records. Anybody else joins the workspace only when there is exactly one to
 * join; with several there is no right guess, so they are left for an
 * administrator rather than put in the wrong customer's data.
 *
 * No down(): which users were unassigned before is not worth restoring, and
 * nulling them again would only reintroduce the fault.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('users')->whereNull('tenant_id')->get(['id', 'email']);

        if ($orphans->isEmpty()) {
            return;
        }

        $tenantIds = DB::table('tenants')->orderBy('id')->pluck('id');
        $onlyTenant = $tenantIds->count() === 1 ? $tenantIds->first() : null;

        foreach ($orphans as $user) {
            $fromInviter = DB::table('user_invitations')
                ->join('users as inviter', 'inviter.id', '=', 'user_invitations.invited_by')
                ->where('user_invitations.email', $user->email)
                ->whereNotNull('user_invitations.accepted_at')
                ->whereNotNull('inviter.tenant_id')
                ->orderByDesc('user_invitations.accepted_at')
                ->value('inviter.tenant_id');

            $tenantId = $fromInviter ?? $onlyTenant;

            if ($tenantId !== null) {
                DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
            }
        }
    }
};
