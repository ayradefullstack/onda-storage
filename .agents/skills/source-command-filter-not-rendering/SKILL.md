---
name: "source-command-filter-not-rendering"
description: "Migrated source command `filter-not-rendering`"
---

# source-command-filter-not-rendering

Use this skill when the user asks to run the migrated source command `filter-not-rendering`.

## Command Template

Task: on list pages, filters update the URL but the results do not appear until a manual refresh. Fix the root cause everywhere, not just on one page. Read AGENTS.md first.

## Symptom (manual test, author side, page "Oeuvres")
- Choosing a status filter: the URL changes (`?status=…`), but the list does not show the filtered results.
- "Effacer les filtres": the URL is cleared, but the list does not come back.
- F5 on the same URL shows the correct results. **So the server filters correctly; the client does not render the new data.** It happens every time.

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

## Part 1 — Root cause, with evidence (report, then continue)
Reproduce with Playwright on `onda-storage.test` as an author. For one filter change and one clear, record:
- the Inertia request (URL, `X-Inertia-Partial-Data` / `only` header, status) and whether the **response props** contain the filtered list (count + first ids);
- the Vue state of the list after the response (Vue devtools or a test hook);
- the DOM: are the rows present? Are they present but invisible (opacity 0, a reveal class never removed)?

Check these causes explicitly and say which one(s):
1. **Props copied into local state once:** `const items = ref(props.oeuvres)` / `reactive({...props})` / a `data` copy. With `preserveState: true` the component is not remounted, so the copy never updates.
2. **Wrong partial reload:** `only: [...]` names a prop that does not match the controller's prop name (e.g. `works` vs `oeuvres`), so the list is never sent back.
3. **Reveal / animation regression from the UI-polish work:** `useReveal` (run once) or a `TransitionGroup` stagger leaves the new rows at `opacity: 0` because they are inserted after the initial observation.
4. **Stale cached visit:** Inertia prefetch / `cacheFor` returning an old response for the new URL.
5. **Deferred or lazy props** (`Inertia::defer`, `Inertia::optional`) not included in the reload.
6. **Global loader exclusions** (`meta.silent`, `preserveState` handling) cancelling or swallowing the visit.
7. **`:key` problems:** the list keyed by index or a non-unique value, so Vue reuses stale nodes.

State the root cause in one sentence. It must be covered by a regression test in Part 3.

## Part 2 — The fix (one pattern for every list page)
- **One composable, e.g. `useListFilters`,** used by every filtered list (author oeuvres; admin oeuvres, authors, files, audit…; list them all):
  - the filter state is initialised from `props.filters` and **re-synced when the props change** (`watch(() => props.filters, …)`);
  - it calls `router.get(url, query, { preserveState: true, preserveScroll: true, replace: true, only: [<correct data prop>, 'filters'], meta: { silent: true } })`;
  - debounce for the text search (~300 ms); immediate for selects/chips; empty values are removed from the query; changing a filter resets `page` to 1;
  - "Effacer" sends an empty query (removes every filter param), and the inputs reset.
- **Lists read the props directly:** `computed(() => props.oeuvres)`, never a one-time copy.
- **Rows keyed by the record id.**
- **Reveal/animation:** rows inserted after the first render must be visible immediately (or re-observed). An animation must never be able to hide data. Fix it in `useReveal` / the list component itself, so every page benefits.
- **No prefetch cache** on filter visits (or the cache key includes the full query).
- A small loading state on the list while the visit runs (existing Skeleton or row dimming), and the existing empty state when the filter has no results ("Aucune oeuvre ne correspond à ces filtres" + "Effacer les filtres").
- Browser back/forward restores the filters and the matching results.

## Part 3 — Tests
- **Regression test for the Part 1 root cause** (mandatory).
- **Vitest:**
  - new props after a visit re-render the list (mount with props A, update to props B, assert the rows of B);
  - the filter state re-syncs from `props.filters` (also after back/forward);
  - `router.get` is called with the correct `only`, `preserveState`, `replace`, `meta.silent`, and without empty params; changing a filter resets the page;
  - "Effacer" sends an empty query and resets the inputs;
  - rows inserted after the first render are visible (no leftover reveal class / opacity 0);
  - the empty state appears for zero results.
- **Pest:** the author oeuvres endpoint filters by each status, ignores unknown values safely, and never returns another author's oeuvres.
- **Manual (Playwright):** on every list page found in Part 2: filter → results appear without refresh; clear → the full list comes back; back/forward; search + status combined; pagination after a filter.

## Owned files — touch nothing else
```
resources/js/composables/useListFilters.ts (new) and useReveal.ts (fix only)
resources/js/pages/author/** and resources/js/pages/admin/**   ← filter wiring of list pages only
resources/js/components/** list/table/filter components        ← props/keys/reveal fixes only
app/Http/Controllers/Author/*Oeuvre* (and admin list controllers) ← only if a prop name or filter param is wrong
resources/js/locales/{ar,fr,en}.json
tests for the above
```
FROZEN: the upload, vault, consultation and review logic; the global loader behaviour (only add `meta.silent` usage); colour and font tokens.

## Acceptance gate
1. The Part 1 evidence and root cause.
2. `composer test`: green, before/after counts. Vitest, types, lint and format all 0. `npm run build`.
3. The list of pages migrated to `useListFilters`, and the manual Playwright check on each.
4. `git status --short`

Then STOP.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `--cached`, `update-index`).
- Leave every change unstaged. Stop only processes you started. Never kill all php.exe or node.exe.
- End by printing `git status --short`.
