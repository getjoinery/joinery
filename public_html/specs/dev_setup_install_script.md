# Dev Environment Setup Install Script

**Status: stub — captured for later expansion.**

## Problem

The production install path (`maintenance_scripts/install_tools/install.sh` and related) produces a production-ready deployment. Developers cloning the repo to work on the platform need additional setup beyond that — test data, dev settings, agent files, validators — and currently perform those steps manually or ad hoc.

## Goal

A scripted, idempotent dev-environment setup pass that converts a baseline install into a developer-ready environment, separate from the production install path.

## Likely contents (not yet designed)

- Set debug-mode and dev-only settings in `stg_settings`.
- Seed test data: test users at various permission levels, sample products, fixture content.
- Seed default agent files into `stg_agent_files` (forward reference: `agent_files_management.md`).
- Configure or document local DNS / hosts / SSL setup for development domains.
- Install or check dev-only tooling (PHP file validator, linters, anything else assumed by the dev workflow).

## Non-goals

- Changing the production install path or its defaults.
- Replacing manual workflows for actions that should stay manual (commits, deploys, schema changes).

## Open questions

- Location: `maintenance_scripts/install_tools/dev_setup.sh`? A PHP entry point under `utils/`? Both?
- Idempotency: re-running should be safe, with each step skip-if-already-applied.
- Prompted vs. defaulted: do we always seed test data, or prompt? Do we always seed agent files, or prompt?
- Single script vs. modular invocation (e.g., `dev_setup --seed-test-data`, `dev_setup --seed-agent-files`)?
- What does the script assume about the baseline state? Fresh install only, or runnable against any existing dev environment?

## Out of scope (forward references)

- Production install behavior — addressed by the existing install scripts.
- Agent file design and admin management — addressed in `agent_files_management.md`.
