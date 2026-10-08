---
name: "source-command-phase-gate"
description: "Migrated source command `phase-gate`"
---

# source-command-phase-gate

Use this skill when the user asks to run the migrated source command `phase-gate`.

## Command Template

Identify the current ONDA Storage phase from the conversation (or ask if ambiguous), then run that phase's acceptance-gate commands exactly as specified for it in the phase prompt (see the `onda-storage-prompts` skill for the per-phase list — e.g. P1 is `migrate:fresh --seed` + `test --compact` + `types:check`; P2 is `test --compact --filter=Vault` + `types:check`; P3 adds the curl upload script; etc.).

Print a PASS/FAIL table, one row per acceptance criterion for that phase.

If any row is FAIL: state that the gate is not met, do not propose fixes unless asked, and do not treat the phase as complete. Refuse to start the next phase's work in this same command.

If every row is PASS: say the gate is met and that the next phase may begin in a fresh session.
