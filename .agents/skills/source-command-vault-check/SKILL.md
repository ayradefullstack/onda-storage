---
name: "source-command-vault-check"
description: "Migrated source command `vault-check`"
---

# source-command-vault-check

Use this skill when the user asks to run the migrated source command `vault-check`.

## Command Template

Run `composer test` and `npm run types:check`.

Report ONLY failures — file, line, and the actual error message. If both commands pass, say so in one line and stop. Do not summarize or list passing tests, passing suites, or file counts.
