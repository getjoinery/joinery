# Plugin tiers: native and sandboxed

**Status:** Proposed by the owner 2026-09-09, design discussion continued
2026-10-05. Design questions decided 2026-10-05 (see Open questions; only
Q5, billing, is left open). Reviewed adversarially 2026-10-14: the inventory,
limits, secrets, outbound guard and WP1/WP2/WP9 are corrected below, and
Q11 (install and signing), Q12 (where the host runs), Q13 (compatibility)
and Q14 (UI calls wait, bounded) are decided. Only Q5 remains open. Built
in small committed pieces on main (owner, 2026-10-14). WP1 built 2026-10-14
(webhook receiver kind only; the plugin host kind waits for WP7); WP2 to WP9
not started.
The security consequence is tracked in `security_inventory.md`; this spec is
the design, the inventory of what already exists, the build list and the
workflow.

**This is the one spec for the sandboxed plugin tier.** It also carries the
outgoing-webhooks work that `signal_bus` unblocked and never built, because
the tier needs the same delivery piece (see WP1).

## The idea

Two kinds of plugin, told apart by what the package contains, not a
judgement.

**Native.** PHP, running in the php-fpm pool with full reach, exactly as
plugins do today. One signed by the release key on the management node
(built from our repository by our publisher) installs with no prompt and is
served on the marketplace with a native badge. An unsigned one installs only
through the existing unsigned-package warning, and never on a host that
refuses unsigned packages. A partner's plugin we like is not signed by us,
and there is no countersigning of anyone else's PHP.

**Sandboxed.** A WebAssembly module that reaches the platform only through
the API, under permissions its manifest declares and the install screen
shows. It needs no signature and carries no warning: the sandbox, not the
author, is what keeps it safe. The 1.0 marketplace is native only; the
sandboxed tier opens when the first outside author needs it.

## Why two tiers rather than one

One tier means either every plugin, ours included, is rehomed against the
API (twelve plugins, ~170k lines, and a weaker plugin model for the product we
sell), or third-party code runs in the process that holds the vault keys. The
threat model (`security_inventory.md` § The threat model after all of it)
already accepts our own signed code in the pool as residual 3 and 4; a
stranger's code there is not on the accepted list. Two tiers put the line
exactly where the trust already is.

PHP's name-based class loading has no capability gate, so there is no
in-process middle tier: code is either trusted and in the pool, or outside
the pool and talking through the API. Existing plugins are never sandboxed
in place.

## What a sandboxed plugin looks like

One `.wasm` file plus a manifest. Nothing else ships.

Worked example, "post new registrations to Slack":

- **Permissions:** read event registrations.
- **Signals:** `event.registered`.
- **Settings:** one secret field, the Slack webhook URL.
- **Outbound hosts:** `hooks.slack.com`.
- **Code:** one function that receives the signal payload, asks the API for
  the registration, and asks the host to POST a message.

The install screen shows exactly those four declarations in plain words. The
module has no way to do anything not on that screen.

## One install process for both kinds (Q11, decided 2026-10-14)

A sandboxed plugin installs the way a native one does: from the marketplace,
or as a ZIP uploaded on the Plugins page (`PackageInstallPage`), with the
same replace-on-upload flow. It gets no unsigned warning, and a host that
refuses unsigned packages (`unsigned_packages=refuse`) accepts it, as it
accepts style themes today. What the admin sees instead is the permission
list. That is consent, not a warning: it is exactly what the host will let
the module do.

Sandboxed plugins are not signed. The marketplace is ours and decides what
it carries. A per-module marketplace signature would protect nothing the
sandbox does not already enforce, and would add a signing key that could be
stolen or misused; on the native trust list such a key could approve PHP.

**The kind is decided by the package's contents, never by its own claim.** A
package is sandboxed only when it holds exactly one `.wasm` file and one
manifest. Anything else, one PHP file among them included, is native and
goes through signature checking and, unsigned, the warning. A replacing
upload is judged the same way, so a sandboxed plugin cannot be updated into
PHP without the warning. The native installer and its key list are
unchanged.

Because a sandboxed plugin's folder can only ever hold those two files, it
sits in `plugins/` beside native ones. Native plugin loading recognises a
sandboxed plugin and loads nothing from its folder, and the daily check that
a site runs only the code we shipped treats it as expected rather than as
unshipped code.

## The sandboxed tier

### The host lives outside the pool

Not a wasm runtime loaded into php-fpm through FFI: a runtime bug there is a
pool compromise, which is the thing the tier exists to prevent. A separate
plugin-host process under its own uid, the same shape as the unseal daemon,
runs the modules and reaches core over `/api/v1` with a per-plugin scoped
credential. A Go host on wazero (pure Go, no C beneath it), using Extism's
Go library to load and run modules (Q6), matches the binaries the fleet
already ships (agent, sealer) and their release channel. One host per node
(Q4).

**On a shared host, the host runs inside each site container (Q12, decided
2026-10-14).** Only a site with a sandboxed plugin installed gets one. It
runs under its own uid, supervised the way `install_agent.sh` supervises the
agent: a systemd unit where PID 1 is systemd, a cron keepalive otherwise. It
reaches core on the container's loopback and core reaches it on a local
socket, so no container wall changes, and the container's CPU and memory
limits cap it with everything else on the site. A runner bug or a module
breaking out reaches only the site that installed it. Modules run in
wazero's interpreter, not its compiler: the interpreter is Go stepping
through the module's instructions, so a bug in it is far less likely to
become an escape than a bug in generated machine code, and it leaves the
host free to run with `MemoryDenyWriteExecute`. It is several times slower,
which an integration waiting on the network does not notice; the compiler is
revisited only if a real plugin proves too slow.

**Modules are stateless (decided 2026-10-05).** The host makes a fresh instance for
each signal, scheduled run or UI request and throws it away afterwards.
Nothing lives in module memory between calls; anything a plugin wants to
remember goes through the API into its own data. Every call runs under a
memory cap and a wall-clock timeout, and the host caps how many calls run at
once. There is no separate CPU budget: wazero can stop a call at a deadline
but cannot count its work, so CPU is bounded by the timeout, the concurrency
cap and the CPU share of the process the host runs in. Every host function
behind a door takes the call's deadline and returns when it passes, so a
module waiting on a door (an outbound call, say) is stopped as surely as one
spinning on its own. A misbehaving module is killed by ending one call, with
no effect on any other plugin.

### The doors

A module can do these things and nothing else.

1. **API calls** under the permissions the manifest declares. The credential
   is minted at install for exactly those permissions and revoked at
   uninstall. Permissions are the API's existing authorization surface, named
   in the manifest the way `plugin.json` names settings today.
2. **Signals.** Core delivers the signals the manifest subscribes to (a
   purchase, an arriving message, a schedule tick) to the module through the
   host. The module cannot register a hook; it can only be told. Delivery is
   **notify-only**: the module hears about a thing after it happened and
   cannot veto or alter it. A plugin that changes behaviour mid-request
   (alter a price, refuse a registration) would make core wait on a
   stranger's code, and is not in this design.
3. **Scheduled runs**, declared in the manifest, run by the host on the
   platform's scheduler.
4. **UI declared as manifests** that core renders: forms, tables, menu items,
   settings groups, the way `settings.json` and the scaffold manifest already
   describe pages. No PHP views, no theme-chain reach, no script in the
   admin origin. If a module needs richer UI, that is a new manifest
   primitive core adds for everyone, not an escape hatch. This is the one
   door where core waits on a module (Q14): an admin asking the plugin
   directly expects to wait for its answer, as with a native plugin's page,
   unlike a signal, where waiting would stall someone else's request. The
   wait is bounded: a per-call timeout of a few seconds and at most two UI
   calls at once per site; past either, the page says the plugin did not
   respond.
5. **Outbound web calls to declared hosts.** The module never
   opens a socket. It asks the host to make an HTTPS request; the host
   refuses any hostname the manifest did not declare and makes the call
   through a guard with the same rules as core's `SafeHttpClient` (Q10), so
   internal addresses are unreachable. Declared hosts appear on the install screen.
   Without this door the tier cannot carry an integration, which is what
   most outside plugins are.

**Settings and secrets:** declared in the manifest like `plugin.json`
settings. A field marked secret is stored sealed with `SecretBox` and handed
to the module per call; the module never reads the settings table. Core does
not seal declared secrets today (see What already exists); WP5 depends on
that being fixed for every declared secret, native ones included.

**Data:** models declared in the manifest, owned and migrated by core,
reachable only through door 1. No schema of the module's own.

**Sealed content:** none in the first version. A mediated capability can
come later if a real plugin needs it; it would be answered through the unseal
daemon, never by handing the module a key.

**Public pages:** not in the first version. The doors cover admin and
profile UI. A later version could let a module supply data to a
core-rendered component; a module never emits HTML or script.

### What the module runs as

Any language with a wasm toolchain. The Extism plugin development kits (Rust,
Go, C, JavaScript, Python, .NET, Zig) are the reference, whether or not the
host uses Extism's runtime. The host exposes the doors as host functions.

## What already exists

| Need | Already in the tree | Gap |
|---|---|---|
| Per-plugin credential | Scoped machine keys: `apk_scope` names the actions a key may call, enforced in `ApiAuth::authorize()` | A scope can name actions only, and `apiv1.php` refuses a scoped key on every route family but actions, so record create/read/update/delete refuses it outright. No admin page creates a scoped key; one plugin (`dns_filtering`) makes one in code (WP2) |
| API surface | 328 of 423 logic files carry `_logic_descriptor()` (counted 2026-10-14: the PHP files directly in core `logic/` and in each plugin's `logic/`) | 95 files not reachable; each is a feature the tier cannot use |
| "Something happened" | `SignalBus` (spec `signal_bus`, doc `docs/signals.md`): 39 signals (6 core in `signals.json`, 33 in plugin manifests, counted 2026-10-14), flat JSON-safe payloads, signals and subscribers declared in manifests | Subscribers are PHP methods called in the request. Nothing queues a signal for delivery outside the pool (WP1). None yet for content published, user updated, message arrived or file uploaded (WP6) |
| Scheduled runs | Scheduled task system, tasks declared in JSON | A task type that asks the host to run a module |
| UI from data | Scaffold manifest, `settings.json`, `admin_menus.json`, plugin `settings` in `plugin.json`; FormWriter's `fromDescriptor()` renders a form from a descriptor at request time | A renderer for whole pages and tables from a declaration at request time rather than generating files |
| Plugin-owned data | `update_database` builds tables from field specifications | Field specifications today live in PHP data classes, not a manifest |
| Packaging and install | Marketplace client, ZIP upload with replace (`PackageInstallPage`), the unsigned-package warning, Go binary release channel (including Go programs a plugin ships prebuilt, `GoBinaryPublisher::PLUGIN_PUBLISHERS`) | Recognising a sandboxed package by its contents (Q11); the permission screen |
| Secret settings | `"secret": true` in `settings.json` / `plugin.json` masks the field on the settings form; `SecretBox` seals a few secrets per consumer (OAuth client secrets, the DNS install credential) | Every other declared secret is stored as plain text: `SettingsWriter` never seals. Seal every declared secret in the settings path, for native and sandboxed plugins alike (WP5) |
| Guarded outbound calls | `SafeHttpClient` (PHP); `plugins/mailbox/provisioning/relay-sealer/direct_egress.go` (Go: refuses every redirect, resolves once and dials only the address it checked was public) | Lift the Go guard into the host. The refused-address test cases live in three drifting copies (Q10) |

## What to build

Ordered so the wasm host comes last. WP1 to WP5 are "the doors"; they are
the large part, and they are the same contract whether the code on the far
side is a wasm module on this box or an outside app holding a scoped key
(not offered now, kept possible; see Q9).

**WP1 Signal delivery queue and outgoing webhooks.** A core subscriber on
the bus that does one cheap local write: the signal and its payload into a
delivery table, one row per interested receiver. A scheduled worker drains
it with retry and backoff. Two kinds of receiver: an operator-configured
webhook URL (signed body), and the plugin host. This is the outgoing
webhooks feature `signal_bus` unblocked, built once for both uses. The drain
gives up on a row after a set number of attempts, and delivered and
abandoned rows are kept for a set time, both visible per receiver. The
existing `WebhookLog` model (incoming provider webhooks and their
de-duplication) is left alone: a delivery queue is a different shape.

*Built 2026-10-14 (doc `docs/webhooks.md`).* Receivers are
`SignalReceiver` (`sgr_signal_receivers`, a `kind` column with `webhook`
the only value so far) and deliveries `SignalDelivery`
(`sgd_signal_deliveries`). `SignalDeliveryQueue` is the `*` subscriber
and the drain; `SignalDeliveryDrain` runs it every minute. Messages use the
Standard Webhooks format (HMAC-SHA256, `whsec_` secret sealed with
SecretBox), go out through `SafeHttpClient` over HTTPS only, retry at 1 m,
5 m, 30 m, 2 h, 6 h, 12 h and 24 h, and are given up after the eighth
attempt. A signal raised in a request that opened sealed content is
recorded as withheld, with no payload. Admin pages: System › Webhooks
(permission 8, like API keys). Retention: `signal_delivery_retention_days`
(30). The plugin host joins by adding its kind and a drain of its own.

**WP2 Scope reaches records.** Extend key scope so a credential can be
limited to named models and verbs ("read event registrations, write its own
plugin's models"), and stop refusing scoped keys on the record endpoints
where the scope names the model. This is a new scope grammar and a new
refusal point inside the record endpoints, not an extension of the
action-name check. Per-record authorization keeps applying underneath. An
admin page creates, scopes and revokes keys, since Q9 ships scoped keys as
an admin tool and none exists today.

**WP3 Manifest-declared data.** A module's manifest declares its models;
core creates and migrates the tables through the existing schema system,
namespaced to the plugin, and exposes them through the record endpoints.
Uninstall follows the deletion system.

**WP4 Manifest-rendered UI.** Core renders admin pages, profile pages, menu
entries and settings groups from the manifest. Tables read from door 1;
forms post to an action the module handles. FormWriter emits every form.

**WP5 Install, permissions, uninstall.** Teach the existing marketplace and
upload flow to recognise a sandboxed package by its contents (Q11): no
unsigned warning, accepted on hosts that refuse unsigned packages, judged
the same way on a replacing upload. The install screen that shows
permissions, signals, schedules, outbound hosts in plain words; credential
mint and revoke; secret settings sealed through the general fix (What
already exists, Secret settings). An upgrade that asks for more than the
installed version had stops and asks the admin. The manifest names the
platform version the module was built for, and install refuses a module
that needs an action, signal or UI primitive the site lacks, naming it
(Q13).

**WP6 More signals.** Add signals as real plugins need them: content
published, user updated, message arrived, file uploaded. Each is a catalog
entry and a dispatch call.

**WP7 The plugin host.** Go on wazero. Loads modules, enforces per-call
memory and time limits and a cap on concurrent calls, exposes the doors as
host functions (each honouring the call's deadline), drains its
share of the WP1 queue, runs schedules, serves UI calls from core over a
local socket. Ships and upgrades on the agent and sealer channel, installed
on a node only when it has a sandboxed plugin; on a shared host, inside that
site's container (Q12). A systemd unit where PID 1 is systemd, a cron
keepalive otherwise, and a health check. Runs modules in wazero's
interpreter, and its unit keeps `MemoryDenyWriteExecute`.

**WP8 Author kit.** SDK wrappers for the doors in at least Rust and
JavaScript, a manifest validator, a command that loads a local module into a
dev site and fires a test signal at it, and a log view per plugin (what it
was told, what it called, what was refused and why).

**WP9 One of our own, sandboxed.** A small real plugin built on the tier
before any outsider is invited, to find the gaps ourselves. No existing
plugin fits: each uses PHP views and hooks. The pick is a notifier: it
posts a signal to one declared host (doors 1, 2 and 5, and a secret
setting), sends a daily digest (door 3), keeps a sent-log model of its own
(WP3), and has an admin page listing what it sent beside its settings
(door 4).

## How development changes

### For an outside author

- Writes Rust, Go, JavaScript or anything with a wasm toolchain. No PHP, no
  SQL, no views, no theme files.
- The loop: edit, compile, load into a dev site, fire a test signal, read
  the plugin's log. There is no step debugger across the boundary.
- A refused call says which permission was missing, so the fix is a line in
  the manifest and a new consent prompt, not a guess.
- Anything the doors do not offer is a request to us for a new primitive.

### For us

- **The API is a public promise (Q13).** Renaming or reshaping an action
  breaks someone's shipped plugin, so actions change by addition, and a
  change that breaks an existing one raises the API version with notice.
  Our own pages call the same actions, so the habit applies to page work
  too.
- **A feature without an API action does not exist for the tier.** The 91
  unexposed logic files are the backlog, and the API Endpoint Rules in
  CLAUDE.md become load-bearing rather than hygiene.
- **A signal's payload is a contract.** Fields are added; renaming or
  removing one is a breaking change under Q13.
- **UI primitives are a product surface.** Each "I need richer UI" becomes a
  primitive built once for every plugin.
- **Two plugin systems** to test and document. Native plugin development is
  unchanged.

## Costs, stated for the decision

- Two plugin systems to keep working, document and test.
- The sandboxed tier is much weaker than native and will stay so for a long
  time. Outside authors will ask why. The marketplace should say the reason
  in one sentence: native plugins run inside the process that holds your
  keys, and only code we build gets to do that.
- The logic-descriptor migration becomes load-bearing: any core feature not
  reachable through the API is one the tier cannot use.
- Self-hosters writing PHP on their own box are outside both tiers. They
  have root; the marketplace simply will not carry that code.
- A new process on every site that installs a sandboxed plugin (inside its
  container on a shared host; an estimated 20 MB idle, judged from the
  agent's 21 MB),
  with its own supervision, monitoring and upgrade path.
- A compatibility promise on the API, the signal catalog and the UI
  manifest, kept for as long as outside plugins exist.

## Open questions

- **Q1** DECIDED 2026-10-05: the marketplace does not mention the sandboxed
  tier until the feature exists. No listing, badge or "coming" notice.
- **Q2** DECIDED 2026-10-05: enforce per record type and verb, show grouped
  by feature. The credential is limited to exactly the models and verbs the
  manifest names; the install screen groups them ("Events: read only",
  "Calendar: read and add") with the full list one click away. Core keeps
  the map from record type to feature group.
- **Q3** DECIDED 2026-10-05: own admin section first, named slots added on
  demand. Every sandboxed plugin gets its own page in the admin and nothing
  else to start. When a real plugin needs to appear on a core page, core
  adds one named slot there (for example "event page actions") that any
  plugin can then declare into. Each slot is a promise kept across
  redesigns and needs an ordering rule for when two plugins use it, so none
  is added on a guess; WP9 shows which is needed first.
- **Q4** DECIDED 2026-10-05: one host per node, running every sandboxed
  plugin on it. Rests on modules being stateless (a fresh, capped instance
  per call); if modules were ever allowed to stay running, revisit. On a
  shared host a node is a site container (Q12).
- **Q5** LEFT OPEN 2026-10-05 until a real outside author exists. Billing:
  free only; authors sell a licence on their own site and the plugin checks
  it (needs door 5, nothing from us); or paid through the marketplace with a
  cut (payouts, tax, refunds: a project of its own). Build nothing that
  blocks any of the three.
- **Q6** DECIDED 2026-10-05: our own host program, with Extism's Go library
  inside it to load and run modules. The library runs on wazero (confirmed
  from its README 2026-10-05) and leaves WASI off unless enabled, so the
  "pure Go, no C beneath it" property holds. The host registers only our
  doors as host functions. Extism's built-in web-call feature stays off;
  outbound calls go through door 5 so the internal-address guard applies.
  Versions are pinned and upgrades reviewed. Fallback if the project is
  abandoned: our own runner on wazero speaking the same module contract, so
  existing modules keep working.
  Read from the library's source 2026-10-14: the manifest's timeout becomes
  a context deadline (wazero's close-on-deadline is switched on only when a
  timeout is set) and its page limit becomes wazero's memory limit; there is
  no CPU metering. Its `http_request` host function is registered
  unconditionally in its env module, uses Go's default client (follows
  redirects, no internal-address guard), and with an empty allowed-host list
  refuses by panicking. The env module cannot simply be left out, because
  the Extism kits read input and write output through it. To confirm at
  build: that a panic in a host function ends only that call, and a way to
  make `http_request` refuse without running (replace that one function, or
  carry a patched copy of the library).
- **Q7** ANSWERED 2026-10-05: no outside plugin or author yet. The tier
  opens when one appears. Until then WP9, our own sandboxed plugin, is the
  stand-in that sets build order; WP9 names the pick (2026-10-14).
- **Q8** DECIDED 2026-10-05: door 5 is in the first version. Outbound web
  calls to manifest-declared hosts only, shown on the install screen beside
  what the plugin can read, every call logged per plugin. Also decided:
  a plugin that declares outbound hosts can never be granted sealed content,
  even if a later version adds that capability.
- **Q9** DECIDED 2026-10-05: wait, but keep the possibility open. A "remote
  app" is an outside app on the developer's own server, receiving webhooks
  and holding a scoped key. We do not offer or list remote apps now: the
  code runs on someone else's server and sees the data it reads, which cuts
  against the data-stays-on-your-box promise the wasm host exists to keep.
  Webhooks and scoped keys ship as admin tools. Nothing in WP1, WP2 or WP5
  may assume the far side is a wasm module, so remote apps can be offered
  later without rework.
- **Q10** DECIDED 2026-10-05: the Go host enforces the outbound rules itself
  (declared hosts only, no internal addresses, no followed redirects, every
  call logged) rather than asking core to make each call. A round trip
  through core would hold a web worker for as long as the remote site takes
  to answer. To stop the Go and PHP copies drifting, one shared list of test
  cases (addresses that must be refused, redirects that must not be
  followed) runs against both the host's guard and `SafeHttpClient`.
  Three copies already exist and already differ:
  `tests/security/safe_http_client_test.php`, and `direct_wire_test.go` and
  `mailapps_test.go` in `plugins/mailbox/provisioning/relay-sealer/`. The
  shared list replaces all three and is built ahead of WP7.
- **Q11** DECIDED 2026-10-14: one install process for both kinds, and
  sandboxed plugins are not signed. See One install process for both kinds.
- **Q12** DECIDED 2026-10-14: on a shared host, one host inside each site
  container that has a sandboxed plugin, running modules in wazero's
  interpreter. One host per machine was declined: one process would hold
  every tenant's keys, the container walls would need an opening per site in
  both directions, and per-tenant limits would have to be rebuilt inside it.
  See The host lives outside the pool.
- **Q13** DECIDED 2026-10-14: every API action is available to plugins, as
  it is to our own pages. Additions (an action, a field, a signal or payload
  field, a UI primitive) are always allowed; a change that breaks an
  existing one raises the API version, with notice. Left open until a real
  outside author exists, as Q5 is: how long the notice runs, and whether the
  old version keeps running beside the new one. Built with the tier: the
  manifest names the platform version a module was built for, and install
  refuses a module that needs something the site lacks, naming it (WP5).
  Nothing owned this before: `docs/api.md` freezes the response envelope,
  and its version handshake forces old clients to upgrade, the opposite of
  what a shipped plugin needs. Native plugins need no such rule: ours ship
  in the same release as core, and an uploaded native plugin uses core's PHP
  internals, which no rule covers and the unsigned warning already implies.
- **Q14** DECIDED 2026-10-14: UI calls wait, bounded. Door 4 holds a web
  worker while the module answers, as a native plugin's page does, with a
  per-call timeout native pages lack and at most two UI calls at once per
  site; a timeout shows on the page as the plugin not responding. The
  timeout is set at build, long enough for a "test connection" button
  making one outbound call (5 to 10 seconds). Declined: never waiting, with
  pages showing data the module wrote earlier and form submissions queued,
  which costs immediate form feedback and makes plugins harder to write.
