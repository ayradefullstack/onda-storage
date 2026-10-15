---
name: "source-command-author-dashboard-cleanup"
description: "Migrated source command `author-dashboard-cleanup`"
---

# source-command-author-dashboard-cleanup

Use this skill when the user asks to run the migrated source command `author-dashboard-cleanup`.

## Command Template

# Task: Author Dashboard — Data Integrity Audit and UI/UX Cleanup Proposal

Act as a Senior Laravel Engineer, Senior Vue 3 / Inertia.js Developer, and Senior UI/UX Designer specializing in legal deposit and copyright registry platforms.

The objective is to audit and clean the author dashboard so that it displays only genuine, server-backed, useful information.

The current dashboard (`resources/js/pages/author/Dashboard.vue` and `resources/js/components/dashboard/**`) may contain hardcoded statistics, demo lists, placeholder charts, fake activity, sample certificates, or other content that could mislead authors about the status of their copyright deposits.

This is a data-integrity issue, not merely a visual redesign.

## CRITICAL EXECUTION RULE

**Perform Step 0 only. Do not modify any files. Do not implement anything. Stop and wait for my explicit approval.**

Read `AGENTS.md` first and follow its project-specific instructions.

Then inspect the relevant frontend components, backend controller, routes, models, database schema, translations, tests, and existing services needed to understand the current dashboard.

Do not assume a data source exists simply because a component displays a value.

## 1. Audit Every Dashboard Block

Inspect `pages/author/Dashboard.vue` and every component it imports from `components/dashboard/**`.

List every visible block in its actual display order.

For each block, provide:

- Display name.
- File and component responsible for rendering it.
- Data currently displayed.
- Whether the data is static, mocked, computed locally, or supplied by the server.
- The actual source of the data, if one exists.
- Whether the block provides meaningful value to an author.
- Your recommended action and justification.

Classify each block using exactly one of these categories:

1. **REAL-VALUABLE** — backed by genuine application data and useful to authors; retain it.
2. **REAL-LOW-VALUE** — backed by real data but redundant or not useful enough to justify its space; propose removal.
3. **FAKE-WIREABLE** — currently displays fake or hardcoded content, but an authoritative data source already exists; propose connecting it to the real source.
4. **FAKE-NO-SOURCE** — contains fabricated content without an available authoritative source; propose removing it.

For every fake block, quote the actual hardcoded values or a short code excerpt and provide the exact file and location.

Do not label content as fake solely because its value is not immediately obvious. Trace its source before classifying it.

Also identify real information that could become misleading because it is stale, calculated incorrectly, not scoped to the authenticated author, or not consistent with the actual deposit workflow.

## 2. Audit the Surrounding Author Interface

Inspect the relevant author header, layout, and shared components, including:

- Notification popover: identify whether notifications come from real persisted records.
- User menu and any displayed statistics.
- Sidebar badges and counters.
- `AggregateProgressBar`.
- `NewDepositModal`.
- `CertificateModal`.
- Any charts, recent-activity feeds, storage indicators, progress indicators, welcome messages, or decorative statistics.

For each item, explain whether it is genuine, useful, redundant, or potentially misleading.

Pay particular attention to certificates and attestations.

A certificate or attestation must never be represented as an issued legal document unless a real, server-generated document exists for the corresponding registered work.

Inspect the actual certificate generation and retrieval flow. If `CertificateModal` displays a preview, simulated certificate, placeholder reference, or other unverified document, identify exactly what it shows and recommend the appropriate correction.

Do not change shared components or unrelated pages during this audit.

## 3. Trace the Real Data Sources

Inspect the existing application architecture to establish whether each proposed dashboard feature can be implemented reliably.

Investigate:

- The actual oeuvre status values and transitions.
- The authenticated author's relationship to their oeuvres.
- The existing submission eligibility rules and `SubmissionGate`.
- Required document definitions and validation rules.
- Real decision dates and rejection reasons.
- File upload and processing states.
- The existing upload store and any persistence mechanisms.
- Storage quotas, reservations, used bytes, and quota limits.
- Notification persistence, types, and links to oeuvres.
- The existing author oeuvres listing and its supported filters.
- The current dashboard controller and route.
- Existing localization keys in Arabic, French, and English.

Distinguish between data that is genuinely persisted in the database and transient frontend state.

For every proposed widget, report:

- Whether its source exists.
- Where the source lives.
- Whether the source is authoritative.
- Whether additional backend work would be needed.
- Whether the feature should instead be removed.

Do not invent schema fields, model relationships, status names, routes, or services. Verify them in the actual repository.

## 4. Proposed Target Dashboard

Evaluate the following layout against the real implementation. Keep only features whose required data sources and business rules are verified.

### A. Header

- Greeting using the authenticated author's real name.
- Existing primary action: "Nouveau dépôt".
- Preserve the current deposit creation flow.

### B. Status Summary

Four compact cards, provided that the real status values support these categories:

- Brouillons.
- En attente: submitted plus under review, where appropriate to the actual workflow.
- Enregistrées.
- Rejetées.

Requirements:

- Counts must be calculated from real, author-scoped records.
- Zero must display as `0`.
- No placeholder counts or percentages.
- Each card should link to an existing, verified listing filter.
- If the required status mapping is ambiguous, flag it instead of guessing.

### C. Actions requises

Display this block only when genuine actionable items exist.

Potential items:

- Rejected works: real title, actual decision date, genuine rejection reason, and an action to correct and resubmit.
- Incomplete drafts: real title, exact missing required documents, and an action to complete the submission.
- Complete drafts not yet submitted: real title, a "Prêt à soumettre" state, and the existing submit action.

The missing-document list must use the same eligibility rules and required-document definitions as the existing submission flow.

Do not duplicate or approximate `SubmissionGate`.

Do not expose an action unless the current user's permissions and actual workflow permit it.

### D. Dépôts en cours

Show only real uploads or processing tasks that the application can reliably identify.

Inspect whether the upload store tracks active operations and whether processing states are available from the server.

Do not present persisted upload states as live progress if no authoritative live-progress mechanism exists.

Hide the block when no genuine active operations exist.

### E. Œuvres récentes

Show up to the five most recently updated works belonging to the authenticated author.

Potential fields:

- Title.
- Reference displayed using `<bdi dir="ltr">`.
- Actual status badge.
- Last update.
- Link to the corresponding existing details page.

Use actual timestamps and existing routes. Limit the query and avoid N+1 database queries.

### F. Espace de stockage

Show storage usage only if an authoritative quota record or equivalent supported source exists.

Investigate the quota calculation already used during upload initialization.

The dashboard must reuse the existing authoritative calculation rather than implementing a separate approximation.

If supported, show:

- Used bytes plus live reserved bytes.
- Actual quota limit.
- Human-readable usage such as `X Go sur Y Go`.
- A warning state when usage exceeds 80%.

Hide the widget if the author has no applicable quota record.

Report whether quotas exist and are usable for every author account, including newly created accounts.

### G. Dernières décisions

Show up to three genuine decision notifications related to the author's works, if the actual notification system supports them.

Potential decision types include registered, rejected, and under review, but verify the actual event model and status semantics.

Link each item to the correct existing work page.

Hide the block when no relevant notifications exist.

### H. First-Time Author

When the authenticated author has no oeuvres, determine whether the application supports a dedicated first-time empty state.

If appropriate, replace the normal dashboard content with one honest, concise empty state containing:

1. Create an oeuvre.
2. Upload the required documents.
3. Submit the deposit.

Include the existing "Créer mon premier dépôt" action.

Do not show sample works, fake activity, invented statistics, or fabricated examples.

Verify whether the empty-state condition should be based on having no oeuvres at all or another meaningful business condition. Explain the distinction in your proposal.

## 5. Remove Unnecessary or Misleading Content

Recommend removing any content that has no genuine, useful source, including:

- Fake trend charts.
- Hardcoded activity feeds.
- Invented percentages or progress indicators.
- Fictional royalty or revenue figures.
- Demo names, dates, and references.
- Fake certificates or attestations.
- News items or statistics without an authoritative source.
- Decorative widgets that add no meaningful information.

Do not automatically remove all static interface text. Labels, headings, explanatory text, and legitimate empty-state instructions are necessary UI copy and should be managed through the existing localization system.

Preserve the existing font, color, and design tokens.

## 6. Required Audit Deliverables

Before requesting approval, produce:

### Deliverable 1 — Block Inventory

A table containing the blocks in display order, their files, data sources, classifications, and recommendations.

### Deliverable 2 — Evidence of Fake Data

For every suspected fake element, provide the actual code excerpt, exact location, and explanation of why it is not backed by an authoritative source.

### Deliverable 3 — Data Source Matrix

For every proposed retained block, identify the existing model, relationship, service, controller prop, store, or other verified source.

Explicitly mark unavailable or unverified sources.

### Deliverable 4 — Proposed Desktop and Mobile Wireframes

Provide simple ASCII wireframes for the final dashboard, based only on features supported by the actual application.

Desktop example:

[Greeting + Nouveau dépôt]

[Drafts] [Pending] [Registered] [Rejected]

[Actions requises — only when non-empty]

[Recent works                  ] [Storage quota — if available]

[Recent decisions — if available]

Mobile example:

[Greeting]
[Nouveau dépôt]

[Drafts]
[Pending]
[Registered]
[Rejected]

[Actions requises]
[Recent works]
[Storage quota — if available]
[Recent decisions — if available]

Adjust the wireframes according to the actual data sources and useful content identified during the audit.

### Deliverable 5 — Backend and Testing Plan

List the controller props, queries, relationships, services, localization changes, and tests that would be required.

Identify potential performance risks, authorization concerns, missing data sources, and any ambiguity in the business rules.

### Deliverable 6 — Implementation Scope

List the exact files likely to change, along with files that must remain untouched.

## 7. Mandatory Approval Gate

**STOP after completing the audit and proposal.**

Do not create, modify, rename, or delete files. Do not implement the redesign. Do not run commands that modify the project.

Present your findings and wait for my explicit approval before proceeding.

The purpose of this stage is to agree on what is real, what is useful, what should be removed, and what can safely be connected to authoritative data.

No implementation without approval.
