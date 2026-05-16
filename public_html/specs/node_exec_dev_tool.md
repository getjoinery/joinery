# Spec: Server Manager Node Exec CLI

## Problem

When Claude investigates a production issue, it currently requires 4–5 discovery steps before any useful command can run:

1. Check memory/notes for SSH details (often stale)
2. Query `mgn_managed_nodes` for host, key, port, container name
3. SSH into the host
4. Discover whether the site runs bare-metal or Docker
5. Run `docker exec <container>` to reach the site

All the required data already lives in Server Manager. This tool exposes it as a single call.

## Intended User

**Claude** — not a human operator. This tool is designed to be invoked via a single `Bash` tool call during production investigations. The goal is to collapse the 4–5 step discovery process into one call per diagnostic command.

A memory entry will point Claude to this tool so it is the first thing reached for when investigating any managed node.

## Typical Use Case

User reports: *"ScrollDaddy prod failed an upgrade — something about a missing plugin or theme."*

**Without this tool (today):**
- Read memory for SSH details → wrong, those were DNS servers
- Query DB for node → get host, key, container name
- SSH into host → discover it's Docker
- Re-run everything via `docker exec`
- Finally run diagnostic commands

**With this tool:**

```bash
# Step 1 — list nodes to find the right slug
php plugins/server_manager/node_exec.php

# scrolldaddy     23.239.11.53    container: scrolldaddy
# getjoinery      45.33.12.88     (bare metal)
# ...

# Step 2 — check the active theme and version
php plugins/server_manager/node_exec.php scrolldaddy \
  "psql -U postgres scrolldaddy -t -c \"SELECT stg_name, stg_value FROM stg_settings WHERE stg_name IN ('theme_template','system_version');\""

# Step 3 — check the theme manifest on prod
php plugins/server_manager/node_exec.php scrolldaddy \
  "cat /var/www/html/scrolldaddy/public_html/theme/scrolldaddy/theme.json"

# Step 4 — check the error log
php plugins/server_manager/node_exec.php scrolldaddy \
  "tail -50 /var/www/html/scrolldaddy/logs/error.log"
```

Each step is one `Bash` tool call with no prior discovery needed.

## Location

`plugins/server_manager/node_exec.php` — a CLI entry point inside the Server Manager plugin. Reuses `JobCommandBuilder::ssh_prefix()` and the existing node model; no duplicate connection logic.

## Behavior

**No-args mode:** List all active nodes — slug, host, container name (if any). This is Claude's entry point at the start of any investigation.

**Node lookup:** Match by `mgn_slug` or `mgn_name` (case-insensitive). Error and exit 1 if not found or disabled.

**No-command mode:** Print the resolved exec prefix for interactive/scripting use:
```bash
php plugins/server_manager/node_exec.php scrolldaddy
# ssh -i /home/user1/.ssh/id_ed25519_claude ... root@23.239.11.53 "docker exec scrolldaddy"
```

**Command mode:** Run the command on the node and stream output.
- If `mgn_container_name` is set → wraps command in `docker exec <container>`
- If not → runs directly over SSH

**Connection details** (from `JobCommandBuilder::ssh_prefix()`):
- Host: `mgn_host`
- User: `mgn_ssh_user` (default `root`)
- Key: `mgn_ssh_key_path`
- Port: `mgn_ssh_port` (default 22)
- Flags: `-o StrictHostKeyChecking=no -o ConnectTimeout=10 -o BatchMode=yes`

**Exit code:** Pass through the remote command's exit code so the script is scriptable.

**Output:** Clean and undecorated — no progress headers or spinners. Claude reads this directly.

## Memory Entry (to be written after build)

A memory entry will tell Claude: *"To run commands on any managed node, use `php plugins/server_manager/node_exec.php`. No args lists nodes. Pass a slug and a quoted command to execute. Handles SSH and Docker transparently."*

## Open Questions

1. **Timeout:** `ConnectTimeout=10` from `ssh_prefix()` is fine for quick checks. Should long-running commands (log tails, upgrade runs) accept a `--timeout N` flag?
2. **Non-Joinery nodes:** DNS servers have no container and no Joinery install. The tool should still work for them as bare SSH — confirm this is desired.
