---
name: "source-command-ui-polish"
description: "Migrated source command `ui-polish`"
---

# source-command-ui-polish

Use this skill when the user asks to run the migrated source command `ui-polish`.

## Command Template

Task: UI/UX polish of the admin and author dashboards: responsive preview, modals, selects, animations and scroll animations. Read AGENTS.md first.

You act as a senior frontend engineer with a UI/UX eye. If the `frontend-design` skill is available in this environment, read it before the plan. If an accessibility review skill is available, run it on the result.

## Hard constraints
- **Do not change fonts or colours.** Use only the existing design tokens (CSS variables / Tailwind theme): no new palette, no new typeface, no new hex values. Shadows, radii and spacing come from the existing scale.
- No new UI library. Build on the existing `components/ui` (reka-ui), Vue `<Transition>` / `<TransitionGroup>`, Tailwind v4 and plain CSS. A small composable is fine. Adding an animation package needs my approval first.
- Animate **transform and opacity only** (no animated width, height, top or left that causes layout shifts). No CLS.
- **`prefers-reduced-motion: reduce`** disables every non-essential animation (instant transitions, no reveal, no stagger).
- **RTL first:** Arabic is the main locale. Direction-aware motion (a slide "from the start side" mirrors in RTL). Use logical properties (`ms-`/`me-`, `start`/`end`).
- No behaviour, route or backend change. The consultation viewers keep their security behaviour (watermark, no download, sandboxed docx iframe).

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build` passes.

## Step 0 — Audit and plan (no code; wait for my approval)
1. An inventory, with paths: the Dialog/modal usages, Select/Combobox usages, the viewer layouts (side viewer on admin/author oeuvre pages, `FileReviewLayout`), dashboard pages, tables, empty and loading states, and any existing transitions.
2. Screenshots, or precise descriptions, of the admin oeuvre page, the review page, an author oeuvre page, one modal and one long select, at 390, 1366, 1920 and 2560 px wide. Note what breaks or wastes space.
3. A short plan per section below: what changes, which components, and which shared primitives you will create. Flag anything that would touch colours or fonts: it is not allowed.

STOP after Step 0 and wait for "go".

## 1. Preview that grows with the screen
- **Side viewer (oeuvre pages):**
  - two-column layout with a list column and a viewer column;
  - viewer share by breakpoint: lg ≈ 55 %, xl ≈ 62 %, 2xl ≈ 70 %. Remove the page `max-width` cap on these pages so wide screens are used;
  - the viewer is `sticky` and its height follows the viewport (`calc(100dvh - header)`), so it stays visible while the file list scrolls;
  - below lg: the viewer becomes a full-height bottom sheet or full-screen panel, opened from the file row, with a close button and swipe-down on touch.
- **Full review page (new tab):** the viewer takes all the space left by the header (`100dvh`). The Details/Activity tabs live in a collapsible side panel on xl and up, and stack under the viewer on smaller screens.
- **Every viewer adapts live (`ResizeObserver`, debounced):**
  - PDF "fit width" re-renders on resize; manual zoom levels are preserved;
  - images use `object-contain` with zoom/pan;
  - video and audio keep their aspect ratio inside the available box;
  - the docx iframe height follows its content;
  - the sheet table scrolls inside its own area with a sticky header row and first column.
- A "Plein écran" toggle (Fullscreen API) on the viewer, with Esc to leave.

## 2. Modals
- Create one shared wrapper, `components/ui/app-dialog/AppDialog.vue`, on top of the existing reka-ui Dialog. Migrate every modal to it.
  - **Sizes:** `sm | md | lg | xl | full`.
  - **Layout:** a sticky header (title + close), a **scrollable body** (`max-height: min(90dvh, …)`, `overflow-y: auto`, `overscroll-behavior: contain`) and a sticky footer (actions). Long content scrolls inside the body and never pushes the buttons off-screen.
  - **Scroll shadows:** a subtle top/bottom fade that appears only when there is more content to scroll, using existing colour tokens.
  - **Motion:** the overlay fades; the panel fades + scales from 0.96 to 1 (≈180 ms ease-out) on desktop, and slides up from the bottom on mobile. Leaving is faster (≈120 ms).
  - **Behaviour:** focus trap, Esc closes (except when a destructive action is in progress), focus returns to the trigger, body scroll is locked without layout jump (scrollbar gutter compensation).
- The reject dialog's reason textarea auto-grows up to a max height, then scrolls. It shows a live character count and stays visible above the mobile keyboard.

## 3. Select dropdowns
- Through the existing reka-ui Select/Combobox:
  - content `max-height` around 320 px with internal scroll and scroll-up/down buttons;
  - `collisionPadding` so the menu never leaves the viewport;
  - the menu width matches the trigger;
  - the selected item shows a check;
  - full keyboard navigation.
- **Long lists become searchable comboboxes** (type to filter, accent- and case-insensitive, Arabic and French), with a "no result" state. Lists with more than ~12 options qualify: colleges, members, author roles, wilayas, communes, formats.
- Open/close motion: fade + 4 px slide from the trigger side (≈140 ms).
- Verify the old bug "the select shows only the selected option" cannot come back. Add a test.

## 4. Animations (subtle, purposeful)
- Inertia page navigation: a quick fade/slide of the main content (≈150 ms), plus a thin top progress bar during navigation using an existing accent token.
- Lists and cards on first render: staggered fade-up (20–40 ms per item, capped at the first 12 items; the rest appear without delay).
- Hover and press: cards lift slightly (translateY(-1px) plus the existing shadow step); buttons scale to 0.98 on press.
- Tabs: an animated underline indicator sliding between tabs.
- Status changes (e.g. a deposit becoming ready, an oeuvre accepted): a one-time soft highlight pulse on the badge.
- Upload progress: a smooth width transition on the bar (allowed here because the bar has its own layer and no siblings shift), and numbers animated with tabular-nums.
- Skeleton loaders (shimmer, existing neutral tokens) for the viewer, tables and stat cards while data loads, instead of empty boxes.
- Sidebar collapse/expand animation, with icons staying aligned.
- Dashboard stat numbers count up once on first view.

## 5. Scroll animations
- A `useReveal` composable (IntersectionObserver, run once, threshold ~0.15): sections and cards fade-up as they enter the viewport. It is disabled under reduced motion, and on lists longer than 50 rows.
- Sticky page header and table headers gain a soft shadow once the page is scrolled.
- A "back to top" floating button appears after one viewport of scroll on long pages (oeuvre lists, review history, activity tab), and scrolls smoothly back.
- Smooth scroll for in-page anchors, offset by the sticky header.
- Scroll shadows on every internal scroll container (file list, tables, modal body, select menu).
- On the review page, a thin reading-progress bar for long documents (PDF/DOCX/text) inside the viewer header.

## 6. Dashboards (admin and author)
- Tables:
  - sticky header, row hover, comfortable density;
  - horizontal scroll inside the card on small screens, with the first column sticky;
  - every action button reachable by keyboard.
- Empty states: an icon, one sentence and one clear action (existing icon set only).
- Consistent page header: title, breadcrumb and primary action, aligned on all dashboard pages.

## Shared primitives to create (reuse everywhere)
`AppDialog`, `ScrollArea` (with scroll shadows), `useReveal`, `useScrolled`, `BackToTop`, `Skeleton`, `PageTransition`, and a searchable `AppCombobox`. Put the animation keyframes and utilities in `resources/css/app.css` under a clearly marked "motion" section; do not touch the token sections.

## Tests
- Vitest:
  - `useReveal` does nothing under reduced motion and stops observing after the first reveal;
  - `AppDialog` keeps the footer visible with long content, returns focus, and closes on Esc;
  - `AppCombobox` filters Arabic/French case- and accent-insensitively and shows the empty state;
  - the select regression test;
  - the viewer recalculates fit-width on resize (mock `ResizeObserver`);
  - no colour hex values were added (grep in the diff, reported).
- Manual visual check at 390, 1366, 1920 and 2560 px, in ar and fr, light and dark if the app supports both. Paste screenshots or describe each.
- Reduced-motion check: enable the OS setting (or emulate it in devtools) and confirm the animations are off.

## Owned files — touch nothing else
```
resources/js/components/ui/**      ← new wrappers only (AppDialog, ScrollArea, Skeleton, AppCombobox); do not fork reka-ui
resources/js/components/**         ← adopt the wrappers; consult viewers: layout and resize only
resources/js/composables/**        ← useReveal, useScrolled, …
resources/js/layouts/**, resources/js/pages/admin/**, resources/js/pages/author/**
resources/css/app.css              ← "motion" section only; no token changes
resources/js/locales/{ar,fr,en}.json ← only new UI strings (Plein écran, back to top, no results…)
tests for the above
```
FROZEN: colour and font tokens, backend, routes, consultation security behaviour (watermark, no-download, iframe sandbox), the upload protocol and scheduler logic.

## Acceptance gate
1. The Step 0 audit and the approved plan.
2. `composer test` green; Vitest, types, lint and format all 0; `npm run build`, reporting the bundle size difference.
3. The visual check at the four widths, in ar and fr.
4. The reduced-motion check.
5. Confirmation that no colour or font value changed (diff grep).
6. `git status --short`

Then STOP.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `--cached`, `update-index`).
- Leave every change unstaged. I review and commit myself.
- Stop only processes you started. Never kill all php.exe or node.exe.
- End by printing `git status --short`.
