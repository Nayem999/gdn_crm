<?php

namespace Database\Seeders;

use App\Domain\Access\Actions\CreateRoleAction;
use App\Domain\Access\DTOs\RoleData;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Products\Models\Product;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Teams\Actions\CreateTeamAction;
use App\Domain\Teams\Actions\SyncTeamMembersAction;
use App\Domain\Teams\DTOs\TeamData;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * A CRM with a few months of work in it, for demonstrations and for a new
 * installation somebody wants to click around before trusting.
 *
 * Deliberately **not** part of DatabaseSeeder or of the installation wizard:
 * this is run on purpose, by name.
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Two things it will not do. It refuses to run against an installation that
 * already has accounts in it, because merging invented customers into real ones
 * is not something anybody can undo from the UI. And every account it creates
 * uses an example.com address with a shared, obvious password — which is why
 * `demo.allowed` has to be true in a production environment before it will run
 * at all.
 *
 * Model events are off (WithoutModelEvents), so seeding does not fire
 * workflows, notifications or scoring jobs at a few hundred invented records.
 */
class DemoDataSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The password every demo account shares. Useless anywhere real: the
     * accounts it opens exist only in a database somebody seeded on purpose.
     */
    public const PASSWORD = 'demo-password';

    public function run(): void
    {
        if (! $this->isAllowed()) {
            return;
        }

        $owner = $this->owner();
        $team = $this->team($owner);
        $members = $this->salesTeam($team);

        $this->catalogue($owner);

        $accounts = $this->accounts($members);
        $this->contacts($accounts, $members);
        $this->leads($members);
        $this->deals($accounts, $members);
        $this->activities($accounts, $members);
        $this->tickets($accounts, $members);

        $this->command?->info('Demo data seeded. Every demo account signs in with the password "'.self::PASSWORD.'".');
    }

    /**
     * Whether there is anything here worth refusing to touch.
     */
    private function isAllowed(): bool
    {
        if (app()->environment('production') && ! config('demo.allowed', false)) {
            $this->command?->warn('Refusing to seed demo data in production. Set DEMO_DATA_ALLOWED=true to override.');

            return false;
        }

        if (Account::query()->exists()) {
            $this->command?->warn('This installation already has accounts — demo data was not seeded.');

            return false;
        }

        return true;
    }

    /**
     * Whoever owns the installation, or a demo administrator if the wizard has
     * not been run.
     */
    private function owner(): User
    {
        $existing = User::query()->oldest('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        $owner = User::create([
            'name' => 'Dana Reed',
            'email' => 'admin@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $owner->forceFill(['email_verified_at' => now()])->save();

        $owner->syncRoles([Role::query()->firstWhere('name', PermissionCatalogue::SUPER_ADMIN_ROLE)]);

        return $owner;
    }

    private function team(User $owner): Team
    {
        return app(CreateTeamAction::class)(new TeamData(
            name: 'Sales',
            description: 'The demo sales team.',
            memberIds: [$owner->id],
        ));
    }

    /**
     * Three representatives who see their team's work, and their manager.
     *
     * Through CreateRoleAction and SyncTeamMembersAction rather than straight
     * inserts, so the demo roles carry a real access level and every member's
     * `current_team_id` is set — which is what makes team-scoped visibility
     * something a demonstration can actually show.
     *
     * @return array<int, User>
     */
    private function salesTeam(Team $team): array
    {
        $manager = $this->role('Sales Manager', DataAccessLevel::Team, [
            'accounts', 'contacts', 'leads', 'deals', 'activities', 'products',
            'quotes', 'orders', 'invoices', 'reports', 'tickets', 'knowledge', 'saved-views',
        ]);

        $representative = $this->role('Sales Representative', DataAccessLevel::Own, [
            'accounts', 'contacts', 'leads', 'deals', 'activities', 'products', 'quotes', 'saved-views',
        ]);

        $people = [
            ['Priya Nair', 'priya@example.com', $manager],
            ['Tom Alvarez', 'tom@example.com', $representative],
            ['Mei Chen', 'mei@example.com', $representative],
        ];

        $users = [];

        foreach ($people as [$name, $email, $role]) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(self::PASSWORD),
            ]);

            // Not fillable, so it has to be forced: a demo user who has to
            // verify an address at example.com can never sign in.
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->syncRoles([$role]);

            $users[] = $user;
        }

        app(SyncTeamMembersAction::class)(
            $team,
            [...$team->users()->pluck('users.id')->all(), ...array_map(fn (User $user): int => $user->id, $users)]
        );

        return $users;
    }

    /**
     * A role holding every permission in the named catalogue groups.
     *
     * Groups rather than a list of names, so a permission added by a later
     * module reaches the demo roles without anybody editing this file.
     *
     * @param  array<int, string>  $groups
     */
    private function role(string $name, DataAccessLevel $level, array $groups): Role
    {
        $existing = Role::query()->firstWhere('name', $name);

        if ($existing !== null) {
            return $existing;
        }

        $permissions = array_values(array_filter(
            PermissionCatalogue::all(),
            fn (string $permission): bool => in_array(explode('.', $permission)[0], $groups, true)
        ));

        return app(CreateRoleAction::class)(new RoleData($name, $level, $permissions));
    }

    private function catalogue(User $owner): void
    {
        $items = [
            ['CRM Platform — Starter', 'PLAT-STD', 1200, 480],
            ['CRM Platform — Professional', 'PLAT-PRO', 3600, 1400],
            ['CRM Platform — Enterprise', 'PLAT-ENT', 9600, 3800],
            ['Additional user seat', 'SEAT-01', 180, 60],
            ['Data migration', 'SRV-MIG', 2500, 900],
        ];

        foreach ($items as [$name, $sku, $list, $cost]) {
            Product::factory()->ownedBy($owner)->create([
                'name' => $name,
                'sku' => $sku,
                'list_price' => $list,
                'cost_price' => $cost,
            ]);
        }

        Product::factory()->service()->ownedBy($owner)->count(3)->create();
    }

    /**
     * @param  array<int, User>  $members
     * @return array<int, Account>
     */
    private function accounts(array $members): array
    {
        $accounts = [];

        foreach (range(1, 12) as $index) {
            $accounts[] = Account::factory()->ownedBy($this->pick($members, $index))->create();
        }

        return $accounts;
    }

    /**
     * @param  array<int, Account>  $accounts
     * @param  array<int, User>  $members
     */
    private function contacts(array $accounts, array $members): void
    {
        foreach ($accounts as $index => $account) {
            Contact::factory()
                ->forAccount($account)
                ->primary()
                ->ownedBy($this->pick($members, $index))
                ->create();

            Contact::factory()
                ->forAccount($account)
                ->ownedBy($this->pick($members, $index))
                ->count(random_int(1, 3))
                ->create();
        }
    }

    /**
     * @param  array<int, User>  $members
     */
    private function leads(array $members): void
    {
        $statuses = [
            LeadStatus::New, LeadStatus::New, LeadStatus::Contacted,
            LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Unqualified,
        ];

        foreach (range(0, 23) as $index) {
            Lead::factory()
                ->ownedBy($this->pick($members, $index))
                ->status($statuses[$index % count($statuses)])
                ->source(LeadSource::cases()[$index % count(LeadSource::cases())])
                ->create(['created_at' => now()->subDays(random_int(0, 90))]);
        }
    }

    /**
     * Deals spread across the pipeline, plus a closed quarter behind them so the
     * forecast and the win/loss reports have something to draw.
     *
     * @param  array<int, Account>  $accounts
     * @param  array<int, User>  $members
     */
    private function deals(array $accounts, array $members): void
    {
        $pipeline = Pipeline::default();
        $open = [DealStage::New, DealStage::Qualification, DealStage::Proposal, DealStage::Negotiation];

        foreach ($accounts as $index => $account) {
            $deal = Deal::factory()
                ->forAccount($account)
                ->ownedBy($this->pick($members, $index))
                ->atStage($open[$index % count($open)]->value);

            if ($pipeline !== null) {
                $deal = $deal->onPipeline($pipeline, $open[$index % count($open)]->value);
            }

            $deal->create();
        }

        foreach ($accounts as $index => $account) {
            // Two thirds won: a demonstration of a pipeline nobody ever wins is
            // a demonstration of the wrong thing.
            $outcome = $index % 3 === 2 ? StageOutcome::Lost : StageOutcome::Won;

            Deal::factory()
                ->forAccount($account)
                ->ownedBy($this->pick($members, $index))
                ->closed($outcome)
                ->create([
                    'expected_close_date' => now()->subDays(random_int(5, 120))->toDateString(),
                    'closed_at' => now()->subDays(random_int(1, 100)),
                ]);
        }
    }

    /**
     * @param  array<int, Account>  $accounts
     * @param  array<int, User>  $members
     */
    private function activities(array $accounts, array $members): void
    {
        $types = [ActivityType::Task, ActivityType::Call, ActivityType::Meeting];

        foreach ($accounts as $index => $account) {
            Activity::factory()
                ->ofType($types[$index % count($types)])
                ->ownedBy($this->pick($members, $index))
                ->create([
                    'related_type' => $account->getMorphClass(),
                    'related_id' => $account->id,
                    'due_at' => now()->addDays(random_int(0, 14))->setTime(random_int(9, 16), 0),
                ]);

            Activity::factory()
                ->ofType($types[($index + 1) % count($types)])
                ->ownedBy($this->pick($members, $index))
                ->create([
                    'related_type' => $account->getMorphClass(),
                    'related_id' => $account->id,
                    'status' => ActivityStatus::Completed->value,
                    'due_at' => now()->subDays(random_int(1, 30)),
                    'completed_at' => now()->subDays(random_int(0, 30)),
                ]);
        }
    }

    /**
     * @param  array<int, Account>  $accounts
     * @param  array<int, User>  $members
     */
    private function tickets(array $accounts, array $members): void
    {
        $priorities = TicketPriority::cases();

        foreach ($accounts as $index => $account) {
            $contact = Contact::query()->where('account_id', $account->id)->first();

            $ticket = Ticket::factory()
                ->ownedBy($this->pick($members, $index))
                ->state([
                    'account_id' => $account->id,
                    'contact_id' => $contact?->id,
                    'priority' => $priorities[$index % count($priorities)]->value,
                ]);

            // A support queue that is entirely open reads as a backlog nobody
            // works; one that is entirely closed reads as a queue nobody uses.
            $ticket = match ($index % 4) {
                0 => $ticket->withStatus(TicketStatus::New),
                1 => $ticket->withStatus(TicketStatus::Open),
                2 => $ticket->resolved(now()->subDays(random_int(1, 20))),
                default => $ticket->closed(now()->subDays(random_int(1, 40))),
            };

            $ticket->create(['created_at' => now()->subDays(random_int(1, 60))]);
        }
    }

    /**
     * Deal the records out round-robin, so every demo user has work rather than
     * one of them having all of it.
     *
     * @param  array<int, User>  $members
     */
    private function pick(array $members, int $index): User
    {
        return $members[$index % count($members)];
    }
}
