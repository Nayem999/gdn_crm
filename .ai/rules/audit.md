---
paths:
  - 'app/Domain/Audit/**'
---

# Audit

## Audit attributes are opt-in — never log credentials
Models are audited by adding `use App\Domain\Audit\Concerns\RecordsActivity` and implementing `activityAttributes()`, which is an explicit allowlist. It is deliberately not `logAll()`/`logFillable()`: the trail must never capture a password hash, remember token, two-factor secret or invitation token. Adding a sensitive column to a model does nothing until someone lists it, and tests/Feature/Audit/ActivityLogTest.php asserts no payload contains those.

Records whose model belongs to a package (spatie's Role) are logged through `App\Domain\Audit\AuditLogger` instead, which writes entries shaped exactly like the trait's — same `audit` log name and created/updated/deleted events — so the viewer handles both identically. Log deletions *before* the row goes, or the entry carries nothing.

`config/activitylog.php` sets `subject_returns_soft_deleted_models => true` on purpose: users are soft-deleted, and an entry that can't resolve its subject defeats the point.

Any listing of activity must order by `created_at` then `id`. Same-second entries otherwise come back in arbitrary order, which makes pagination unstable enough to repeat or skip rows.
