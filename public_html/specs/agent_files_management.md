# Agent Instruction File Management

## Problem

We maintain `CLAUDE.md` and `GEMINI.md` as separate files. Three issues:

1. **Duplication** — both files should be identical, but updates require touching both.
2. **Shipping** — customers receiving the software need a different agent file than the one used internally (no admin credentials, different test URLs, references to internal infrastructure).
3. **Upgrade safety** — customer customizations to their agent file must survive `php utils/upgrade.php`.

## Goals

- One source of truth (or as close as practical) for shared instructions.
- Customers can customize their agent files without losing changes on upgrade.
- Mechanism scales to additional agents (Cursor, Aider, AGENTS.md universal file) without proportional pain.

## Non-goals

- Per-plugin contributions to the agent file. Plugins should not be able to mutate top-level agent instructions.
- Runtime expansion. Agent files are read once by the agent CLI at session start, not by `serve.php`.

## Options

### Option A: Symlink only

`GEMINI.md` becomes a symlink to `CLAUDE.md`. One file to edit. No shipping variants — internal and customer-facing content live in the same file.

**Files:**
- `CLAUDE.md` (real file, in git)
- `GEMINI.md` → symlink → `CLAUDE.md`

**Pros:** Zero machinery. Works today. Git tracks symlinks fine.
**Cons:** Doesn't solve shipping variants. Internal-only references (admin credentials, internal URLs, test fixtures) leak to customers. No customer customization story.

**Note:** An emerging convention is a universal `AGENTS.md` file that multiple agents read directly; if we adopt it, the symlink chain becomes `CLAUDE.md` → `AGENTS.md` ← `GEMINI.md`.

### Option B: Symlink + local overlay convention

Add a `CLAUDE.local.md` convention: customers append their own rules in a file `upgrade.php` never touches. The shipped `CLAUDE.md` ends with a line pointing the agent at it: "additional project rules in CLAUDE.local.md if present."

**Files:**
- `CLAUDE.md` (real, in git, shipped to customers as-is)
- `GEMINI.md` → symlink → `CLAUDE.md`
- `CLAUDE.local.md` (created by customer, gitignored, preserved on upgrade)

**Upgrade behavior:** `upgrade.php` skips `*.local.md` files.

**Pros:** Customers get a safe customization slot. Still trivial to maintain.
**Cons:** Same leak problem as Option A — internal-only content goes out in the shipped `CLAUDE.md`.

### Option C: Two hand-maintained variants + local overlay (recommended)

Maintain `CLAUDE.md` (internal) and `CLAUDE.shipped.md` (customer-facing) as separate files in git. `upgrade.php` deploys `CLAUDE.shipped.md` to the customer as their `CLAUDE.md`. Customers still get the `CLAUDE.local.md` overlay slot.

**Files:**
- `CLAUDE.md` (internal, in git, NOT deployed to customers)
- `CLAUDE.shipped.md` (in git, deployed by `upgrade.php` as the customer's `CLAUDE.md`)
- `GEMINI.md` → symlink → `CLAUDE.md` (internal side); generated copy on customer side
- `CLAUDE.local.md` (customer-side, upgrade-safe)

**Upgrade behavior:** `upgrade.php` copies `CLAUDE.shipped.md` → `CLAUDE.md` on the customer's deployment, overwriting it. `*.local.md` preserved.

**Pros:** Clean separation between internal and shipped. No build step. Accepts two files of duplication, manageable when the variants are genuinely different.
**Cons:** Common rules drift between the two variants over time without discipline. Adding a third agent (Aider, Cursor) means another symlink or copy per variant.

### Option D: Fragment assembly with profiles

Break agent instructions into topic fragments in `/docs/agent_instructions/` (routing.md, db_rules.md, formwriter.md, etc.). Profile manifests in `/profiles/` (e.g., `internal.yaml`, `customer.yaml`) declare which fragments compose each output. A build script (`utils/build_agent_files.php`) assembles them.

**Files:**
- `docs/agent_instructions/*.md` (fragments, in git)
- `profiles/*.yaml` (manifests, in git)
- `utils/build_agent_files.php` (generator)
- `CLAUDE.md`, `GEMINI.md`, etc. (generated; either committed or built on upgrade)
- `CLAUDE.local.md` (customer-side, upgrade-safe)

**Upgrade behavior:** `upgrade.php` runs the builder with the customer profile. `*.local.md` preserved.

**Pros:** Single source of truth for shared rules. N agents × M variants with no quadratic pain. Internal-only fragments stay out of customer profile by manifest exclusion.
**Cons:** Real machinery — builder script, manifest format, pre-commit hook or build-on-deploy. Generated outputs in git diff awkwardly. Overkill for 2 agents × 2 variants.

## Comparison

| | A: Symlink | B: + Overlay | C: Two variants + overlay | D: Fragments |
|---|---|---|---|---|
| Solves duplication | ✓ | ✓ | partial | ✓ |
| Ships customer variant | ✗ | ✗ | ✓ | ✓ |
| Customer customization survives upgrade | ✗ | ✓ | ✓ | ✓ |
| Scales to N agents × M variants | poorly | poorly | poorly | ✓ |
| Files added | 0 | 1 | 2 | ~5+ |
| Build step needed | no | no | no | yes |

## Recommendation

**Option C** for current scope (2 agents, 2 variants). It costs one extra file (`CLAUDE.shipped.md`) plus a small `upgrade.php` rule, and defers the fragment system until there's evidence — a third agent or a third variant — that justifies the build machinery.

The migration path is clean: if C becomes painful later, the fragments and profiles in D can be extracted from the existing pair of files rather than designed up front.

## Open questions

- Should `CLAUDE.shipped.md` live at the project root or under `/docs/`? Root keeps it visible next to its sibling; `/docs/` reduces top-level clutter.
- Does `upgrade.php` already have a "preserve user-modified files" mechanism we should reuse, or does this need new code?
- Audit `CLAUDE.shipped.md` content for leaks at every update: no admin credentials references, no internal hostnames, no references to memory files the customer won't have.
- Where does the customer's `CLAUDE.local.md` get bootstrapped from? An empty file? A documented template? Or do we leave it absent until the customer creates it?
