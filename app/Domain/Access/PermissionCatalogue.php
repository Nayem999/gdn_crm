<?php

namespace App\Domain\Access;

/**
 * The canonical list of permissions the application enforces.
 *
 * This is the source of truth for the roles matrix and for the seeder, so every
 * checkbox on that screen maps to a permission some policy actually checks. When
 * a new module lands, add its group here and re-run RolesAndPermissionsSeeder.
 */
final class PermissionCatalogue
{
    /**
     * A role that always holds every permission, kept in sync by the seeder and
     * protected from editing so the last administrator cannot be locked out.
     */
    public const SUPER_ADMIN_ROLE = 'Super Admin';

    /**
     * @return array<string, array{label: string, icon: string, permissions: array<string, string>}>
     */
    public static function groups(): array
    {
        return [
            'company' => [
                'label' => 'Company',
                'icon' => 'building-2',
                'permissions' => [
                    'company.view' => 'View the company profile',
                    'company.update' => 'Update the company profile',
                ],
            ],
            'users' => [
                'label' => 'Users',
                'icon' => 'users',
                'permissions' => [
                    'users.view' => 'View users',
                    'users.create' => 'Create users',
                    'users.update' => 'Update users',
                    'users.delete' => 'Remove users',
                    'users.invite' => 'Invite users by email',
                ],
            ],
            'teams' => [
                'label' => 'Teams',
                'icon' => 'network',
                'permissions' => [
                    'teams.view' => 'View teams',
                    'teams.create' => 'Create teams',
                    'teams.update' => 'Update teams and their membership',
                    'teams.delete' => 'Remove teams',
                ],
            ],
            'roles' => [
                'label' => 'Roles & permissions',
                'icon' => 'shield-check',
                'permissions' => [
                    'roles.view' => 'View roles',
                    'roles.create' => 'Create roles',
                    'roles.update' => 'Update roles, permissions and access levels',
                    'roles.delete' => 'Remove roles',
                ],
            ],
            'accounts' => [
                'label' => 'Accounts',
                'icon' => 'building-2',
                'permissions' => [
                    'accounts.view' => 'View accounts',
                    'accounts.create' => 'Create accounts',
                    'accounts.update' => 'Update accounts',
                    'accounts.delete' => 'Remove accounts',
                    'accounts.export' => 'Export accounts',
                    'accounts.import' => 'Import accounts from a file',
                    'accounts.merge' => 'Merge duplicate accounts',
                ],
            ],
            'leads' => [
                'label' => 'Leads',
                'icon' => 'target',
                'permissions' => [
                    'leads.view' => 'View leads',
                    'leads.create' => 'Capture leads',
                    'leads.update' => 'Update leads and move their status',
                    'leads.assign' => 'Hand a lead to someone else',
                    'leads.delete' => 'Remove leads',
                    'leads.export' => 'Export leads',
                    'leads.import' => 'Import leads from a file',
                    'leads.merge' => 'Merge duplicate leads',
                    // Conversion writes three records at once, one of them
                    // in a module this person may not otherwise touch.
                    'leads.convert' => 'Convert a lead into an account, contact and deal',
                    // Configuring how every lead is scored is administration,
                    // not lead work, so it stands apart from leads.update.
                    'leads.scoring' => 'Configure lead scoring and qualification rules',
                    // Its own permission: a capture form opens a write path
                    // into the CRM from the internet, which is a different
                    // thing from being allowed to type a lead in.
                    'leads.forms' => 'Configure public lead capture forms',
                ],
            ],
            'deals' => [
                'label' => 'Deals',
                'icon' => 'handshake',
                'permissions' => [
                    'deals.view' => 'View deals',
                    'deals.create' => 'Create deals',
                    'deals.update' => 'Update deals and move them along',
                    'deals.assign' => 'Hand a deal to someone else',
                    // Won and lost figures are what the business is measured
                    // on, so declaring one is more than an edit.
                    'deals.close' => 'Close a deal as won or lost',
                    'deals.delete' => 'Remove deals',
                    'deals.export' => 'Export deals',
                    // Deciding how every deal is worked is administration, not
                    // deal work, so it stands apart the way leads.scoring does.
                    'deals.pipelines' => 'Configure pipelines and stages',
                ],
            ],
            'activities' => [
                'label' => 'Activities',
                'icon' => 'calendar-clock',
                'permissions' => [
                    'activities.view' => 'View tasks, calls and meetings',
                    'activities.create' => 'Schedule tasks, calls and meetings',
                    // Completing, reopening and calling off are part of doing
                    // the work, so they sit under update rather than being a
                    // permission of their own.
                    'activities.update' => 'Update activities and mark them done',
                    'activities.assign' => 'Hand an activity to someone else',
                    'activities.delete' => 'Remove activities',
                    'activities.export' => 'Export activities',
                ],
            ],
            'products' => [
                'label' => 'Products',
                'icon' => 'package',
                'permissions' => [
                    'products.view' => 'View the catalogue',
                    'products.create' => 'Add products and services',
                    'products.update' => 'Update the catalogue',
                    'products.delete' => 'Remove products',
                    'products.export' => 'Export the catalogue',
                    // Deciding what the company charges is administration, not
                    // catalogue work — the same way deals.pipelines stands
                    // apart from deals.update.
                    'products.pricing' => 'Configure price books',
                ],
            ],
            'quotes' => [
                'label' => 'Quotes',
                'icon' => 'file-text',
                'permissions' => [
                    'quotes.view' => 'View quotes',
                    'quotes.create' => 'Create quotes',
                    'quotes.update' => 'Update draft quotes and raise new versions',
                    // Putting a priced offer in front of a customer over the
                    // company's name is more than an edit.
                    'quotes.send' => 'Send quotes to customers',
                    'quotes.delete' => 'Remove quotes',
                    'quotes.export' => 'Export quotes',
                ],
            ],
            'orders' => [
                'label' => 'Orders',
                'icon' => 'clipboard-check',
                'permissions' => [
                    'orders.view' => 'View sales and purchase orders',
                    'orders.create' => 'Raise orders, including from an accepted quote',
                    'orders.update' => 'Update draft orders and move them along',
                    'orders.delete' => 'Remove orders',
                    'orders.export' => 'Export orders',
                    // Committing the company's money to a supplier is not the
                    // same act as taking a customer's order, so it stands apart.
                    'orders.purchase' => 'Raise and approve purchase orders',
                ],
            ],
            'invoices' => [
                'label' => 'Invoices',
                'icon' => 'receipt',
                'permissions' => [
                    'invoices.view' => 'View invoices',
                    'invoices.create' => 'Raise invoices, including from an order',
                    'invoices.update' => 'Update draft invoices',
                    'invoices.issue' => 'Issue and cancel invoices',
                    'invoices.delete' => 'Remove invoices',
                    'invoices.export' => 'Export invoices',
                    // Saying money arrived is not the same act as raising the
                    // demand for it, and in most organisations it is not the
                    // same person either.
                    'invoices.payments' => 'Record and remove payments',
                ],
            ],
            'workflows' => [
                'label' => 'Workflows',
                'icon' => 'zap',
                'permissions' => [
                    'workflows.view' => 'View workflow definitions',
                    'workflows.create' => 'Create workflows',
                    'workflows.update' => 'Update workflows and switch them on or off',
                    'workflows.delete' => 'Remove workflows',
                    // Separate from the rest: answering "why did this record
                    // change last night" needs the log and nothing else, and
                    // that is a far wider audience than the people who should
                    // be able to change what happens tonight.
                    'workflows.logs' => 'View the workflow execution log',
                    // Reading approvals that were never yours, for an audit.
                    // Answering one is not a permission: it is an instruction
                    // from a workflow to a named person, and a role that could
                    // answer for anybody would defeat the chain.
                    'workflows.approvals' => 'View every approval, not only your own',
                ],
            ],
            'custom-modules' => [
                'label' => 'Custom modules',
                'icon' => 'box',
                'permissions' => [
                    // One set across every generated module rather than a set
                    // per module: the catalogue is a static list the roles
                    // matrix renders, and permissions invented at runtime would
                    // need discovering and syncing on every module save — a
                    // change to how 1.5 works, not something to bolt on here.
                    'custom-modules.view' => 'View records in modules you have added',
                    'custom-modules.create' => 'Create records in modules you have added',
                    'custom-modules.update' => 'Update records in modules you have added',
                    'custom-modules.delete' => 'Remove records in modules you have added',
                    'custom-modules.export' => 'Export records from modules you have added',
                    'custom-modules.configure' => 'Add, change and remove modules themselves',
                ],
            ],
            'saved-views' => [
                'label' => 'Saved views',
                'icon' => 'bookmark',
                'permissions' => [
                    // Saving a private view needs nothing: it is the person's
                    // own arrangement of a list they can already see. Sharing
                    // one puts it in front of everybody, which is the act worth
                    // gating.
                    'saved-views.share' => 'Share a saved view with everyone',
                ],
            ],
            'custom-fields' => [
                'label' => 'Custom fields',
                'icon' => 'sliders-horizontal',
                'permissions' => [
                    'custom-fields.view' => 'View the custom fields configured for each module',
                    // Defining a field changes what every record in a module can
                    // hold, so it is one administrative permission rather than a
                    // create/update/delete trio. Answering a field needs nothing
                    // extra: the record's own policy governs that.
                    'custom-fields.manage' => 'Add, change and remove custom fields',
                ],
            ],
            'tickets' => [
                'label' => 'Support',
                'icon' => 'life-buoy',
                'permissions' => [
                    'tickets.view' => 'View tickets',
                    'tickets.create' => 'Raise tickets',
                    'tickets.update' => 'Update tickets and move them on',
                    'tickets.assign' => 'Hand a ticket to another agent',
                    'tickets.delete' => 'Remove tickets',
                    'tickets.export' => 'Export tickets',
                    'tickets.sla' => 'Configure SLA policies',
                    'tickets.analytics' => 'See support analytics',
                ],
            ],
            'reports' => [
                'label' => 'Reports',
                'icon' => 'bar-chart-3',
                'permissions' => [
                    'reports.view' => 'Run and read reports',
                    'reports.create' => 'Build reports',
                    'reports.update' => 'Edit reports',
                    'reports.delete' => 'Remove reports',
                    // Sharing is its own permission: a report somebody built
                    // for themselves becoming visible to the whole company is
                    // a different decision from building it.
                    'reports.share' => 'Share reports with everybody',
                    'reports.schedule' => 'Schedule reports by email',
                ],
            ],
            'knowledge' => [
                'label' => 'Knowledge base',
                'icon' => 'book-open',
                'permissions' => [
                    'knowledge.view' => 'Read the knowledge base',
                    'knowledge.create' => 'Write articles',
                    'knowledge.update' => 'Edit articles and arrange sections',
                    // Its own permission: writing a draft and putting it in
                    // front of customers are different acts.
                    'knowledge.publish' => 'Publish and unpublish articles',
                    'knowledge.delete' => 'Remove articles and sections',
                ],
            ],
            'contacts' => [
                'label' => 'Contacts',
                'icon' => 'contact',
                'permissions' => [
                    'contacts.view' => 'View contacts',
                    'contacts.create' => 'Create contacts',
                    'contacts.update' => 'Update contacts',
                    'contacts.delete' => 'Remove contacts',
                    'contacts.export' => 'Export contacts',
                    'contacts.import' => 'Import contacts from a file',
                    'contacts.merge' => 'Merge duplicate contacts',
                ],
            ],
            'timeline' => [
                'label' => 'Notes & documents',
                'icon' => 'message-square-text',
                'permissions' => [
                    // One group across every module: a note is the same thing
                    // on a lead, a contact and an account, and what keeps them
                    // apart is the record's own policy, which is asked first.
                    'timeline.view' => 'Read notes and documents on a record',
                    'timeline.create' => 'Write notes and attach documents',
                    'timeline.update' => 'Edit a note they wrote',
                    'timeline.delete' => 'Remove notes and documents',
                ],
            ],
            'meta' => [
                'label' => 'Meta',
                'icon' => 'share-2',
                'permissions' => [
                    'meta.view' => 'View the Meta connection, pages and ad accounts',
                    'meta.connect' => 'Connect a Meta business, page, ad account or WhatsApp number',
                    'meta.disconnect' => 'Disconnect them again',
                    'meta.manage' => 'Change lead mapping, assignment and integration settings',
                    'meta.sync' => 'Run a synchronisation by hand rather than waiting for the schedule',
                    'meta.leads.view' => 'View leads that came from Meta, and what they were attributed to',
                    'meta.leads.manage' => 'Reprocess and reattribute a Meta lead',
                    'meta.campaigns.view' => 'View Meta campaigns, ad sets, ads and their figures',
                    'meta.campaigns.manage' => 'Link a Meta campaign to a CRM campaign',
                    // Separate from meta.manage: sending an outcome back to Meta
                    // changes what it optimises other people's budget towards,
                    // and is not the same decision as changing a mapping.
                    'meta.conversions.send' => 'Report CRM outcomes back to Meta',
                ],
            ],
            'social' => [
                'label' => 'Social inbox',
                'icon' => 'messages-square',
                'permissions' => [
                    'social.inbox.view' => 'Open the social inbox and read conversations',
                    'social.inbox.reply' => 'Reply to a conversation',
                    'social.inbox.assign' => 'Assign a conversation to somebody',
                    'social.analytics.view' => 'View social and campaign analytics',
                ],
            ],
            'whatsapp' => [
                'label' => 'WhatsApp',
                'icon' => 'message-circle',
                'permissions' => [
                    'whatsapp.view' => 'View WhatsApp conversations',
                    'whatsapp.reply' => 'Send WhatsApp messages from a record',
                    'whatsapp.templates.view' => 'View the approved message templates',
                    'whatsapp.templates.manage' => 'Synchronise templates and choose which may be used',
                ],
            ],
            'settings' => [
                'label' => 'Settings',
                'icon' => 'settings',
                'permissions' => [
                    'settings.view' => 'View application settings',
                    'settings.update' => 'Change application settings',
                    // Deliberately separate: someone can be trusted with a date
                    // format without being handed integration credentials.
                    'settings.secrets' => 'Read and replace stored credentials',
                ],
            ],
            'notifications' => [
                'label' => 'Notifications',
                'icon' => 'bell',
                'permissions' => [
                    'notifications.view' => 'View the notification matrix, templates and log',
                    'notifications.update' => 'Change the notification matrix and templates',
                ],
            ],
            'api' => [
                'label' => 'API',
                'icon' => 'plug',
                'permissions' => [
                    'api.tokens' => 'Create and revoke API keys',
                    'api.webhooks' => 'Configure outbound webhooks',
                ],
            ],
            // Inbound, where the API group is outbound. Kept apart because the
            // risk is not the same: an API key lets somebody read what they
            // could already see, while a data source lets an outside system put
            // records into the database.
            'integrations' => [
                'label' => 'Inbound data',
                'icon' => 'antenna',
                'permissions' => [
                    'integrations.view' => 'View data sources and what they have delivered',
                    'integrations.manage' => 'Add data sources, point them at a module and switch them on',
                    // Separate from manage for the reason settings.secrets is
                    // separate from settings.update: somebody can be trusted to
                    // switch a misbehaving source off without being handed the
                    // ability to mint a credential that writes to the database.
                    'integrations.secrets' => 'Generate, rotate and revoke the keys a source authenticates with',
                ],
            ],
            // Separate from notifications on purpose: a notification template
            // is wording the system sends on its own, while an email template
            // is something a salesperson picks and sends to a customer. The
            // people who write the two are rarely the same people.
            'email-templates' => [
                'label' => 'Email templates',
                'icon' => 'mail-plus',
                'permissions' => [
                    'email-templates.view' => 'View email templates',
                    'email-templates.update' => 'Create and change email templates',
                ],
            ],
            'audit' => [
                'label' => 'Audit log',
                'icon' => 'scroll-text',
                'permissions' => [
                    'audit.view' => 'View the audit log',
                ],
            ],
        ];
    }

    /**
     * Every permission name, flattened.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::groups() as $group) {
            foreach (array_keys($group['permissions']) as $permission) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    public static function has(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * Filter a submitted set down to permissions that actually exist, so a
     * tampered request cannot grant something outside the catalogue.
     *
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    public static function only(array $permissions): array
    {
        return array_values(array_filter(
            array_unique($permissions),
            fn (string $permission) => self::has($permission)
        ));
    }
}
