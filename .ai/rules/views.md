---
paths:
  - 'resources/views/**'
---

# Views

## Alpine only loads if @livewireStyles/@livewireScripts are explicit
Livewire 3's `inject_assets` auto-injection (which is what bundles Alpine.js) only fires on a request where at least one actual Livewire component rendered (see Livewire\Features\SupportAutoInjectedAssets). A plain Blade page with no Livewire component gets NO Alpine at all, silently — x-data/x-show/@click just do nothing, no console error.

The base layout (resources/views/components/layouts/app.blade.php) works around this by explicitly adding @livewireStyles in <head> and @livewireScripts before </body>, which force-inject regardless of component presence. Keep these directives in the shared layout; don't remove them even after real Livewire components exist elsewhere, since plain-Blade pages built on this layout still need them.
