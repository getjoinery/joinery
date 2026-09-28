# database_report: the Database as the Agent Sees It

**Status:** Proposed 2026-09-28. Owner agreed the word in principle; nothing built.
**Date:** 2026-09-28

## Why

On 2026-09-28 jeremytunnell's mail page took ~2 s on the server. Finding out why
needed an SSH login to read what only the database knows: that the messages
table had never been vacuumed or analyzed since its statistics were last
cleared, that 44% of its pages were not marked all-visible (so an index-only
count read the table), and that parallel query workers were slowing a 1-CPU
box. None of it was reachable through the agent. CLAUDE.md asks for a word
wherever SSH was the only way in; this is that word.

`host_report` describes the machine. This describes the database on it, and
sits beside it: `database_report`, an **observe** primitive, stored in its own
column and shown in its own card on the node page.

## What it returns

All counts and times; never a row, a value, a statement or a user name.

- **Database:** name, size, PostgreSQL version, when the server last started
  (`pg_postmaster_start_time`), `stats_reset`, `shared_buffers`,
  `effective_cache_size`, `max_parallel_workers_per_gather`, the CPUs the tuner
  would count.
- **Tables:** the 20 largest by total size. For each: total and heap size,
  estimated rows, share of pages all-visible, dead rows, rows changed since
  last analyzed, last vacuum and last analyze (manual or auto, whichever is
  later), and what `DatabaseUpkeep::plan()` would do to it today and why.
- **Maintenance:** the Database maintenance task's last run time, status and
  message, and whether it is active.
- **Counts:** tables never analyzed, tables with no vacuum on record.

## How it reads

The agent runs a script from the site tree, verified against the release
manifest like `host_report.sh`, with no argv and no stdin, as the web user so
it connects the way the site does (no password crosses the agent).
`utils/database_report.php` builds the object from `DatabaseUpkeep::tableStats()`
and `plan()`, so the report and the nightly task can never disagree about what
is stale. Read only: the script runs nothing but `SELECT` and `SHOW`, pinned
by a gate like the other observe words' gates.

## Work packages

1. **WP1 (platform):** `utils/database_report.php` and its gate (object shape,
   caps, read-only pin, table names reduced to a safe character set).
2. **WP2 (agent):** the `database_report` observe word — run the script as the
   web user, cap the output, redact as for `host_report`. Agent release.
3. **WP3 (plane):** `JobCommandBuilder::build_database_report`,
   `JobResultProcessor::process_database_report` (sanitise on intake, store in
   `mgn_last_database_report`), a Database card on the node Overview, a
   Database Report button, and a place on the uptime pass's status cadence.
4. **WP4 (paper):** server_manager overview doc rows; the vocabulary table.

## Open questions

- **Q1:** a recipe on top (check: a large table stale for more than a day
  despite the nightly task; repair: run the task now)? That needs a way for
  the agent to run one named scheduled task, which is a new operate word.
  Recommendation: not until the report shows the task falling behind anywhere.
