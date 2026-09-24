<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one thing a migration test has to prove that a feature test cannot:
 * that a lead which already existed under the old single-owner column
 * survives becoming a `lead_assignees` row when the migration that removed
 * it runs. RefreshDatabase has already applied every migration by the time
 * an ordinary test starts, so the only way to exercise the backfill itself
 * is to invoke this one migration's down()/up() directly, the same way
 * CoreSchemaMigrationTest does for the tables Phase 1 laid down.
 */
test('an existing lead keeps its owner as its assignee when the column is dropped', function () {
    $migration = require database_path('migrations/2026_09_24_093735_create_lead_assignees_table.php');

    // down() first: puts owner_id back (nullable — see the migration's own
    // note on why it cannot be NOT NULL) and drops lead_assignees, so this
    // test starts from the pre-migration shape regardless of what already
    // ran when the suite booted.
    Schema::withoutForeignKeyConstraints(fn () => $migration->down());

    $tenantId = DB::table('tenants')->value('id');
    $ownerId = User::factory()->create()->id;
    $createdAt = now()->subDays(10);

    $leadId = DB::table('leads')->insertGetId([
        'tenant_id' => $tenantId,
        'first_name' => 'Priya',
        'last_name' => 'Ramanathan',
        'status' => 'new',
        'owner_id' => $ownerId,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    Schema::withoutForeignKeyConstraints(fn () => $migration->up());

    expect(Schema::hasColumn('leads', 'owner_id'))->toBeFalse();

    $row = DB::table('lead_assignees')->where('lead_id', $leadId)->first();

    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($ownerId)
        ->and($row->tenant_id)->toBe($tenantId)
        ->and($row->priority)->toBeNull()
        // A backfilled row says when the assignment actually goes back to —
        // the lead's own created_at — not "just now", which migration time
        // is not.
        ->and($row->assigned_at)->toBe($createdAt->toDateTimeString());
});
