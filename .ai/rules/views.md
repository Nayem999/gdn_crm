---
paths:
  - 'resources/views/**'
---

# Views

## Alpine only loads if @livewireStyles/@livewireScripts are explicit
Livewire 3's `inject_assets` auto-injection (which is what bundles Alpine.js) only fires on a request where at least one actual Livewire component rendered (see Livewire\Features\SupportAutoInjectedAssets). A plain Blade page with no Livewire component gets NO Alpine at all, silently — x-data/x-show/@click just do nothing, no console error.

The base layout (resources/views/components/layouts/app.blade.php) works around this by explicitly adding @livewireStyles in <head> and @livewireScripts before </body>, which force-inject regardless of component presence. Keep these directives in the shared layout; don't remove them even after real Livewire components exist elsewhere, since plain-Blade pages built on this layout still need them.

## A named slot written before the default content leaks an output buffer
In Laravel 12.67, calling an anonymous component that renders `{{ $slot }}` and
passing a named slot *before* the default content leaks one output buffer per
render:

```blade
{{-- leaks: ob_get_level() grows by one on every render --}}
<x-print-layout title="T"><x-slot:footer>F</x-slot:footer>Body</x-print-layout>

{{-- fine --}}
<x-print-layout title="T">Body<x-slot:footer>F</x-slot:footer></x-print-layout>
```

It is a framework behaviour, not ours — it reproduces in ordinary view rendering,
not just `Blade::render`/`$this->blade()`. PHPUnit reports it as "Test code or
tested code did not close its own output buffers".

So: **put named slots after the default slot content** at every call site
(`<x-empty-state>`, `<x-print-layout>`, `<x-data-view>`). The
`kit components with slots leave no output buffer behind` test in
tests/Feature/UiKit/ComponentRenderTest.php guards this.

## Icons come from blade-icons, prefixed
`mallardduck/blade-lucide-icons` registers `<x-lucide-{name}>`, and
`blade-ui-kit/blade-icons` registers a generic `<x-icon name="...">` that resolves
prefixed names. Use `<x-icon name="lucide-search" />`, or
`<x-icon :name="'lucide-' . $enum->icon()" />` for dynamic ones.

Do NOT add `resources/views/components/icon.blade.php`. A class-registered
component wins over an anonymous one, so blade-icons' `<x-icon>` shadows it and
the wrapper silently never runs — it fails with `Svg by name "search" from set
"default" not found`.

### Icons get their default size from config, not a wrapper
Lucide's SVGs carry no `width`/`height`, and blade-icons applies no default, so
`<x-icon name="lucide-x" />` written without a class rendered a **0x0** SVG —
invisible, and it collapsed its button's width too. Task 2.1 caught this in the
browser: the whole data-view toolbar had no icons.

`config/blade-lucide-icons.php` now sets `attributes => ['width' => 16,
'height' => 16]`. Attributes rather than the `class` option on purpose:
blade-icons *appends* a default class to whatever the call site passes, and
Tailwind picks the winner by its own stylesheet order — a default `h-4` would
beat an explicit `h-3.5`. A presentation attribute loses to any CSS rule, so
every `h-*`/`w-*` at a call site still wins.
