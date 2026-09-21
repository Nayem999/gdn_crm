---
paths:
  - 'app/Domain/Shared/UI/NavIconPalette.php, resources/views/layouts/partials/sidebar.blade.php, resources/views/components/settings-shell.blade.php'
---

# Views Components

## Navigation icon colours come from NavIconPalette, keyed on the icon name
Sidebar and Settings navigation icons are coloured per-icon by `App\Domain\Shared\UI\NavIconPalette`, not by hand in the template.

- Keyed on the **lucide icon name**, not the menu label, so one icon is one colour everywhere: `building-2` is indigo as Accounts in the sidebar and as Company under Settings.
- Two accessors, because the surfaces differ: `onDark()` for the main sidebar (dark in both themes, so a single 400 shade) and `onPage()` for the Settings nav (on the page background, so a `text-x-600 dark:text-x-400` pair). Using the wrong one makes the icon vanish in one theme.
- Icons keep their colour on hover and while active. Washing them to white was the old treatment and the regression test asserts `h-5 w-5 shrink-0 text-slate-400` is gone.
- Every class is written out in full, like ChipPalette — Tailwind cannot see a class built at runtime.
- An unlisted icon falls back to `crc32($icon) % tones`, so a runtime-created custom module gets a stable colour rather than grey. With 16 tones and ~50 icons, collisions between non-adjacent rows are expected and fine; what the map is careful about is **adjacency** — two identical colours in neighbouring rows of the same group is the bug to avoid (it caught Scheduling/Notifications both amber).
- After changing a tone, run `npm run build`: a colour no template used before is not in the stylesheet yet.
