---
name: "source-command-author-rejected-files-ui"
description: "Migrated source command `author-rejected-files-ui`"
---

# source-command-author-rejected-files-ui

Use this skill when the user asks to run the migrated source command `author-rejected-files-ui`.

## Command Template

Task: show the author which files the admin rejected, in red, with each file's own reason, and let the author delete a rejected file and upload the required one. Accepted files can never be deleted or changed by the author. Read AGENTS.md first.

This brief REPLACES Part 3 of `author-file-replacement` and Parts A and B of `author-review-experience` (the author UI). It keeps their backend work (`replaces_media_file_id`, the reservation on `upload_sessions`, `RemoveMediaFile`, the 409 codes) and Part C of the addendum. If the backend of the continuation brief is not finished, finish it first (its Checkpoint 0 and 1), then do this.

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

## What the product owner wants (the whole feature, in four lines)
1. After the admin rejects a file, **that file appears to the author in red**, with the admin's reason.
2. The author can **delete it and upload the required file** in its place.
3. **Each rejected file shows its own reason.**
4. **Accepted files cannot be deleted or replaced by the author**, in any oeuvre status.

## What the screenshot of the current state shows (fix all of it)
- The author page (the "Pièces exigées pour ce collège" slots) shows **no review state at all**: every file looks "Déposé / Certifié", and **every file has a trash icon, accepted ones included.** The admin side of the same oeuvre shows 3 accepted and 3 rejected files, each rejected one with its reason.
- That oeuvre has status **"Rejetée"** (the whole oeuvre was returned to the author), not "under review". **The earlier briefs only covered `under_review`.** The same file rules must apply in both `under_review` and `rejected` oeuvres.
- The breadcrumb reads "Works" on both the admin and the author page. It is an untranslated leftover of the old name.

## Backend rules (small; with tests)
1. **Accepted files are never removable by the author, in any oeuvre status.** Add this to the removal authorization (it also hides `can_remove`). Server answer 403. The UI follows the server flag.
2. **Rejected oeuvre (returned to the author):**
   - the author may remove a file that is `rejected` or `pending`, never an `accepted` one;
   - uploads work as today (the oeuvre is editable). When the target slot has an unreplaced removed-rejected file, link the new file to it (`replaces_media_file_id`) so the "Remplace : …" label and the admin history work in both statuses;
   - the admin is **not** notified for each replacement while the oeuvre is `rejected` (nobody is reviewing). The existing submit notification covers the resubmission. `ReplacementFileUploaded` is sent only while the oeuvre is `under_review`.
3. **Accepted decisions survive the resubmission:** `review_status` is never reset when the oeuvre goes `rejected → submitted → under_review`. The admin only has to review the files that are `pending`. Add a test.
4. **"Soumettre à nouveau" is blocked while a file of the oeuvre is still `rejected`** (not removed). The gate answers with the list of those files and their reasons, and the button is disabled with the same message. Without this rule the admin would see the same rejected file again. Reuse `SubmissionGate`; this is the only change to it.
5. Under review: a slot accepts an upload only if it has an unreplaced removed-rejected file (as already built). In a `rejected` oeuvre the existing behaviour stays.
6. The author's own pending replacement may be removed while it is not accepted (Part C of the addendum, unchanged).
7. The author page props expose, per file: `review_status` (key), `review_reason`, `reviewed_at`, `can_remove`, and, per slot, `open_replacement`. No reviewer identity, ever: the author sees "ONDA".

## UI — the author oeuvre page (existing tokens only, ar/fr/en, RTL, light and dark, reduced motion)
Colour is never the only signal: every state has an icon and a text label.

**A file row, by state:**
| State | What the author sees |
|---|---|
| uploading / processing | the existing progress and pipeline steps |
| `pending` | neutral row, clock icon, badge "En attente d'examen" |
| `accepted` | success accent, **lock icon**, badge "Accepté", **no trash icon, no actions.** Tooltip and `aria-label`: "Accepté par l'ONDA : ce fichier ne peut plus être modifié" |
| `rejected` | **red (destructive tokens): a start-side accent bar, a tinted background, a circle-x icon, badge "Rejeté"**, then the reason callout and one primary action, "Supprimer et remplacer" |
| a replacement | neutral row, badge "En attente d'examen", label "Remplace : {old file name}" |

**The reason callout** sits directly under its own rejected file (one callout per file; never a shared list): the heading "Motif du rejet", the full reason, the decision date, the signature "ONDA", and one line: "Supprimez ce fichier, puis téléversez un nouveau fichier conforme." A long reason collapses to about 4 lines with "Voir plus".

**"Supprimer et remplacer"** opens an `AppDialog` (sm): the file name, the reason recap, and "Le fichier actuel sera supprimé définitivement. Vous pourrez ensuite téléverser le nouveau." The confirm button is destructive, shows a spinner while busy, and Esc is blocked while busy. On success: toast, scroll to the slot's open dropzone, **move focus to it**, announce it in an `aria-live="polite"` region, and play one soft pulse.

**The open-slot dropzone** ("Remplacement attendu"): a dashed destructive-tinted border, and helper text with the slot's accepted formats and maximum size plus the rejected file's reason as a reminder. A file of a wrong type or size is refused in the browser before the upload starts.

**At the top of the page, only when something is rejected:** a red alert "{n} fichier(s) rejeté(s) : à remplacer", listing each rejected file (name, slot) with a "Aller au fichier" link that scrolls to the row, focuses it and pulses it once. On mobile (390 px) the same alert is a sticky bottom bar. Beside it, three counters (accepted / waiting / to fix) with `useCountUp`, zero shown as 0.

**"Soumettre à nouveau" (rejected oeuvre):** disabled while a file is still rejected, with the reason "{n} fichier(s) rejeté(s) doivent être remplacés". When nothing is rejected, it is enabled as today.

**Slot headers** show the slot state: "À corriger" / "En attente" / "Accepté". A fully accepted slot shows no dropzone while the oeuvre is under review.

**Untranslated breadcrumb:** replace "Works" by the localized label (fr "Œuvres", ar "الأعمال" or the term the app already uses in the menu, en "Works") on the admin and author pages. grep for other untranslated leftovers of the old name in the visible UI and report them; fix the obvious ones, ask about the rest.

## Tests
- **Pest:**
  - accepted files: removal → 403 in every oeuvre status (`under_review`, `rejected`, `registered`), `can_remove` false;
  - a rejected oeuvre: a rejected and a pending file are removable, an accepted one is not; an upload into a slot with an unreplaced removed-rejected file links `replaces_media_file_id`;
  - no `ReplacementFileUploaded` notification for a replacement while the oeuvre is `rejected`; sent once while it is `under_review`;
  - `review_status` survives `rejected → submitted → under_review` (accepted stays accepted);
  - resubmission is blocked while a rejected file remains, with the list and reasons; allowed once every rejected file is removed and the required slots are filled again;
  - the props carry the per-file fields and no reviewer identity; the query count stays under the fixed ceiling.
- **Vitest:**
  - an accepted file has the lock, the tooltip and no trash icon or action; a rejected file has the red state, its own callout and the single action; each state shows an icon and a label;
  - two rejected files show two separate callouts with their own reasons;
  - the top alert lists the rejected files; "Aller au fichier" focuses the row; the mobile bar appears only when something is rejected;
  - "Supprimer et remplacer": confirm → remove → focus on the dropzone; Esc blocked while busy;
  - the dropzone shows formats and size, and a wrong type or size never enters the queue;
  - "Soumettre à nouveau" is disabled with the reason while a file is rejected;
  - the breadcrumb is localized in ar/fr/en;
  - ar (RTL) and fr, light and dark, reduced motion (no pulse, no smooth scroll).
- **Manual (browser, ar and fr, a real upload with the media worker running). Use the same situation as the screenshot:**
  - an oeuvre with 6 files: as admin accept 3 and reject 3 (one with a chip plus custom text), then reject the oeuvre;
  - as the author: the 3 rejected files are red with their own reasons; the 3 accepted ones show the lock and no trash icon; try the removal endpoint on an accepted file directly → 403;
  - "Supprimer et remplacer" on one: confirm, then the dropzone is focused; upload a wrong-type file (refused in the browser), then a correct one;
  - "Soumettre à nouveau" stays disabled until all three rejected files are replaced;
  - resubmit; as admin: the 3 accepted files are still accepted, only the 3 replacements are pending; "Suivant à examiner" jumps over the accepted ones; accept them and accept the oeuvre.

## Owned files — touch nothing else
```
resources/js/pages/author/oeuvres/**, resources/js/pages/admin/oeuvres/** (breadcrumb only)
resources/js/components/** (author file list, slot, dropzone, alert, bar, dialog, breadcrumb)
resources/js/lib/** (the client-side type and size pre-check only)
app/Actions/** (the removal authorization; the SubmissionGate rule for rejected files)
app/Http/Controllers/Author/** (props)
resources/js/locales/{ar,fr,en}.json, lang/{ar,fr,en}/**
AGENTS.md (the accepted-files rule, the resubmission rule)
tests/**
```
FROZEN: the upload protocol, chunking, scheduler and crypto; the pipeline; `OeuvreStatusMachine` and the oeuvre decision actions; the admin decision logic; the hash chains; the consultation security behaviour; colour and font tokens.

## Acceptance gate
1. A short report of what was already built versus what this brief added.
2. `composer test` with before/after counts; Vitest, types, lint, format all 0; `npm run build`.
3. The manual check above, with screenshots of the author page (red rejected rows with their callouts, the locked accepted rows, the top alert, the dropzone, the disabled resubmit button) in ar and fr.
4. `git status --short`

Then STOP.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `git mv`, `--cached`, `update-index`).
- Never rewrite or empty a file with a script; use the Edit tool. If a file is ever damaged, restore it from the index first (`git show :<path>`), then compare with HEAD.
- Leave every change unstaged. Stop only processes you started. Never kill all php.exe or node.exe.
- End by printing `git status --short`.
