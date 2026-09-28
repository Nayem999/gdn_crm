---
paths:
  - 'app/Domain/Tenancy/**'
---

# Tenancy

## Tenancy is a global scope, never a where() — and an unidentified request sees nothing
This installation serves many customers from one shared database, so isolation is a property of the code rather than of the schema. Rules that follow from that:

`BelongsToTenant` adds a **global** scope and stamps `tenant_id` on create. Never scope tenancy with an explicit `where('tenant_id', ...)` at a call site — that is the thing that gets forgotten. Contrast `ScopesByAccessLevel`, which is opt-in (`visibleTo()`) because record visibility is a business rule; tenancy is not.

`Tenancy` is a container **singleton**: the middleware sets it, the scope reads it. Two instances would mean every query scoped to nothing while the request believed it had a workspace.

With no tenant set, `TenantScope` narrows to `1 = 0` — nothing, not everything. An unidentified request showing an empty screen is reported in minutes; one showing every customer's data is reported by the customer.

Writes across workspaces throw. Cross-tenant work goes through `Tenancy::withoutScope()` (which then requires `tenant_id` to be set explicitly on any create) or `Tenancy::for()`, which restores the previous workspace in a `finally` — a command looping tenants must not strand the next one inside the customer whose run failed.

`users` carries `tenant_id` but has **no** global scope: authentication looks a user up by email before any workspace is known. Email stays globally unique; a person belongs to one workspace. Any user listing must scope itself.

`tests/Feature/Tenancy/TenancyCoverageTest.php` is the enforcement: its pending-tables list only ever shrinks, and a new untenanted table has to be named there deliberately. Note it lists tables for the **connection's own database** — `Schema::getTableListing()` answers for every schema on a shared MySQL server.

Unique indexes must include `tenant_id`, or one customer's product SKU, pipeline name, invoice number or settings key blocks another's.

## Entry points with nobody signed in act for the asset owner's workspace — Tenancy::forOwner()
`SetTenantFromUser` only runs for a signed-in user. Meta webhooks, the generic
ingest endpoint, public lead-capture forms and the chat widget have nobody signed
in, and their processing runs after the response or on the queue — so tenancy
is unset and the first `Lead` write throws "No workspace is set". The test suite
hides this: tests/Pest.php sets a workspace before every test.

So each of those paths wraps its tenant work in `Tenancy::forOwner($owner, ...)`,
where the owner is the person the asset belongs to: the Meta account's
`connectedBy` (WhatsApp number, Facebook page), the data source's default owner
or creator, the capture form's / widget's owner. `forOwner()` keeps a workspace
that is already set (a signed-in replay), refuses a suspended one, and throws a
readable error when there is no owner. Any new unauthenticated entry point that
touches tenant data must do the same, and its test must `forget()` the tenant
first to prove it.
