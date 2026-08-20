---
paths:
  - 'app/Domain/*/Policies/*.php'
---

# Policies

## Policies check permission strings, never hardcoded role names
CompanyPolicy (app/Domain/Company/Policies/CompanyPolicy.php) checks `$user->can('company.view')` / `$user->can('company.update')` — spatie/permission permission strings — rather than `$user->hasRole('Admin')`. This keeps policies decoupled from whatever role taxonomy Task 1.5 (Roles & permissions matrix UI) ends up designing; roles are just bundles of permissions assigned later. Follow the same `{module}.{action}` permission-naming convention for new policies.
