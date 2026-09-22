# Copilot instructions

This repository keeps its agent guidance in **`AGENTS.md`** at the repo root,
with the detail in **`CLAUDE.md`** (architecture, commands) and
**`TESTING.md`** (test reference). Read `AGENTS.md` first and follow it.

The three things most often got wrong here:

1. Verify the environment before changing anything: `bin/test.sh --verify`
   (needs Docker; exits non-zero unless tests really ran).
2. PHPUnit testsuite names are **case-sensitive** — the suites are `Helper`,
   `model` (lowercase), `External`, `Controllers`, `SPIO Main`. An unknown
   name makes PHPUnit print `No tests executed!` and exit **0**, so always
   check the test count in the output.
3. Never edit `build/shortpixel/` — it is bundled/generated from the sibling
   `../modules/*` repos.
