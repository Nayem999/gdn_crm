---
paths:
  - 'app/Domain/Install/**'
  - 'app/Livewire/Install/**'
  - app/Http/Middleware/EnsureNotInstalled.php
  - 'database/seeders/{BaselineSeeder,DemoDataSeeder}.php'
  - 'resources/views/livewire/install/**'
---

# Install

## The installation wizard is shut by two independent facts
`Installation::isComplete()` is true if **any** user exists **or** `companies.installed_at` is set. Either alone shuts `/install`, so a command-line install that never wrote the marker is still closed, and deleting every user does not reopen it. The user check runs first because reading the marker goes through `Company::current()`, which *creates* the company row — and the sign-in page asks this on every visit.

Three gates, all needed: `EnsureNotInstalled` on the route, `abort_if` in `InstallWizard::mount()` (a Livewire component is also reachable via /livewire/update, which route middleware never sees), and the re-check inside `CompleteInstallationAction`, which is the one that holds when two people open the form at once.

`$step` is `#[Locked]`, so tests must advance it with `call('next')`, never `set('step', n)`. `install()` revalidates all three steps regardless.

Livewire's second password box is `adminPasswordConfirmation`, so the rule is `confirmed:adminPasswordConfirmation` — bare `confirmed` looks for `adminPassword_confirmation` and passes against nothing.

`email_verified_at` is not in `User::$fillable`: set it with `forceFill()->save()`, not in the `create()` array (both the wizard and DemoDataSeeder were silently dropping it).
