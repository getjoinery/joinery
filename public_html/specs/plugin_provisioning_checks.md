# Plugin Provisioning Checks

## Problem

A plugin can be installed and activated through the admin UI while a resource it
depends on is missing or misconfigured. The Email Forwarding plugin is the
motivating case: it can be activated with no inbound mail server on the box and
no working outbound relay, so it silently fails and the admin has no signal as
to why.

The database side of plugin setup is already handled — `update_database` runs
plugin migrations and syncs schema. What is missing is an equivalent for the
**external resources a plugin needs at runtime**: mail servers, relays,
services, extensions, APIs.

## Goal

Give plugins a way to verify their runtime dependencies on demand and surface,
in the admin UI, which ones are not working — and where appropriate, the command
that fixes them.

It differs from the migration system in one deliberate way: the system never
executes a fix itself. It only detects and reports.

## Core Idea — two complementary check types

No single mechanism covers every dependency, so the system provides two, and a
plugin picks whichever fits each dependency.

**Code check — the plugin's own acquisition routine.** PHP already knows whether
a resource works, because PHP uses it: when the plugin's code opens the SMTP
connection or loads the extension, it either succeeds or throws. So the check is
not a proxy — it *is* the plugin's resource-acquisition code, invoked on demand,
with its failure caught. This is accurate (it exercises the exact code path the
feature uses) and it ignores the container/host boundary entirely, because it
asks "can our code acquire this," which PHP can always answer by trying. Its
limit: it only sees resources PHP actively reaches *out* to acquire.

**Probe check — a port reachability test.** Some dependencies push *into* PHP
rather than being acquired by it — inbound Postfix pipes mail into the forwarder
script; PHP never connects to it. A code check is structurally blind to those. A
probe — open a TCP connection to a host/port — can see them: "something is
listening on :25" is a real signal that the inbound mail server exists. Its
limit: it is coarse. It proves something is accepting connections, not that it
is the right software or correctly configured.

Used together they cover both directions of dependency — what PHP calls out to,
and what calls into PHP. Neither persists anything; both are evaluated live.

## Non-Goals

- No automatic execution of fix scripts. Installing packages and editing `/etc/`
  needs root; the Apache/PHP process does not have it and must not.
- No persisted provisioning state. Checks are evaluated live every run.
- No host introspection and no agent in this spec — but see Extensibility; the
  design leaves a clean seam for an `agent` check type later.
- Unrelated to `automated_hosting_provisioning_setup.md`, which provisions whole
  customer sites from orders.

## Design

### Declaration — `provisioners` in `plugin.json`

A plugin declares its runtime dependencies as a `provisioners` array, alongside
`settings` and `adminMenu`. Each entry's `check` is an object whose `type`
selects the check kind.

```json
"provisioners": [
  {
    "key": "inbound_mail_server",
    "label": "Inbound mail server (Postfix) running",
    "details": "Postfix on the host receives inbound mail and pipes it to the forwarder.",
    "check": { "type": "probe", "probe": "tcp", "host": "host-gateway", "port": 25 },
    "script": "scripts/install_email.sh"
  },
  {
    "key": "outbound_forwarding_relay",
    "label": "Outbound mail relay for forwarding",
    "details": "Forwarded messages are relayed out through this SMTP server.",
    "check": { "type": "code", "call": "EmailForwardingHealth::checkForwardingRelay" }
  }
]
```

| Field | Required | Purpose |
|---|---|---|
| `key` | yes | Stable identifier, unique within the plugin. |
| `label` | yes | Human-readable name shown in the admin UI. |
| `details` | no | One-line explanation shown under the label. |
| `check` | yes | A check object; `type` is `code` or `probe`. |
| `script` | no | Path to a fix script, relative to the plugin root. Present only when the fix is a host-level install; omitted when the failure is a configuration problem. |

### Check type: `code`

```json
"check": { "type": "code", "call": "EmailForwardingHealth::checkForwardingRelay" }
```

`call` names a static method (`Class::method`) on a class loadable in the
plugin's normal context — by convention a `*Health` class in the plugin's
`includes/`. The method **performs the plugin's real resource-acquisition step**,
or calls the shared routine the feature itself uses. The checker invokes it
inside `try`/`catch`:

- **Returns normally** → `verified` — the real acquisition path ran and
  succeeded.
- **Throws `ProvisioningCheckFailed`** → `unmet`; the exception message becomes
  the human-readable reason. This is the *expected* failure signal — the check
  method catches the underlying acquisition exception (a `PDOException`, an SMTP
  error) and rethrows it as `ProvisioningCheckFailed` with a clean message.
- **Throws any other `Throwable`, or class/method not loadable** → `error`. An
  unexpected exception type means the check method is itself faulty, not that
  the dependency is missing — so it is reported as a broken check, never as a
  false `unmet`.

Requirements on a check method: **no side effects** (open and close a
connection, never send a real message or write data — it verifies *acquisition*,
not *use*); **time-bounded** (it must set its own connection/socket timeouts —
the system runs it inside a request and cannot forcibly interrupt blocked PHP
I/O); **idempotent and cheap** (run every time the Plugins page opens). Because
the check is the same code the feature uses, plugin authors should factor each
dependency into one acquisition routine called from both places so the two
cannot diverge.

### Check type: `probe`

```json
"check": { "type": "probe", "probe": "tcp", "host": "host-gateway", "port": 25 }
```

The checker opens a connection to `host`/`port` within a system-enforced timeout
(5s default — unlike a `code` check, the system owns the socket and can bound
it):

- **Connection succeeds** → `reachable` — something is listening, but
  correctness is not confirmed.
- **Connection refused or times out** → `unmet`.
- **Declaration invalid** (unresolvable host token, bad port) → `error`.

`probe` is `tcp` in v1; `http`/`dns` are natural later additions. A probe proves
reachability only — it is the coarse half of the toolset by design, which is why
its passing result is `reachable`, a deliberately weaker state than a code
check's `verified` (see Result states).

### Resolving "the host" for probes

A probe's `host` may be a literal IP/hostname, or the token `host-gateway`,
which the detector resolves to:

- **In a container** (detected via `/.dockerenv`): the Docker bridge gateway,
  derived from the container's default route — this reaches services on the
  host.
- **On bare metal**: `127.0.0.1`.

The token keeps the common "reach a service on my host" case portable across
both deployment layouts.

### Detection

`PluginProvisioning` (in `includes/`) provides:

- `getProvisioners()` — reads the `provisioners` array from every **active**
  plugin's `plugin.json`.
- `runChecks()` — dispatches each `check` by `type` and returns a result per
  provisioner.

Checks are evaluated live; nothing is persisted and there is no database table.

Detection never runs on the page render path. The Plugins page renders
immediately; an AJAX request then triggers the checks and the page updates in
place. A slow check delays only its own badge, never the page.

The endpoint lives at `ajax/check_provisioning.php`, permission-gated to level 5
(matching the Plugins page). It runs `PluginProvisioning::runChecks()` for all
active plugins and returns JSON keyed by plugin and provisioner `key`.

### Result states

| State | Meaning |
|---|---|
| `verified` | A `code` check ran the real acquisition path and it succeeded — the strongest pass. |
| `reachable` | A `probe` connected to the port. Something is listening, but the system has *not* confirmed it is the right software or correctly configured — a deliberately weaker pass. |
| `unmet` | The check method threw `ProvisioningCheckFailed`, or the port was unreachable. The reason is shown, plus the fix command if a `script` is declared. |
| `error` | The check is faulty: class/method not loadable, invalid probe target, or a code check threw an unexpected `Throwable`. Reported as a broken check, distinct from a missing dependency. |

`verified` and `reachable` both mean "the check passed," but they are kept
distinct so the UI never implies more certainty than the check earned. A probe
can only ever yield `reachable`; a code check can only ever yield `verified`.

### Surfacing — admin Plugins page (async)

On `/admin/admin_plugins`, each active plugin with declared provisioners gets a
setup indicator. The page renders with the indicator in a neutral **Checking…**
state and fires the `ajax/check_provisioning.php` request; each badge resolves in
place when the response arrives:

- All checks `verified` → green **Setup complete** badge (or no badge).
- All checks pass but at least one is only `reachable` → teal **Reachable —
  not fully verified** badge. The system confirmed every dependency is present;
  it could not confirm the probe-checked ones are correctly configured.
- One or more `unmet` → amber **Needs setup** badge.
- Any `error` → red **Check failed** badge.
- AJAX request itself fails → grey **Cannot check** badge.

The teal state is the deliberate guard against false confidence: a plugin whose
green status rests on probes never shows the unqualified "Setup complete", so an
admin is never told a probe-only result is fully verified.

In the expanded panel, each passing provisioner is also labelled by strength —
`verified` provisioners read "Verified", `reachable` ones read "Reachable
(listening, not verified)" — so the distinction is visible per dependency, not
just in the rolled-up badge.

Expanding the badge lists each `unmet` provisioner with its `label`, `details`,
the reason (caught exception message, or "connection refused"), and — if a
`script` is declared — the fix command using the **absolute** script path
(plugin root resolved via `PathHelper`):

```
⚠ Needs setup

  Inbound mail server (Postfix) running
  Postfix on the host receives inbound mail and pipes it to the forwarder.
  Reason:  Connection refused (172.17.0.1:25)
  Run:     sudo bash /var/www/html/SITENAME/public_html/plugins/email_forwarding/scripts/install_email.sh
```

When several `unmet` provisioners share a `script`, the command shows once. When
a provisioner has no `script`, only the reason is shown — the admin fixes the
configuration the message points at.

### CLI

`utils/check_provisioning.php` runs the same detection and prints `unmet`
provisioners with their reasons and commands — useful right after a deploy, and
lets `install.sh` / `upgrade.php` optionally echo a "plugins need setup" summary.

## First Consumer — Email Forwarding

Email Forwarding declares two provisioners, one of each type — together they
cover both directions of its mail flow:

- **`inbound_mail_server`** (`probe`) — a TCP probe of `host-gateway:25`. If no
  mail server is listening, inbound mail cannot arrive at all. Offers
  `scripts/install_email.sh` (an idempotent host installer) as the fix.
- **`outbound_forwarding_relay`** (`code`) — `EmailForwardingHealth::checkForwardingRelay()`
  calls the same routine the forwarder uses to acquire its outbound SMTP relay:
  it connects and authenticates, then closes. A down relay or bad credentials
  makes the SMTP library throw; the method catches that and rethrows it as
  `ProvisioningCheckFailed` with a clean message, which is reported as `unmet`.
  No `script` — a relay failure is a configuration problem.

Bump the plugin `version` in `plugin.json` when the `provisioners` block is
added.

## Extensibility

`runChecks()` dispatches by `check.type` with a plain `match` — for v1's two
types that is all that is warranted, and no registration machinery should be
built. Adding a type is a new `match` arm plus a handler method, with the
declaration format, AJAX endpoint, and UI unchanged. The anticipated future
addition is an **`agent`** type that delegates to a host-side helper able to run
true host introspection (`dpkg -s`, `systemctl is-active`) — the rung that would
let the system check things neither a code check nor a probe can see. It is out
of scope here. If it lands and the dispatch grows enough to earn it, formalising
the `match` into a registry is a cheap, local refactor at that point — not
something to pre-build now.

## Limitations

1. **Code check: acquisition, not use.** It confirms the SMTP relay connects and
   authenticates; it does not confirm a specific message is accepted and
   delivered. Input-dependent or side-effecting failures are not caught.
2. **Probe check: coarse.** It proves something is accepting connections on a
   port, not that it is the right software or correctly configured — a probe of
   `:25` cannot tell that Postfix's `master.cf` pipes to the forwarder. This is
   not hidden: a passing probe yields `reachable`, never `verified`, and the UI
   surfaces it as such — but it remains a real gap in what can be confirmed.
3. **Liveness only.** A passing check means "working right now," not "will stay
   working." There is no history and no trend.
4. **Code-check timeouts are self-imposed.** The system can bound a probe's
   socket but cannot forcibly interrupt a blocked PHP I/O call inside a code
   check; a check method that fails to set its own timeout can hang its badge.
   v1 accepts this: the time-bound rule is a documented convention backed by a
   prominent warning in the developer docs, and the async UI keeps the blast
   radius to the single offending badge. Running each code check in a
   subprocess with a hard wall-clock kill — so a runaway check is reported as
   `error` instead of hanging — is a deliberate later hardening step, taken
   only if a misbehaving check becomes a real problem in practice.
5. **Code-check correctness is the plugin's responsibility.** A bug in a check
   method no longer masquerades as a failed dependency — only
   `ProvisioningCheckFailed` maps to `unmet`, and any other `Throwable` maps to
   `error`. The residual burden: the plugin author must remember to catch the
   real acquisition exception and rethrow it as `ProvisioningCheckFailed`. If
   they forget, a genuine dependency failure is misreported as `error` (a
   broken check) rather than `unmet` — visible and wrong-in-a-safe-direction,
   but still wrong.

## Security

- The system **detects and reports only**. It never runs a fix script.
- A `code` check is a method call into already-loaded plugin code; a `probe`
  opens a socket. Neither shells out, and neither adds a trust boundary an
  active plugin did not already have. Probe targets come verbatim from
  `plugin.json`, never from request input.
- Fix scripts are committed under each plugin's `scripts/` directory and reviewed
  like any other code.
- The Plugins page and the AJAX endpoint are permission-gated to level 5+.

## Documentation

On implementation, add a **"Declaring host provisioners"** section to
`docs/plugin_developer_guide.md` covering the `provisioners` schema, both check
types and when to use each, the code-check contract (no side effects,
time-bound, factor the shared acquisition routine, rethrow acquisition failures
as `ProvisioningCheckFailed`), and the `host-gateway` token. The contract section must carry a **prominent warning** that a code
check which fails to set a short connection timeout will hang its own badge —
since the system cannot interrupt blocked PHP I/O, this convention is the only
thing protecting against a stuck check in v1. Add a note to the Email Forwarding overview
(`plugins/email_forwarding/docs/overview.md`) that activation now reports a
missing inbound server or non-working relay on the Plugins page. Developer docs
live in `/docs/`, not only in this spec.

## Implementation Checklist

1. `includes/PluginProvisioning.php` — declaration reader; `runChecks()`
   dispatching `check.type` via a plain `match`; `code` and `probe` handlers;
   `host-gateway` resolution.
   `includes/ProvisioningCheckFailed.php` — the typed exception a code check
   throws to signal an expected dependency failure.
2. `ajax/check_provisioning.php` — permission-5 JSON endpoint.
3. `utils/check_provisioning.php` — CLI detector.
4. `/adm/admin_plugins.php` (+ logic file, + JS) — neutral badges, fetch from the
   AJAX endpoint, resolve each badge in place, unmet-provisioner panel showing
   the reason and (if present) the fix command.
5. Email Forwarding: add `includes/EmailForwardingHealth.php`, add the two
   `provisioners` to `plugin.json` (bump `version`), ensure the outbound relay
   acquisition is a single shared routine called by both the forwarder and the
   check.
6. Docs: new section in `docs/plugin_developer_guide.md`; note in the Email
   Forwarding overview.
