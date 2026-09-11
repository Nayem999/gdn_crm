<?php

namespace App\Providers;

use App\Domain\Access\Policies\RolePolicy;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Policies\AccountPolicy;
use App\Domain\Activities\Models\Activity;
use App\Domain\Activities\Policies\ActivityPolicy;
use App\Domain\Audit\Policies\AuditEntryPolicy;
use App\Domain\Auth\Listeners\RecordLoginHistory;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Policies\CompanyPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Policies\ContactPolicy;
use App\Domain\CustomFields\CustomFieldSchema;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomFields\Policies\CustomFieldPolicy;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\PipelineStatusCache;
use App\Domain\Deals\Policies\DealPolicy;
use App\Domain\Deals\Policies\PipelinePolicy;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Leads\Policies\LeadPolicy;
use App\Domain\Leads\Policies\LeadScoringRulePolicy;
use App\Domain\Notifications\ChannelManager;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\NotificationMatrix;
use App\Domain\Notifications\Policies\NotificationPolicy;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Policies\SettingPolicy;
use App\Domain\Settings\SettingsManager;
use App\Domain\Teams\Policies\TeamPolicy;
use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;
use App\Domain\Timeline\Policies\DocumentPolicy;
use App\Domain\Timeline\Policies\NotePolicy;
use App\Domain\Users\Policies\UserPolicy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity as AuditEntry;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One manager per request, so its per-request cache of loaded groups is
        // shared by everything that reads a setting during that request.
        $this->app->singleton(SettingsManager::class);
        // One matrix and one channel manager per request: both hold a resolved
        // cache, and a test that swaps in a recording driver needs the same
        // instance the dispatcher uses.
        $this->app->singleton(NotificationMatrix::class);
        $this->app->singleton(ChannelManager::class);
        // One memo of the custom field definitions per request, flushed by the
        // actions that change one. See CustomFieldSchema.
        $this->app->singleton(CustomFieldSchema::class);
        // Same arrangement for the configured status sets — see PipelineStatusCache.
        $this->app->singleton(PipelineStatusCache::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        // Super Admin gets its way by holding every permission (kept in sync by
        // RolesAndPermissionsSeeder) rather than a Gate::before bypass, so
        // business rules such as "you cannot delete your own account" still hold.
        Gate::policy(Role::class, RolePolicy::class);
        // Two different things called Activity: the audit entry and the CRM
        // record. Aliased at the import so the pairing here is unambiguous.
        Gate::policy(AuditEntry::class, AuditEntryPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Deal::class, DealPolicy::class);
        Gate::policy(Pipeline::class, PipelinePolicy::class);
        Gate::policy(CustomField::class, CustomFieldPolicy::class);
        Gate::policy(LeadScoringRule::class, LeadScoringRulePolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(NotificationLog::class, NotificationPolicy::class);
        // Both ask the subject record's own policy before answering, so a note
        // or an attachment is never a way round a module's access level.
        Gate::policy(Note::class, NotePolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);

        Event::subscribe(RecordLoginHistory::class);

        // Models live under app/Domain/{Module}/Models, which Laravel's default
        // resolver would map to Database\Factories\Domain\{Module}\Models\...
        // All factories live flat in Database\Factories instead.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }
}
