---
name: "source-command-role-split"
description: "Migrated source command `role-split`"
---

# source-command-role-split

Use this skill when the user asks to run the migrated source command `role-split`.

## Command Template

Restructuring task — separate admin and author by role. Read AGENTS.md first.

Baseline is zero across eslint, prettier, vue-tsc, Pint, phpstan and the full Pest suite,
and `composer ci:check` passes end to end. This refactor must end with all of that still
true and no behaviour changed.

## Two corrections to the brief, before anything else

**1. There is no separate `admin` guard, and there must not be one.**

A Laravel guard is an *authentication* mechanism bound to a user provider. A second guard
would mean a second user table, a second session, and a second login — and would break
Fortify, passkeys, two-factor and the existing session handling, all of which are wired to
the `web` guard.

This application authenticates every user through `web` and distinguishes them by
**spatie/laravel-permission roles**. `role:admin` and `role:author` are authorization
middleware, not guards.

So: both route files use the `web` guard. They differ only in their role middleware. If you
believe otherwise after reading `FortifyServiceProvider` and `config/auth.php`, STOP and
report rather than introducing a guard.

**2. Do not split the domain layer by role.**

Split the **delivery** layer — routes, controllers, form requests, Vue pages, navigation.
Those are role-shaped: an admin route and an author route are genuinely different things.

Do NOT split `app/Domain/**`, `app/Jobs/**`, `app/Actions/**` (except where an action is
genuinely admin-only, like a review decision), `app/Models/**`, or `app/Policies/**`.

The reason is in AGENTS.md's own architecture: dependencies point downward, and the domain
does not know about HTTP. A domain that does not know about HTTP certainly must not know
about roles. Concretely — `DecryptToTemp` is a pipeline operation, not an author operation.
`EncryptedLocalVault` serves both roles. `MediaFilePolicy` answers "can this user view this
file", which is the question *both* roles ask. Filing them under `Author/` or `Admin/` would
force arbitrary choices and produce imports crossing between two folders that should never
have existed.

Policies in particular: they are organised per model, not per role. That is the point of a
policy.

If a job or action turns out to be genuinely used by exactly one role and expresses a
role-specific use case — a review decision, for instance — then `app/Actions/Review/` is
reasonable. Named for the use case, not for the role.

## Deliver a plan first

Produce the full file-move map, the route table (URI, name, middleware, controller) before
and after, and your answers to the decisions below. Wait for approval. This refactor touches
nearly every layer, so a wrong assumption discovered mid-move is expensive.

## Target structure

```
routes/
├── web.php          public + locale-prefixed; requires the role files
├── settings.php     shared by both roles — do NOT duplicate per role
├── admin.php        prefix /admin,  name admin.,  middleware auth+verified+role:admin
└── author.php       prefix /author, name author., middleware auth+verified+role:author

app/Http/Controllers/
├── Admin/           dashboard, authors, review
├── Author/          dashboard, works, uploads
├── Media/           SHARED — both roles read files; do not duplicate
└── (settings, auth controllers stay where they are)

resources/js/pages/
├── admin/
├── author/
├── settings/        shared
├── auth/
└── Home.vue

resources/js/components/
├── admin/
├── author/
└── (shared components stay where they are)
```

## Decisions to make and justify, one line each

**1. Do the upload endpoints move to `/author/uploads/*`?**

They are author-only, so it is consistent. But they are a client-server contract referenced
by the frontend, the curl script in `scratch/`, and several tests. Moving them means moving
every caller. Pick one, be consistent, and if you move them, list every caller you updated.

**2. Do the media read routes move?**

Both roles read files. They belong in neither `/admin` nor `/author`. Recommend keeping them
at `/media/*` with authorization by policy rather than by URL prefix — but argue it.

**3. Route names.**

`admin.dashboard` and `author.dashboard` are clearer than two routes both named `dashboard`,
and the name collision forces the change anyway. But every renamed route breaks its callers:
Wayfinder helpers, `route()` calls in tests, redirect targets. Produce the complete rename
map in your plan so the blast radius is visible before you start.

**4. Settings.**

Shared by both roles. Keep one set of routes and one set of pages. Do not duplicate.

## Traps specific to this codebase

**Layout resolution is name-based.** `resources/js/app.ts` chooses the Inertia layout from
the page component's name: `Home` → `PublicLayout`, `auth/*` → `AuthLayout`, `settings/*` →
nested `[AppLayout, SettingsLayout]`, everything else → `AppLayout`. Moving pages into
`admin/` and `author/` changes those names. Verify every page still resolves to the right
layout, and update the resolver if the new prefixes need explicit handling. A page that
silently falls through to the wrong layout renders without navigation and is easy to miss.

**Locale handling.** Public routes are locale-prefixed (`/{locale}/...`); authenticated
routes are not, and take their locale from a cookie. `/admin/*` and `/author/*` are
authenticated and must NOT gain a locale prefix. Confirm `SetLocale` still resolves
correctly — a middleware reading the first path segment now sees `admin` or `author` where
it previously saw `dashboard`.

**Fortify redirects.** Login currently lands on a single path. With the split, an admin
should land on `/admin/dashboard` and an author on `/author/dashboard`. That needs a
`LoginResponse` that branches on role. Find every hardcoded `/dashboard` — `config/fortify.php`,
`app/Http/Responses/*`, any `HOME` constant — and handle each.

Also decide what happens to a user with **both** roles, or **neither**. Neither is the more
likely bug: a newly registered user before role assignment would land on a route they cannot
access, and see a 403 immediately after signing up. Handle it deliberately.

**Wayfinder.** Run `php artisan wayfinder:generate` after the routes settle and confirm the
generated helpers reflect the new URIs and names. The frontend calls through them, so stale
generated files fail at runtime rather than at build time.

**Test assertions.** Some tests assert a literal `/` redirect — those are correct and must
not change; Fortify's logout and verification responses genuinely redirect to root, which an
earlier session established after a wrong assumption in the opposite direction. Only update
assertions that reference the moved paths.

## Sidebar

One `AppSidebar` component, not two. The menu is data: an array of items each carrying the
role that may see it, filtered against the authenticated user's roles.

- Confirm roles are actually shared to the frontend. If `HandleInertiaRequests` does not
  currently share them, add it — that is in scope.
- Every link goes through a Wayfinder helper. If you find hardcoded paths, fix them and say
  which.
- The active-state logic must handle the new prefixes.
- A user with both roles sees both sections, clearly separated. Say how you handled it.
- All labels in ar/fr/en.

## Method

This refactor is mechanical but wide. Move in stages and keep the suite green at each stage
rather than moving everything and then debugging:

1. Routes: split the files, keep controllers where they are. Suite green.
2. Controllers: move and update namespaces. Suite green.
3. Vue pages: move and fix the layout resolver. Types and lint green.
4. Sidebar and navigation.
5. Fortify redirects and the role-based landing.

Report the state after each stage. If a stage cannot end green, stop there and report rather
than pressing on — a broken intermediate state compounds quickly in a move this wide.

**Namespace and import updates only.** No logic changes, no signature changes, no
"improvements" along the way. If you notice something worth fixing, note it in the report
and leave it. Mixing a refactor with fixes makes the diff unreviewable.

## Owned files

Everything under `routes/`, `app/Http/Controllers/`, `app/Http/Requests/`,
`app/Http/Responses/`, `app/Providers/`, `resources/js/pages/`, `resources/js/components/`,
`resources/js/locales/`, `config/fortify.php`, and `tests/`.

FROZEN — if a change is needed here, STOP and report:

```
app/Domain/**
app/Jobs/**
app/Actions/Upload/**
app/Models/**
app/Policies/**
database/migrations/**
config/vault.php
config/filesystems.php
```

Touching those would mean the split has leaked into the domain, which is the specific
outcome this task is structured to prevent.

## Acceptance gate

1. The plan, approved, then the staged build with a green report after each stage.
2. `php artisan route:list` — the full table, showing new URIs and names.
3. `php artisan test --compact` — 0 failures.
4. `composer ci:check` — still passes end to end.
5. Manual confirmation, reported: an admin logs in and lands on `/admin/dashboard` seeing
   only admin menu items; an author lands on `/author/dashboard` seeing only author items;
   an author hitting an `/admin` URL gets 403; settings work for both; logout still goes
   to `/`.
6. Confirm no authenticated route acquired a locale prefix.
7. Confirm nothing under the frozen list was modified.
8. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
