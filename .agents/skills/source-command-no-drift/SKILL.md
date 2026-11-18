---
name: "source-command-no-drift"
description: "Migrated source command `no-drift`"
---

# source-command-no-drift

Use this skill when the user asks to run the migrated source command `no-drift`.

## Command Template

Run `git status` and `git diff --stat HEAD` to see every file touched (staged, unstaged, and untracked) since the last commit.

Compare that file list against the current phase's declared ownership list (see the phase prompt in the `onda-storage-prompts` skill, or ask the user which phase is active if unclear).

Report as a table: file path → in-scope / OUT OF SCOPE. For anything out of scope, say so plainly and do not silently ignore it — flag it as a hard failure per the project's operating rules, even if the change looks harmless or like an improvement.
