# Agent Release Channel: ship and update joinery-agent inside the platform release

**Status:** BUILT 2026-07-22. Gates 1, 3, 4 passed live on dev (Go suite 18 tests `-race`; bootstrap via install_agent.sh 0.2.0→0.3.0; unattended self-update 0.3.0→0.3.1; tampered v0.3.2 refused with `verify_failed` surfaced on the dashboard, recovered to `current` on manifest fix). Gate 2 passed in direct-invocation form (build+sign+idempotent-carry-forward proven; the publish_upgrade output lines ride the next real publish). **Gate 5 (fleet proof — next publish+apply on getjoinery brings its agent current with zero manual steps) is PENDING and is the condition for moving this spec to implemented/.**

Owner decision 2026-07-22: the agent ships inside the platform release (one channel, no separate agent release stream).

## Why

The job agent is the one piece of the platform the platform cannot update itself. Every other artifact rides publish → apply: the PHP tree, plugins, themes, maintenance scripts, the email stack, even the relay sealer (self-delivered by its provision job from the control plane). The agent binary is deployed by a human running `sudo install` on the box — which is how dev got 0.2.0 while getjoinery's control-plane agent sat on an older build.

That works while the owner operates every control plane. It breaks the moment customers run their own (customer-cloud sites with server_manager active): nobody can ssh in, and doctrine says nothing is manual unless it is a one-off. Agent updates are the opposite of a one-off.

## Decision record

- **One release channel.** The agent artifact is bundled in the platform release. Platform version N carries agent version X; there is no independent agent distribution stream. The agent keeps its own semver (`main.go` `version`) — the bundle manifest records which agent version a platform release carries.
- **The agent updates itself.** No sudo, no human, no control-plane push. The agent already runs as root (systemd unit and the cron keepalive both run it as root), so it has the rights to replace its own binary and restart. Self-swap also dissolves the two failure modes hit during manual deploys: ETXTBSY (rename-over, not copy-over) and the no-passwordless-sudo dance.
- **Signed artifacts, verified before install.** The agent must never execute a binary solely because it appeared in the site tree. The tree is writable by the web user; the agent is root. Without verification, a web-layer compromise becomes root on the control plane. The publisher signs the artifact; the agent verifies with a public key embedded at build time.

## Artifact layout

New directory shipped inside the server_manager plugin (so it rides the existing plugin archive and lands on every deployment via `upgrade.php`):

```
plugins/server_manager/agent_dist/
  manifest.json          # {"version": "0.3.0", "binaries": {"linux-amd64": {"file": ..., "sha256": ..., "signature": ...}, "linux-arm64": {...}}}
  joinery-agent-linux-amd64.gz
  joinery-agent-linux-arm64.gz
```

- Binaries are gzipped (a Go binary is ~10 MB; ~5 MB gzipped per arch). Both mainstream Linux arches are cross-compiled — Go makes this free.
- `signature` is an Ed25519 signature over the raw (decompressed) binary, made with the publisher's private key. The matching public key is compiled into the agent (`-ldflags -X`), same pattern as the version string.
- The private key lives in the control plane's `config/` directory (alongside `Globalvars_site.php`, outside the webroot, 0600). Generated once by the publish tooling if absent — zero-config principle: derive, never require.
- Key rotation = ship an agent build carrying the new public key while still signing with the old one, then switch signing keys the following release.

## Publish side (`publish_upgrade.php`)

A new step before archive assembly:

1. If the agent source tree is present on the publishing box (path from a setting, default `~/joinery-agent` — dev is the only publisher today), run the cross-compile: `GOOS=linux GOARCH={amd64,arm64} go build -ldflags "-X main.version=... -X main.updatePubKey=..."`.
2. Sign each binary, gzip, write `agent_dist/` + `manifest.json`.
3. If the agent source is NOT present (a control plane that publishes without the agent repo), the existing `agent_dist/` contents carry forward unchanged into the archive — publishing never breaks, the agent version simply doesn't advance.
4. Publish output lines state which agent version was bundled and whether it was rebuilt or carried forward. No silent staleness.

`build_installer.sh` remains for first-time manual installs and gains nothing; its generated installer keeps working. Long-term the plugin installer path below supersedes manual bootstrap.

## Agent side (self-update loop)

On every heartbeat tick (already periodic, already cheap):

1. Derive the site tree from `JOINERY_CONFIG` (the agent already locates `Globalvars_site.php`; `agent_dist/` is at a fixed path relative to it). Stat `manifest.json`; if the manifest version for this arch equals the running version, done.
2. Read the gzipped binary, decompress to a temp file **in the same directory as the installed binary** (same filesystem, so rename is atomic), verify sha256, then verify the Ed25519 signature against the embedded public key. Any mismatch: log loudly, mark the heartbeat row (see below), do NOT install, do NOT retry-spin (back off to hourly for that manifest mtime).
3. Copy the current binary to `joinery-agent.bak`, chmod 755 the new file, `rename()` it over the install path. The running process keeps its old inode — no ETXTBSY, no torn binary.
4. Finish the current job if one is executing (the update check runs only between jobs — never swap mid-job), then exit so the supervisor restarts into the new binary. Cron mode: keepalive restarts within a minute. Systemd: unit changes from `Restart=on-failure` to `Restart=always` so a clean exit restarts too (ships in `agent_dist` alongside the binary; the agent rewrites the unit + `daemon-reload` when it differs — it is root).
5. **Boot fallback:** if the agent fails fatal init (config load, DB connect, schema validation) and a `.bak` exists whose version differs, it restores the `.bak` over itself, logs, and exits — the supervisor restarts the previous, working version. A bad release degrades to the old agent, never to a dead control plane. On the first successful heartbeat after an update, the `.bak` is deleted (update confirmed good).

### Surfacing

- `ahb_agent_heartbeats` gains `ahb_bundled_version` (what the tree offers) — the agent writes both. The server_manager dashboard shows a "agent update pending/failed" badge when running ≠ bundled for longer than one heartbeat interval, and a hard alert when signature verification failed.
- All update actions log to the agent log with a `=== Self-update ===` header, mirroring the teardown log convention.

## Bootstrap (fresh control planes)

Self-update requires a running agent; first install is the remaining root moment. It uses the mechanism that already exists for exactly this: **the plugin installer**. `plugins/server_manager/_plugin_installers_start.sh` (run as root via the Run Plugin Installers platform action, and automatically by `install.sh`'s plugin-installer phase) installs the agent from `agent_dist/`: verify signature, install binary, write env file with `--config` derived from the site root it is invoked from, set up systemd or cron supervision (same auto-detection logic as the current installer — lift it, don't duplicate it). Idempotent: if the agent is present and current, it does nothing.

This closes the loop for customer-cloud births: provision → install → plugin installers → agent running → agent keeps itself current forever after.

## Integration inventory (all touchpoints, decided up front)

| Touchpoint | Change |
|---|---|
| `publish_upgrade.php` | build+sign+bundle step; carry-forward when source absent |
| `plugins/server_manager/agent_dist/` | new shipped artifact dir |
| agent `main.go`/new `update.go` | self-update loop, boot fallback, embedded pubkey |
| `install/joinery-agent.service` | `Restart=always`; shipped in agent_dist, converged by agent |
| `ahb_agent_heartbeats` | `ahb_bundled_version` column (data class field spec) |
| server_manager dashboard | update-pending / verify-failed badge |
| `_plugin_installers_start.sh` | root bootstrap from agent_dist |
| `build_installer.sh` | unchanged (manual fallback); README notes the shipped channel is primary |
| `settings.json` (plugin) | `server_manager_agent_source_path` (publisher-only, default `~/joinery-agent`) |

## Acceptance gates

1. **Unit (Go):** update check state machine with a fake filesystem/manifest — version match no-op, good update, bad sha256, bad signature, boot-fallback restore. Rides the existing `runner_test.go` harness style, `-race`.
2. **Publish gate:** publish on dev with agent source present → archive contains `agent_dist/` with manifest version = agent repo version; publish with source path unset → prior artifact carried forward, output says so.
3. **Live self-update gate (dev):** install a deliberately older signed build, publish, apply on dev, watch the agent swap and heartbeat the new version with no human step. Verify `.bak` cleanup after first healthy heartbeat.
4. **Rejection gate:** plant a binary with a valid sha256 but no valid signature; agent refuses, logs, badge appears, running version unchanged.
5. **Fleet proof:** next real publish+apply on getjoinery brings its agent to current with zero manual steps — this is the bug that motivated the spec; it must be the closing gate.

## Documentation

Update `plugins/server_manager/docs/overview.md` (agent section): the release channel, self-update behavior, signature verification, bootstrap via plugin installer, and the dashboard badge — written as current state per docs rules.

## Out of scope

- Windows/macOS agents (no such control planes exist).
- Independent agent release cadence or a downloadable agent page — the platform release IS the channel.
- Signing any artifact other than the agent binary (the PHP tree's integrity story is unchanged by this spec).
