<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('core tables exist with the expected columns after migrating', function () {
    expect(Schema::hasTable('teams'))->toBeTrue()
        ->and(Schema::hasColumns('teams', ['id', 'parent_id', 'name', 'description']))->toBeTrue()
        ->and(Schema::hasTable('team_user'))->toBeTrue()
        ->and(Schema::hasColumns('team_user', ['team_id', 'user_id']))->toBeTrue()
        ->and(Schema::hasColumn('users', 'current_team_id'))->toBeTrue()
        ->and(Schema::hasColumn('roles', 'data_access_level'))->toBeTrue()
        ->and(Schema::hasTable('settings'))->toBeTrue()
        ->and(Schema::hasColumns('settings', ['group', 'key', 'value', 'type', 'is_secret']))->toBeTrue();
});

test('the core schema migrations roll back cleanly and re-migrate cleanly', function () {
    // Invoke these five migrations' up()/down() directly rather than via
    // `migrate:rollback --step`, whose step count would silently drift out of
    // sync every time a later migration (e.g. companies) is added.
    $migrations = collect([
        '2026_08_20_105233_create_teams_table.php',
        '2026_08_20_105234_create_team_user_table.php',
        '2026_08_20_105234_add_current_team_id_to_users_table.php',
        '2026_08_20_105235_add_data_access_level_to_roles_table.php',
        '2026_08_20_105235_create_settings_table.php',
    ])->map(fn (string $file) => require database_path("migrations/{$file}"));

    // Later migrations (user_invitations, ...) hold foreign keys into teams and
    // roles. A real rollback would unwind those first; this test exercises just
    // these five, so constraints are suspended rather than enumerating every
    // future dependent table here.
    Schema::withoutForeignKeyConstraints(function () use ($migrations) {
        $migrations->reverse()->each->down();

        expect(Schema::hasTable('settings'))->toBeFalse()
            ->and(Schema::hasTable('team_user'))->toBeFalse()
            ->and(Schema::hasTable('teams'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'current_team_id'))->toBeFalse()
            ->and(Schema::hasColumn('roles', 'data_access_level'))->toBeFalse();

        $migrations->each->up();
    });

    expect(Schema::hasTable('settings'))->toBeTrue()
        ->and(Schema::hasTable('team_user'))->toBeTrue()
        ->and(Schema::hasTable('teams'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'current_team_id'))->toBeTrue()
        ->and(Schema::hasColumn('roles', 'data_access_level'))->toBeTrue();
});

test('settings enforces a unique group and key pair', function () {
    DB::table('settings')->insert([
        'group' => 'company', 'key' => 'name', 'value' => 'Acme', 'type' => 'string', 'is_secret' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('settings')->insert([
        'group' => 'company', 'key' => 'name', 'value' => 'Other', 'type' => 'string', 'is_secret' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('deleting a team detaches team_user rows but does not delete the user', function () {
    $team = DB::table('teams')->insertGetId(['name' => 'Sales', 'created_at' => now(), 'updated_at' => now()]);
    $userId = DB::table('users')->insertGetId([
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => bcrypt('secret'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('team_user')->insert(['team_id' => $team, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);

    DB::table('teams')->where('id', $team)->delete();

    expect(DB::table('team_user')->where('team_id', $team)->exists())->toBeFalse()
        ->and(DB::table('users')->where('id', $userId)->exists())->toBeTrue();
});
