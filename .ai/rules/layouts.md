---
paths:
  - 'resources/views/components/layouts/**'
---

# Layouts

## App shell: main is the only scroll container (needs min-h-0)
In components/layouts/app.blade.php the wrapper is `flex h-screen overflow-hidden`, the content column is `flex min-h-0 min-w-0 flex-1 flex-col`, and `<main>` is `min-h-0 flex-1 overflow-y-auto`. The `min-h-0` parts are load-bearing: a flex item's default `min-height: auto` stops `overflow-y-auto` from clipping, so tall pages push the document into a second scrollbar and the sticky topbar scrolls out of view. html/body also carry `overflow-hidden` for the app shell.

Do NOT copy that `overflow-hidden` to components/layouts/guest.blade.php — the auth screens are a centred card that must stay scrollable on short viewports.

Anything added inside `<main>` should let main do the scrolling rather than introducing its own full-height scroll container.
