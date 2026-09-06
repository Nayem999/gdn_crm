---
paths:
  - 'resources/views/components/layouts/**'
---

# Layouts

## App shell: main is the only scroll container (needs min-h-0)
In components/layouts/app.blade.php the wrapper is `flex h-screen overflow-hidden`, the content column is `flex min-h-0 min-w-0 flex-1 flex-col`, and `<main>` is `min-h-0 flex-1 overflow-y-auto`. The `min-h-0` parts are load-bearing: a flex item's default `min-height: auto` stops `overflow-y-auto` from clipping, so tall pages push the document into a second scrollbar and the sticky topbar scrolls out of view. html/body also carry `overflow-hidden` for the app shell.

Do NOT copy that `overflow-hidden` to components/layouts/guest.blade.php — the auth screens are a centred card that must stay scrollable on short viewports.

Anything added inside `<main>` should let main do the scrolling rather than introducing its own full-height scroll container.

## wire:navigate resets the <html> class, so the theme must be re-applied
Dark mode is a `dark` class on `<html>` plus `localStorage.theme`; the server never
renders that class. A `wire:navigate` page swap copies the incoming document's
`<html>` attributes over the live ones, so every SPA navigation silently dropped
the theme — which reads to a user as "the topbar toggle doesn't work".

layouts/partials/head.blade.php therefore defines `applyStoredTheme()`, calls it
before first paint **and** binds it to `livewire:navigated`. Use
`classList.toggle('dark', shouldBeDark)`, not `add`, or navigating after choosing
light re-adds dark on an OS that prefers dark. AppLayoutTest pins both lines.

Anything else that lives on `<html>` or `<body>` and is set from JS has the same
problem — put it in that listener too.
