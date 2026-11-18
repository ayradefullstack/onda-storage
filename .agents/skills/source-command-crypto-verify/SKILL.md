---
name: "source-command-crypto-verify"
description: "Migrated source command `crypto-verify`"
---

# source-command-crypto-verify

Use this skill when the user asks to run the migrated source command `crypto-verify`.

## Command Template

Run only the `Domain/Vault/Crypto` unit tests:

```
php artisan test --compact --filter=Vault
```

(narrow further to the `tests/Unit/Vault` path if the filter also picks up unrelated feature tests)

Report explicitly, one line each, PASS/FAIL:
- offset round-trip (byte 0 and a non-zero chunk index)
- Range read across a MAC segment boundary
- MAC failure detection on a flipped bit

Then list any other failing assertions in that run. Do not summarize passing tests beyond the three lines above.
