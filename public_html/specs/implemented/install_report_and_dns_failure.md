# Install report, and a DNS failure that says so

**Status:** implemented 2026-09-08

## The problem

A Linode StackScript deploy onto a fresh instance "did not work". The site was
serving, and the reason it did not load at its name was one line in
`/var/log/stackscript.log`: the DNS zone for the domain lived in a Linode
account the supplied token could not see, so creating the zone was refused and
the A record was never written. Two things were wrong with how that surfaced.

1. **A failure was reported as a wait.** The installer's closing summary said
   "Nothing further is needed. Point the domain at this server whenever you are
   ready." It had just learned, one step earlier, that waiting would change
   nothing.
2. **The log was reachable only from a shell on the box.** The plane could ask
   the node for its disk and memory but not for the one document that says
   whether the install finished and why DNS or the certificate did not.

## What changed

### The DNS step's outcome is a fact the rest of the install reads

`linode_stackscript.sh` (1.6) records how its DNS step ended in one variable,
`DNS_OUTCOME`: `skipped: …`, `written: …`, or `failed: …`. **failed** is reserved
for a refusal that waiting will not change: the zone held by another account or
a restricted user, a token without the Domains scope, a refused record write.
It hands the value to `install.sh` as `JOINERY_DNS_OUTCOME`, and its own closing
block prints `DNS setup failed: <reason>` on a failure.

`install.sh` (2.67) reads that variable in its closing summary. A failure prints
`DNS setup failed: <reason>` in red, says nothing changes on its own, names
where the record has to be changed, and appends the same line to the site's
`logs/error.log` as `[INSTALL] DNS setup failed: …`. A genuine wait keeps the
"whenever you are ready" wording. An install driven any other way leaves the
variable unset and sees no change.

### The node reports its own install: `install_report`

A new observe primitive in the agent (1.23.0), `install_report`, reads a
compiled-in list of the logs a first-boot install leaves
(`/var/log/stackscript.log`, `/var/log/cloud-init-output.log`) and the
certificate retry directory, and answers:

- whether the install finished, failed, or is still running, and when it started
- how the DNS step ended (`written`, `failed` with the reason, `skipped`,
  `not_attempted`), and how the certificate step ended (`issued`, `deferred`)
- whether the certificate retry timer is armed, and for which domains
- warning and error lines (bounded), and the tail of the log (24 KiB, ANSI
  stripped, cut on a line)

It takes no parameters. A path parameter would make it "read this file for me",
which is a disclosure primitive whatever its name. The plane offers it as an
**Install Report** button beside Check Status on the node's Overview tab, for a
node whose agent reports the primitive; the job page shows the verdicts and the
tail through the ordinary transcript.

### The markers are a contract

The verdicts are read off lines the installer prints on purpose:
`=== Joinery first-boot install: `, `=== Joinery is installed ===`,
`Install stopped. Nothing further will run.`, `DNS setup failed: `,
`A record created/updated: `, `Skipping DNS creation`,
`Issued LE certificate for `, `No SSL certificate was issued`, plus the two
ways the DNS step reported a refusal before it printed the failed marker
itself (`Linode returned HTTP …`, `Could not determine this instance's public
IP`), so every log already on disk reads correctly.
`tests/unit/installer_contract_test.php` pins the scripts to them and, where the
agent source is on the box, checks every marker the reader declares is one a
script prints. `observe_install_report_test.go` pins the reader.

## What it does not cover

An install that dies before the agent is installed, or a first-boot install on a
machine nobody has paired yet, has no agent to ask. That log is reachable only
from the console. The primitive answers for an install that reached the agent:
the quiet failure, where a site serves and something inside the install went
wrong, for as long as the log stays on disk, however long after the fact the
node is paired.

## Files

- `maintenance_scripts/install_tools/linode_stackscript.sh` 1.6
- `maintenance_scripts/install_tools/install.sh` 2.67
- `joinery-agent/primitives/observe_install_report.go` (+ test), `gate_test.go`, `main.go` 1.23.0
- `plugins/server_manager/includes/JobCommandBuilder.php` 1.47
- `plugins/server_manager/logic/node_detail_actions_logic.php`
- `plugins/server_manager/includes/node_detail_tabs/overview.php`
- `plugins/server_manager/docs/overview.md`
- `tests/unit/installer_contract_test.php`

## Verification

- Agent: `go test -race ./...` green, including the vocabulary pin.
- Plane: `php tests/run.php --changed` green (11 suites); parity, builder,
  CSRF and artifact-channel suites pass directly.
- The reader run against the real log of the 2026-09-08 deploy on
  173.255.233.234 (written by the 1.5 script, before the failed marker) reports
  install finished, DNS failed with the HTTP 400 zone line, certificate
  deferred.
- Live gate still open: an agent release carrying 1.23.0 and a platform release
  carrying the scripts, then Install Report on a paired node.
