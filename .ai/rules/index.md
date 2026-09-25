# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Domain/Access/** | .ai/rules/access.md |
| app/Domain/Accounts/**, app/Livewire/Accounts/**, resources/views/livewire/accounts/** | .ai/rules/accounts.md |
| app/Domain/Activities/**, app/Livewire/Activities/**, app/Console/Commands/{SendActivityReminders,GenerateRecurringActivities}.php, resources/views/livewire/activities/** | .ai/rules/activities.md |
| app/Domain/Campaigns/**, app/Domain/Meta/Ads/**, app/Livewire/Meta/** | .ai/rules/ads.md |
| app/Domain/Audit/** | .ai/rules/audit.md |
| resources/views/components/select.blade.php, resources/views/components/status-chip.blade.php | .ai/rules/components.md |
| config/fortify.php | .ai/rules/config.md |
| app/Domain/Contacts/**, app/Livewire/Contacts/**, resources/views/livewire/contacts/** | .ai/rules/contacts.md |
| app/Domain/CustomFields/**, app/Livewire/CustomFields/**, resources/views/livewire/custom-fields/** | .ai/rules/custom-fields.md |
| app/Domain/*/DTOs/** | .ai/rules/d-t-os.md |
| app/Domain/Dashboard/**, app/Livewire/Dashboard/**, resources/views/dashboard.blade.php, resources/views/livewire/dashboard/** | .ai/rules/dashboard.md |
| app/Domain/Shared/Concerns/**, app/Domain/Shared/DataView/**, app/Domain/Shared/Filters/**, app/Domain/Shared/Exports/**, app/Jobs/GenerateDataViewExport.php, resources/views/components/data-view.blade.php, resources/views/components/data-view/**, resources/views/components/filter-builder*, resources/views/components/column-manager.blade.php, resources/views/components/export-menu.blade.php | .ai/rules/data-view.md |
| app/Domain/Deals/Models/Deal.php, app/Domain/Deals/Models/DealStageEntry.php, app/Domain/Deals/Concerns/TracksStageHistory.php, app/Domain/Deals/Actions/{CreateDeal,UpdateDeal,DeleteDeal,MoveDealStage,CloseDeal}Action.php, app/Domain/Deals/DTOs/DealData.php, app/Domain/Deals/DealFields.php, app/Domain/Deals/DealExportSource.php, app/Domain/Deals/Enums/DealCloseReason.php, app/Livewire/Deals/Deal*.php, resources/views/livewire/deals/deal*.blade.php | .ai/rules/deals.md |
| app/Domain/Shared/Duplicates/**, app/Domain/Shared/Actions/MergeRecordsAction.php, app/Domain/Shared/Actions/SyncDuplicateKeysAction.php, app/Domain/Shared/Concerns/MergesWithDuplicates.php, app/Domain/Shared/Concerns/FindsDuplicates.php, app/Domain/Shared/Concerns/WarnsAboutDuplicates.php, app/Domain/*/[A-Z]*Duplicates.php, app/Livewire/Duplicates/**, resources/views/livewire/duplicates/**, resources/views/components/duplicate-* | .ai/rules/duplicates.md |
| database/factories/** | .ai/rules/factories.md |
| * | .ai/rules/general.md |
| app/Domain/Shared/Imports/**, app/Domain/Shared/Actions/RunImportAction.php, app/Domain/Shared/Models/ImportRun.php, app/Domain/*/[A-Z]*ImportSource.php, app/Livewire/Imports/**, app/Jobs/RunImport.php, resources/views/livewire/imports/** | .ai/rules/imports.md |
| app/Domain/Ingestion/**, app/Livewire/Settings/DataSources.php, resources/views/livewire/settings/data-sources.blade.php, database/migrations/*ingestion*.php | .ai/rules/ingestion.md |
| app/Domain/Install/**, app/Livewire/Install/**, app/Http/Middleware/EnsureNotInstalled.php, database/seeders/{BaselineSeeder,DemoDataSeeder}.php, resources/views/livewire/install/** | .ai/rules/install.md |
| app/Domain/Api/**, app/Domain/Webhooks/**, app/Domain/Messaging/**, app/Domain/Chat/**, app/Http/Controllers/Api/**, app/Http/Controllers/ChatCaptureController.php, app/Jobs/DeliverWebhook.php, routes/api.php, config/cors.php | .ai/rules/integrations.md |
| app/Domain/Knowledge/**, app/Livewire/Knowledge/**, resources/views/livewire/knowledge/** | .ai/rules/knowledge.md |
| resources/views/components/layouts/** | .ai/rules/layouts.md |
| app/Domain/Leads/Capture/**, app/Domain/Leads/Actions/SubmitLeadCaptureAction.php, app/Http/Controllers/LeadCaptureController.php, resources/views/lead-capture/** | .ai/rules/lead-capture.md |
| app/Domain/Leads/**, app/Livewire/Leads/**, app/Jobs/RescoreLeads.php, database/seeders/LeadScoringRulesSeeder.php, resources/views/livewire/leads/** | .ai/rules/leads.md |
| app/Livewire/** | .ai/rules/livewire.md |
| app/Domain/Mail/**, app/Domain/Notifications/Drivers/MailDriver.php, config/mail.php | .ai/rules/mail.md |
| database/migrations/** | .ai/rules/migrations.md |
| app/Domain/*/Models/*.php | .ai/rules/models-name-collisions.md |
| app/Domain/*/Models/*.php | .ai/rules/models.md |
| app/Domain/Notifications/**, app/Livewire/Notifications/**, app/Jobs/SendNotification.php, app/Notifications/**, app/Mail/**, resources/views/livewire/notifications/** | .ai/rules/notifications.md |
| app/Domain/Deals/Models/Pipeline.php, app/Domain/Deals/Models/PipelineStage.php, app/Domain/Deals/Actions/**, app/Domain/Deals/DTOs/**, app/Domain/Deals/Enums/StageOutcome.php, app/Livewire/Deals/**, resources/views/livewire/deals/**, database/seeders/PipelinesSeeder.php | .ai/rules/pipelines.md |
| app/Domain/*/Policies/*.php | .ai/rules/policies.md |
| app/Domain/Products/**, app/Livewire/Products/**, resources/views/livewire/products/** | .ai/rules/products.md |
| tests/Feature/Regression/**, app/Domain/Shared/RequestMemo.php, app/Http/Middleware/SecurityHeaders.php | .ai/rules/regression.md |
| app/Domain/Reports/**, app/Livewire/Reports/**, resources/views/livewire/reports/**, resources/views/components/report-*.blade.php, resources/views/components/chart/**, app/Jobs/SendScheduledReport.php, app/Mail/ScheduledReportMail.php | .ai/rules/reports.md |
| app/Domain/Sales/**, app/Domain/Products/Cpq/**, app/Livewire/Sales/**, database/migrations/*document_lines*.php, database/migrations/*quote*.php, database/migrations/*order*.php, database/migrations/*invoice*.php, resources/views/livewire/sales/**, resources/views/pdf/** | .ai/rules/sales.md |
| app/Domain/Settings/**, app/Livewire/Settings/**, resources/views/livewire/settings/**, resources/views/components/settings-shell.blade.php, resources/views/components/form/secret.blade.php, app/Domain/Settings/DisplayTime.php, app/Domain/Settings/SettingField.php | .ai/rules/settings.md |
| app/Domain/Social/**, app/Livewire/Social/**, resources/views/livewire/social/** | .ai/rules/social.md |
| app/Domain/Support/**, app/Livewire/Support/**, resources/views/livewire/support/** | .ai/rules/support.md |
| app/Domain/Teams/** | .ai/rules/teams.md |
| app/Domain/Tenancy/** | .ai/rules/tenancy.md |
| tests/** | .ai/rules/tests.md |
| app/Domain/Timeline/**, app/Livewire/Timeline/**, app/Http/Controllers/DownloadDocument.php, resources/views/livewire/timeline/**, resources/views/components/timeline-entry.blade.php | .ai/rules/timeline.md |
| app/Domain/Shared/UI/NavIconPalette.php, resources/views/layouts/partials/sidebar.blade.php, resources/views/components/settings-shell.blade.php | .ai/rules/views-components.md |
| resources/views/livewire/** | .ai/rules/views-livewire.md |
| resources/views/** | .ai/rules/views.md |
| app/Domain/Workflows/Webhooks/** | .ai/rules/webhooks.md |
| app/Domain/Workflows/**, app/Domain/Approvals/**, app/Livewire/Workflows/**, app/Livewire/Approvals/**, app/Jobs/RunWorkflow.php, database/migrations/*workflow*.php, database/migrations/*approval*.php, resources/views/livewire/workflows/**, resources/views/livewire/approvals/** | .ai/rules/workflows.md |
