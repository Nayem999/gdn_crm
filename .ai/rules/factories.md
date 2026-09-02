---
paths:
  - 'database/factories/**'
---

# Factories

## All factories live flat in Database\Factories, resolved by basename
Models under `app/Domain/{Module}/Models` break Laravel's default factory resolution: it maps `App\Domain\Auth\Models\LoginHistory` to `Database\Factories\Domain\Auth\Models\LoginHistoryFactory`, which doesn't exist. AppServiceProvider registers `Factory::guessFactoryNamesUsing()` mapping any model to `Database\Factories\{ClassBasename}Factory` instead.

So: keep every factory directly in `database/factories/` (no nested namespaces), name it `{Model}Factory`, and always set `protected $model = X::class` on factories for domain models, since the reverse guess (factory -> model) still assumes `App\Models\`.

This was latent from 1.1 — CompanyFactory existed but was never called, so nothing failed until LoginHistory::factory() was used in 1.2.
