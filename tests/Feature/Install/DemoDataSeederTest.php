<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Products\Models\Product;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Support\Models\Ticket;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\BaselineSeeder;
use Database\Seeders\DemoDataSeeder;
use Spatie\Permission\Models\Role;

/**
 * Task 11.5 — the demo data seeder.
 */
function demoSeed(): void
{
    // The baseline first, because the demo roles are built from the permission
    // catalogue and the deals need a pipeline to sit on.
    (new BaselineSeeder)->run();
    (new DemoDataSeeder)->run();
}

test('it fills every list screen with something to look at', function () {
    demoSeed();

    expect(Account::query()->count())->toBeGreaterThan(0)
        ->and(Contact::query()->count())->toBeGreaterThan(0)
        ->and(Lead::query()->count())->toBeGreaterThan(0)
        ->and(Deal::query()->count())->toBeGreaterThan(0)
        ->and(Activity::query()->count())->toBeGreaterThan(0)
        ->and(Product::query()->count())->toBeGreaterThan(0)
        ->and(Ticket::query()->count())->toBeGreaterThan(0);
});

test('it creates demo users who hold a role with a real access level', function () {
    demoSeed();

    $representative = User::query()->firstWhere('email', 'tom@example.com');

    expect($representative)->not->toBeNull()
        ->and($representative->hasRole('Sales Representative'))->toBeTrue()
        ->and($representative->can('deals.view'))->toBeTrue()
        // A representative sees their own records and nobody else's — which is
        // the thing a demonstration of access levels has to be able to show.
        ->and($representative->can('settings.update'))->toBeFalse();

    $role = Role::query()->firstWhere('name', 'Sales Representative');

    expect($role->data_access_level)->toBe(DataAccessLevel::Own->value);
});

test('every demo user belongs to the team they are scoped by', function () {
    demoSeed();

    $team = Team::query()->firstWhere('name', 'Sales');
    $manager = User::query()->firstWhere('email', 'priya@example.com');

    expect($team)->not->toBeNull()
        ->and($team->users()->count())->toBeGreaterThanOrEqual(3)
        // Membership alone is not enough: team-scoped visibility reads
        // current_team_id, which SyncTeamMembersAction is what maintains.
        ->and($manager->current_team_id)->toBe($team->id);
});

test('the records are spread across the people who own them', function () {
    demoSeed();

    expect(Account::query()->distinct()->count('owner_id'))->toBeGreaterThan(1);
});

test('there is closed business behind the open pipeline', function () {
    demoSeed();

    expect(Deal::query()->whereIn('stage', [DealStage::Won->value, DealStage::Lost->value])->count())->toBeGreaterThan(0)
        ->and(Deal::query()->whereNotIn('stage', [DealStage::Won->value, DealStage::Lost->value])->count())->toBeGreaterThan(0)
        ->and(Deal::query()->whereNotNull('closed_at')->whereNull('close_reason')->count())->toBe(0);
});

test('it refuses to run against an installation that already has accounts', function () {
    demoSeed();

    $before = Account::query()->count();

    (new DemoDataSeeder)->run();

    expect(Account::query()->count())->toBe($before);
});

test('it keeps the administrator the wizard made rather than inventing one', function () {
    (new BaselineSeeder)->run();

    $owner = User::factory()->create(['email' => 'real-admin@example.com']);

    (new DemoDataSeeder)->run();

    expect(User::query()->firstWhere('email', 'admin@example.com'))->toBeNull()
        ->and(Account::query()->where('owner_id', $owner->id)->exists())->toBeFalse();
});
