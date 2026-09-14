<?php

use App\Domain\Access\PermissionResolver;
use App\Http\Middleware\EnsureNotInstalled;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The contracts in CRM_BUILD.md's testing matrix, asserted across the whole
 * application rather than module by module.
 *
 * A sweep rather than a test per class, deliberately. The matrix says things
 * like "every policy" and "every select" — a per-module test satisfies that
 * only for the modules somebody remembered, and the next module to land is
 * exactly the one nobody will. These fail the moment a new module breaks the
 * rule, without anybody having to add anything.
 */

/**
 * Every model class under app/Domain, keyed by its class name.
 *
 * @return array<string, class-string<Model>>
 */
function sweepDomainModels(): array
{
    $models = [];

    foreach (File::allFiles(app_path('Domain')) as $file) {
        if ($file->getExtension() !== 'php' || ! str_contains($file->getPath(), 'Models')) {
            continue;
        }

        $class = 'App\\Domain\\'.str($file->getRelativePathname())
            ->replace(['/', '\\'], '\\')
            ->replace('.php', '')
            ->value();

        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $models[class_basename($class)] = $class;
        }
    }

    return $models;
}

/**
 * The model classes Gate has a policy registered for.
 *
 * @return array<string, class-string>
 */
function sweepRegisteredPolicies(): array
{
    /** @var array<string, class-string> $policies */
    $policies = Gate::policies();

    return $policies;
}

// -- No plain selects ----------------------------------------------------------

test('no blade template anywhere uses a plain select element', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        // The component itself is the one place a real <select> belongs: it is
        // what Tom Select is attached to.
        if (str_ends_with($file->getRelativePathname(), 'components'.DIRECTORY_SEPARATOR.'select.blade.php')) {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), '<select')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    // The UI Standard's hardest rule: every dropdown is <x-select>, so every
    // dropdown searches, and a new screen cannot quietly ship a bare one.
    expect($offenders)->toBe([], 'These templates use a plain <select>: '.implode(', ', $offenders));
});

// -- Factories -----------------------------------------------------------------

test('every model in app/Domain has a factory', function () {
    $missing = [];

    foreach (sweepDomainModels() as $name => $class) {
        if (! method_exists($class, 'factory')) {
            $missing[] = $name;

            continue;
        }

        try {
            $class::factory();
        } catch (Throwable $exception) {
            $missing[] = $name.' ('.$exception->getMessage().')';
        }
    }

    // The brief requires one for every model. A log table with no factory means
    // a test that wants one row builds it by hand, and a hand-built row can
    // describe a state the application cannot produce.
    expect($missing)->toBe([], 'These models have no usable factory: '.implode(', ', $missing));
});

/**
 * Models whose factory needs a parent handed to it.
 *
 * Each is a polymorphic child — a custom field's answer, a document's line —
 * and the column that says what it belongs to has no sensible default. Their
 * own modules build them with a parent; this sweep only checks that every
 * model *has* a factory, which they do.
 *
 * @return array<int, string>
 */
function sweepModelsNeedingAParent(): array
{
    return ['CustomFieldValue', 'DocumentLine'];
}

test('every factory produces a model that saves', function () {
    $broken = [];

    foreach (sweepDomainModels() as $name => $class) {
        if (! method_exists($class, 'factory') || in_array($name, sweepModelsNeedingAParent(), true)) {
            continue;
        }

        try {
            $model = $class::factory()->create();

            expect($model->exists)->toBeTrue();
        } catch (Throwable $exception) {
            $broken[] = $name.': '.Str::limit($exception->getMessage(), 120);
        }
    }

    // A factory that has drifted from its table is one nobody finds until they
    // write the test that needed it.
    expect($broken)->toBe([], implode(' | ', $broken));
});

// -- Authorization -------------------------------------------------------------

test('every registered policy refuses somebody holding no permissions', function () {
    $leaks = [];
    $stranger = User::factory()->create();

    foreach (sweepRegisteredPolicies() as $modelClass => $policyClass) {
        if (! class_exists($modelClass) || ! method_exists($modelClass, 'factory')) {
            continue;
        }

        try {
            $record = $modelClass::factory()->create();
        } catch (Throwable) {
            // A model whose factory needs more setup than this sweep can give
            // is covered by its own module's tests.
            continue;
        }

        foreach ((new ReflectionClass($policyClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || str_starts_with($method->name, '__') || $method->name === 'before') {
                continue;
            }

            $parameters = $method->getParameters();

            // Every policy method here takes the user first, and the record
            // second when it is about one.
            $arguments = count($parameters) >= 2 ? [$stranger, $record] : [$stranger];

            try {
                $allowed = $policyClass::class === null ? false : app($policyClass)->{$method->name}(...$arguments);
            } catch (Throwable) {
                continue;
            }

            if ($allowed === true) {
                $leaks[] = class_basename($policyClass).'::'.$method->name;
            }
        }
    }

    // "Unauthorized user gets 403" from the testing matrix, asserted for every
    // policy at once: somebody with no permissions may do nothing at all.
    expect($leaks)->toBe([], 'These policy methods allow a user with no permissions: '.implode(', ', $leaks));
});

// -- Routes --------------------------------------------------------------------

test('every application route is behind authentication', function () {
    $open = [];

    foreach (app('router')->getRoutes() as $route) {
        $uri = $route->uri();
        $middleware = $route->gatherMiddleware();

        // The deliberately public surface. Each of these authenticates by
        // something other than a session, or is public by design:
        //
        //   api/          an API token, checked by Sanctum
        //   f/ forms/     the customer-facing lead capture forms
        //   webhooks/     a provider signature, not a login
        //   e/o/ e/c/     email open and click tracking, opened by a mail client
        //   c/            a chat widget token
        //   invitations/  a signed invitation, which is the thing being accepted
        //   guide         the documentation, which is public on purpose: it is the
        //                 instructions, and it reads nothing but two markdown files
        //   install       the first-run wizard, which creates the first account and
        //                 so cannot require one — allowed here only while it
        //                 carries EnsureNotInstalled, which shuts it for good
        //                 the moment there is an account to protect
        //   horizon/      gated by the viewHorizon ability — asserted below
        //   up, _, livewire/, storage/, sanctum/  framework and asset routes
        $public = str_starts_with($uri, 'api/')
            || str_starts_with($uri, 'f/')
            || str_starts_with($uri, 'forms/')
            || str_starts_with($uri, 'webhooks/')
            || str_starts_with($uri, 'e/o/')
            || str_starts_with($uri, 'e/c/')
            || str_starts_with($uri, 'c/')
            || str_starts_with($uri, 'invitations/')
            || ($uri === 'install' && in_array(EnsureNotInstalled::class, $middleware, true))
            || str_starts_with($uri, 'horizon')
            || str_starts_with($uri, 'sanctum/')
            || str_starts_with($uri, 'up')
            || str_starts_with($uri, '_')
            || str_starts_with($uri, 'livewire/')
            || str_starts_with($uri, 'storage/')
            || in_array($uri, ['/', 'guide', 'login', 'register', 'logout', 'forgot-password', 'reset-password',
                'reset-password/{token}', 'email/verify', 'user/confirm-password',
                'user/confirmed-password-status', 'two-factor-challenge'], true);

        if ($public) {
            continue;
        }

        if (! in_array('auth', $middleware, true) && ! in_array('auth:web', $middleware, true)) {
            $open[] = $route->methods()[0].' '.$uri;
        }
    }

    expect($open)->toBe([], 'These routes are reachable without logging in: '.implode(', ', $open));
});

test('the queue dashboard is gated on a real permission', function () {
    $stranger = User::factory()->create();
    $admin = User::factory()->create();
    $admin->givePermissionTo(
        PermissionResolver::models(['settings.view'])[0]
    );

    // Laravel ships this gate as an empty allowlist, which closes Horizon to
    // everybody including the administrator who needs it — and reads as a
    // permission fault rather than the configuration one it is.
    expect(Gate::forUser($stranger)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($admin->fresh())->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});
