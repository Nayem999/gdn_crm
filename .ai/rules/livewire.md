---
paths:
  - 'app/Livewire/**'
---

# Livewire

## Drop the cached media relation before reading or mutating it
The auth guard returns the same User instance for a whole request, and the topbar renders `avatarUrl()` (which loads the `media` relation) on every page. Any later media mutation on `auth()->user()` in that same request therefore works against a stale, often empty, cached collection — `clearMediaCollection()` iterates nothing and silently deletes no rows, while the DB still holds them.

Call `$user->unsetRelation('media')` immediately before reading or changing media on an instance you did not just fetch (see ProfileForm::refreshMedia). Route-model-bound instances are freshly queried, so they're safe.

Same trap applies to any relation the layout touches before an action mutates it.
