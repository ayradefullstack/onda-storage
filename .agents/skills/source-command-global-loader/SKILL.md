---
name: "source-command-global-loader"
description: "Migrated source command `global-loader`"
---

# source-command-global-loader

Use this skill when the user asks to run the migrated source command `global-loader`.

## Command Template

Task: restore the loading screen on every navigation, smart, themed (dark/light) and well animated. Read AGENTS.md first.

Context: the landing page has a full-screen preloader (`components/public/FullscreenPreloader.vue` + `composables/useGlobalLoader.ts`). The UI-polish task limited it to the first load. The product owner wants it back on **every request**, in both themes, more polished.

A naive "overlay on every visit" is bad UX: it flashes on fast navigations, blocks the page, and would fire every 5 s during the consultation polling. So implement it **smart**, as specified below.

## Constraints
- **No new colours or fonts.** Only existing tokens; the brand green stays as it is. Dark/light through the app's existing appearance mechanism (`.dark` class / `appearance` cookie / system preference).
- No new packages. CSS keyframes, Vue `<Transition>`, a small composable.
- Animate transform and opacity only. `prefers-reduced-motion: reduce` replaces all motion with a static logo and a simple fade.
- RTL first (ar), plus fr and en.
- Do not touch the upload protocol, the consultation viewers or the backend.

## Step 0 — Report (no code)
1. The current preloader: files, what it looks like, when it shows, and what the UI-polish task changed. Is that task finished, or still in the working tree?
2. Every source of Inertia visits that must NOT trigger the overlay: partial reloads (`only`), the consultation poll, filter/search/sort/pagination visits with `preserveState` / `preserveScroll`, prefetch, and anything in the upload store.
3. How the theme is resolved on first paint (blade + cookie), so the initial loader never flashes white in dark mode.

Continue unless something contradicts this brief.

## Behaviour (the important part)
- **First page load:** an inline loader in `resources/views/app.blade.php` (plain HTML + inline CSS, no JS dependency), themed from the same server-side `dark` class / system preference. It is removed with a fade when the Vue app mounts. No white flash in dark mode.
- **Inertia visits (`router.on('start' | 'progress' | 'finish')`):**
  - **Delay threshold 300 ms:** the overlay appears only if the visit is still running after 300 ms. Faster visits show nothing but the top progress bar.
  - **Minimum display 450 ms** once shown, to avoid a flicker.
  - **Never** for: partial reloads (`only` / `except`), prefetch, polling, visits marked `preserveState` + `preserveScroll` (filters, search, sort, pagination), or an opt-out flag `{ meta: { silent: true } }`, which the consultation poll and any background refresh must use.
  - **Over 3 s:** a second line, "Encore un instant…". **Over 15 s:** "La connexion semble lente", with a "Réessayer" button that reloads the current visit. Never trap the user.
  - When the visit reports progress (form uploads), show a determinate ring with a percentage; otherwise indeterminate.
  - Cancelled or failed visits remove the overlay immediately.
- **Overlay style:** do not blank the page. Use a translucent backdrop (theme background at ~70–80 % opacity plus a light blur where supported), so the current page stays faintly visible underneath. Scroll is locked only while the overlay is visible, with no layout jump (scrollbar gutter).
- **Progress bar:** keep Inertia's top bar for short visits, with the same existing colour. You may touch `resources/js/app.ts` only for the progress options (delay, showSpinner: false); never the colour.

## Visual and animation (attractive, but calm: this is an official registry)
- **Centre piece:** the existing ONDA logo mark (SVG from the current preloader or logo component).
  - Entrance: the mark draws itself (stroke-dashoffset), then fills (≈700 ms, once).
  - While waiting: a slow "breathing" scale of 0.98–1.02 (≈2.4 s loop), and a thin circular ring around it rotating (indeterminate) or filling (determinate). The ring uses the brand token at full opacity on a 15 % track of the same token.
  - Below: the localized status text with a soft fade between messages, and three dots with a staggered rise.
- **Theme:**
  - light: page background tone with the brand mark;
  - dark: dark background token with the same mark, plus a subtle glow (`drop-shadow` from the brand token at low opacity).
  - Both read correctly in RTL.
- **Exit:** the mark scales to 1.04 while the overlay fades out (≈200 ms). The page content does its existing page transition (from the UI-polish work) right after, with no double animation.
- **Reduced motion:** a static mark, static ring, text only, and a plain 120 ms fade.
- **Accessibility:** `role="status"`, `aria-live="polite"`, a localized label ("Chargement…" / "جارٍ التحميل…" / "Loading…"), `aria-busy` on the main region while loading. The overlay takes focus only when the slow-connection button appears.

## Implementation
- One component, `components/AppLoader.vue`, used by the landing page and the app layouts. It replaces `FullscreenPreloader.vue`; delete the old one once nothing uses it.
- One composable, `useGlobalLoader.ts`, holds the state machine (idle → pending (300 ms) → visible (min 450 ms) → slow (3 s) → very slow (15 s) → hiding) and all the exclusion rules. Pure, testable logic; timers injectable for tests.
- Keyframes live in the "motion" section of `resources/css/app.css`.
- Add `meta: { silent: true }` to the consultation poll and to every background refresh found in Step 0.

## Tests (Vitest + happy-dom)
- A visit under 300 ms never shows the overlay.
- Over 300 ms it shows, and it stays at least 450 ms.
- The 3 s and 15 s messages appear; "Réessayer" reloads.
- No overlay for: `only` partial reloads, prefetch, `silent`, `preserveState` + `preserveScroll`, and the consultation poll (assert its visits carry `silent`).
- A cancelled or failed visit hides the overlay immediately.
- Reduced motion produces the static variant.
- The dark class gives the dark variant; the RTL layout renders correctly.
- The `role` / `aria-live` attributes are present.
- The blade initial loader exists, and is removed on mount (feature test or DOM test).

## Owned files
```
resources/views/app.blade.php                 ← inline initial loader only
resources/js/app.ts                           ← progress options only, colour unchanged
resources/js/components/AppLoader.vue (new), resources/js/components/public/FullscreenPreloader.vue (delete when unused)
resources/js/composables/useGlobalLoader.ts
resources/js/layouts/**                       ← mount AppLoader
resources/js/components/consult/**            ← add meta.silent to the poll only
resources/js/pages/**                         ← add meta.silent to background refreshes only
resources/css/app.css                         ← "motion" section only
resources/js/locales/{ar,fr,en}.json          ← loader strings only
tests for the above
```
FROZEN: colour and font tokens, the backend, the upload store, the consultation viewers' security behaviour.

## Acceptance gate
1. The Step 0 report.
2. `composer test` green; Vitest, types, lint and format all 0; `npm run build`.
3. Manual check in light and dark, ar and fr:
   - a fast navigation shows only the bar;
   - a slow navigation (throttle the network to "Slow 3G" in devtools) shows the overlay, then the 3 s message;
   - the review page poll never shows it;
   - first load in dark mode does not flash white.
   Describe each, or paste screenshots.
4. `git status --short`

Then STOP.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation.
- Leave every change unstaged. Stop only processes you started.
- End by printing `git status --short`.
