<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Leads\Models\Lead;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\Models\ReportSchedule;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Support\Models\Ticket;
use App\Domain\Timeline\DocumentUploads;
use App\Domain\Timeline\Models\Document;
use App\Livewire\Timeline\RecordTimeline;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * The security pass CRM_BUILD.md's 11.2 asks for, written as sweeps.
 *
 * The access-level fuzz is the one the brief names outright: "attempt to read
 * another user's records by ID". It is a sweep rather than a test per module,
 * because the module that will get this wrong is the one added after this file
 * was written.
 */

/**
 * Somebody who holds every permission for a module but whose role sees only
 * their **own** records. The dangerous shape: all the buttons, none of the
 * records.
 *
 * @param  array<int, string>  $permissions
 */
function ownOnlyUser(array $permissions): User
{
    $role = Role::query()->create([
        'name' => 'Own only '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::Own->value,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * The modules that scope records by owner, and how to make one.
 *
 * @return array<string, array{model: class-string<Model>, permission: string}>
 */
function scopedModules(): array
{
    return [
        'leads' => ['model' => Lead::class, 'permission' => 'leads.view'],
        'contacts' => ['model' => Contact::class, 'permission' => 'contacts.view'],
        'accounts' => ['model' => Account::class, 'permission' => 'accounts.view'],
        'deals' => ['model' => Deal::class, 'permission' => 'deals.view'],
        'activities' => ['model' => Activity::class, 'permission' => 'activities.view'],
        'tickets' => ['model' => Ticket::class, 'permission' => 'tickets.view'],
    ];
}

// -- Access-level bypass -------------------------------------------------------

test('somebody on own-records-only cannot read another person record by id', function (string $module) {
    $spec = scopedModules()[$module];
    $model = $spec['model'];

    $stranger = User::factory()->create();
    $theirs = $model::factory()->ownedBy($stranger)->create();

    $snooper = ownOnlyUser([$spec['permission'], $spec['permission']]);
    $mine = $model::factory()->ownedBy($snooper)->create();

    // The scope is the thing under test: a guessed id must not come back.
    $visible = $model::query()->visibleTo($snooper)->pluck('id')->all();

    expect($visible)->toBe([$mine->id])
        ->and($model::query()->visibleTo($snooper)->whereKey($theirs->id)->exists())->toBeFalse()
        // And the policy agrees with the scope, which is what the screens ask.
        ->and($snooper->can('view', $theirs))->toBeFalse()
        ->and($snooper->can('view', $mine))->toBeTrue();
})->with(array_keys(scopedModules()));

test('the scope holds however the id is guessed', function () {
    $stranger = User::factory()->create();
    $snooper = ownOnlyUser(['deals.view']);

    $theirs = Deal::factory()->ownedBy($stranger)->create();

    // Every shape a request might take to reach a record: a key, a list of
    // keys, and an id that does not exist at all.
    expect(Deal::query()->visibleTo($snooper)->whereKey($theirs->id)->first())->toBeNull()
        ->and(Deal::query()->visibleTo($snooper)->whereKey([$theirs->id, 999999])->get())->toHaveCount(0)
        ->and(Deal::query()->visibleTo($snooper)->find($theirs->id))->toBeNull();
});

test('a team-level role sees the team and no further', function () {
    $role = Role::query()->create([
        'name' => 'Team level '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::Team->value,
    ]);
    $role->syncPermissions(PermissionResolver::models(['deals.view']));

    $team = Team::factory()->create();

    $member = User::factory()->create(['current_team_id' => $team->id]);
    $member->assignRole($role);

    $colleague = User::factory()->create(['current_team_id' => $team->id]);
    $outsider = User::factory()->create();

    $ours = Deal::factory()->ownedBy($colleague)->create();
    $theirs = Deal::factory()->ownedBy($outsider)->create();

    $visible = Deal::query()->visibleTo($member->fresh())->pluck('id')->all();

    expect($visible)->toContain($ours->id)
        ->and($visible)->not->toContain($theirs->id);
});

// -- Mass assignment -----------------------------------------------------------

test('no model anywhere is unguarded', function () {
    $unguarded = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $body = (string) file_get_contents($file->getPathname());

        // `$guarded = []` turns every column into a fillable one, including the
        // ones an action is meant to own.
        if (preg_match('/\$guarded\s*=\s*\[\s*\]/', $body) === 1) {
            $unguarded[] = $file->getRelativePathname();
        }

        if (str_contains($body, 'Model::unguard')) {
            $unguarded[] = $file->getRelativePathname().' (unguard)';
        }
    }

    expect($unguarded)->toBe([], 'These are mass-assignable without limit: '.implode(', ', $unguarded));
});

test('a column owned by an action is not fillable', function (string $class, string $column) {
    // Each of these is written by exactly one action, and a form that could
    // mass-assign it would be a second path to a state the action guards.
    expect((new $class)->getFillable())->not->toContain($column);
})->with([
    'a ticket status' => [Ticket::class, 'status'],
    'a ticket resolution' => [Ticket::class, 'resolved_at'],
    'an article status' => [Article::class, 'status'],
    'a report being standard' => [Report::class, 'is_standard'],
    'a report slug' => [Report::class, 'slug'],
    'a schedule next run' => [ReportSchedule::class, 'next_run_at'],
    'a deal stage' => [Deal::class, 'stage'],
]);

// -- Uploads -------------------------------------------------------------------

test('documents are stored on a disk nothing serves', function () {
    $disk = config('media-library.disk_name');

    // The package defaults to the public disk, which is symlinked into
    // public/storage — every document would then be readable by URL with the
    // policy never consulted.
    expect($disk)->not->toBe('public')
        ->and(config('filesystems.disks.'.$disk.'.visibility'))->not->toBe('public');
});

test('a document upload refuses a file that runs in a browser', function (string $name, string $mime) {
    $owner = ownOnlyUser(['leads.view', 'timeline.view', 'timeline.create']);
    $lead = Lead::factory()->ownedBy($owner)->create();

    Livewire::actingAs($owner)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->set('upload', UploadedFile::fake()->createWithContent($name, '<script>alert(1)</script>'))
        ->call('attachDocument')
        ->assertHasErrors(['upload']);

    expect(Document::query()->count())->toBe(0);
})->with([
    'an html page' => ['page.html', 'text/html'],
    'an svg' => ['drawing.svg', 'image/svg+xml'],
    'a php script' => ['shell.php', 'application/x-php'],
]);

test('a document upload accepts the things a customer actually sends', function () {
    $owner = ownOnlyUser(['leads.view', 'timeline.view', 'timeline.create']);
    $lead = Lead::factory()->ownedBy($owner)->create();

    Livewire::actingAs($owner)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->set('upload', UploadedFile::fake()->create('signed-contract.pdf', 64, 'application/pdf'))
        ->set('documentTitle', 'Signed contract')
        ->call('attachDocument')
        ->assertHasNoErrors();

    expect(Document::query()->count())->toBe(1);
});

test('the allowlist names no format that executes', function () {
    $dangerous = ['html', 'htm', 'svg', 'php', 'phtml', 'js', 'exe', 'sh', 'bat', 'jar', 'zip'];

    foreach ($dangerous as $extension) {
        expect(DocumentUploads::extensions())->not->toContain($extension);
    }
});

// -- Response headers ----------------------------------------------------------

test('every response carries the headers a browser needs to defend the page', function () {
    $response = $this->actingAs(User::factory()->create())->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

test('HSTS is sent only over a secure connection', function () {
    // Sending it over plain HTTP is meaningless, and sending it from a local
    // server pins a developer's browser to https for a site with no certificate.
    $response = $this->actingAs(User::factory()->create())->get('/');

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

// -- Rate limiting -------------------------------------------------------------

test('the limiters the application depends on are registered', function (string $limiter) {
    expect(RateLimiter::limiter($limiter))->not->toBeNull();
})->with(['api', 'ingest']);

test('repeated failed logins are throttled', function () {
    $user = User::factory()->create(['email' => 'dana@example.test']);
    $last = null;

    // Fortify's own login limiter, which is why config/fortify.php leaves
    // `limiters.login` null: naming one there swaps a message somebody can
    // read for a bare 429.
    foreach (range(1, 6) as $ignored) {
        $last = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);
    }

    $errors = session('errors');

    expect($errors)->not->toBeNull()
        ->and(implode(' ', $errors->get('email')))->toContain('Too many');
});

// -- Escaping ------------------------------------------------------------------

test('no blade template prints a record field unescaped', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $body = (string) file_get_contents($file->getPathname());

        preg_match_all('/\{!!\s*(.+?)\s*!!\}/s', $body, $matches);

        foreach ($matches[1] ?? [] as $expression) {
            // The legitimate uses: markup this application generated itself —
            // a chip, a cell a data view built, an icon. Anything else is a
            // record's own text going to the browser unescaped.
            $safe = str_contains($expression, 'ChipPalette::')
                || str_contains($expression, '$slot')
                || str_contains($expression, 'cellFor')
                || str_contains($expression, '->render()')
                || str_contains($expression, 'Str::markdown')
                || str_contains($expression, '$html')
                || str_contains($expression, 'svg')
                // Fortify's own QR code, which is an SVG it builds from the
                // user's 2FA secret. Named rather than matched by a looser
                // rule: widening the heuristic is how the next one gets
                // through without anybody looking at it.
                || str_contains($expression, 'twoFactorQrCode');

            if (! $safe) {
                $offenders[] = $file->getRelativePathname().': {!! '.trim(substr($expression, 0, 60)).' !!}';
            }
        }
    }

    expect($offenders)->toBe([], 'These print unescaped: '.implode(' | ', $offenders));
});
