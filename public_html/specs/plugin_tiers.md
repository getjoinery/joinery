# Plugin tiers: native and sandboxed

**Status:** Proposed by the owner 2026-09-09, design discussion continued
2026-10-05. Design questions decided 2026-10-05 (see Open questions; only
Q5, billing, is left open). Nothing is built. The security consequence is tracked
in `security_inventory.md`; this spec is the design, the inventory of what
already exists, the build list and the workflow.

**This is the one spec for the sandboxed plugin tier.** It also carries the
outgoing-webhooks work that `signal_bus` unblocked and never built, because
the tier needs the same delivery piece (see WP1).

## The idea

Two kinds of plugin, told apart by a key, not a judgement.

**Native.** Signed by the release key on the management node, which means
built from our repository by our publisher. Runs in the php-fpm pool with
full reach, exactly as plugins do today. Served on the marketplace with a
native badge. A partner's plugin we like is not native, and there is no
countersigning of anyone else's PHP.

**Sandboxed.** Everything else. A WebAssembly module that reaches the
platform only through the API, under permissions its manifest declares and
the install screen shows. The 1.0 marketplace is native only; the sandboxed
tier opens when the first outside author needs it.

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

**Modules are stateless (decided 2026-10-05).** The host makes a fresh instance for
each signal, scheduled run or UI request and throws it away afterwards.
Nothing lives in module memory between calls; anything a plugin wants to
remember goes through the API into its own data. Every call runs under a
memory cap, a CPU budget and a wall-clock timeout. A misbehaving module is
killed by ending one call, with no effect on any other plugin.

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
   primitive core adds for everyone, not an escape hatch.
5. **Outbound web calls to declared hosts.** The module never
   opens a socket. It asks the host to make an HTTPS request; the host
   refuses any hostname the manifest did not declare and makes the call
   through the same guarded client core uses (`SafeHttpClient`), so internal
   addresses are unreachable. Declared hosts appear on the install screen.
   Without this door the tier cannot carry an integration, which is what
   most outside plugins are.

**Settings and secrets:** declared in the manifest like `plugin.json`
settings. A field marked secret is stored encrypted by core and handed to
the module per call; the module never reads the settings table.

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
| Per-plugin credential | Scoped machine keys: `apk_scope` names the actions a key may call, enforced in `ApiAuth::authorize()` | A scope can name actions only. Record create/read/update/delete refuses a scoped key outright (WP2) |
| API surface | 282 of 373 logic files carry `_logic_descriptor()` | 91 files not reachable; each is a feature the tier cannot use |
| "Something happened" | `SignalBus` (spec `signal_bus`, doc `docs/signals.md`): 17 signals, flat JSON-safe payloads, signals and subscribers declared in manifests | Subscribers are PHP methods called in the request. Nothing queues a signal for delivery outside the pool (WP1). Catalog is thin outside commerce and events |
| Scheduled runs | Scheduled task system, tasks declared in JSON | A task type that asks the host to run a module |
| UI from data | Scaffold manifest, `settings.json`, `admin_menus.json`, plugin `settings` in `plugin.json` | A renderer that takes a page declaration at request time rather than generating files |
| Plugin-owned data | `update_database` builds tables from field specifications | Field specifications today live in PHP data classes, not a manifest |
| Packaging and trust | Package signing, marketplace client, Go binary release channel | A package type for a module; install screen |
| Guarded outbound calls | `SafeHttpClient` | Go host needs the same guard, or proxies through core |

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
webhooks feature `signal_bus` unblocked, built once for both uses. The
existing `WebhookLog` model is for incoming webhooks; confirm whether it is
reused or left alone.

**WP2 Scope reaches records.** Extend key scope so a credential can be
limited to named models and verbs ("read event registrations, write its own
plugin's models"), and stop refusing scoped keys on the record endpoints
where the scope names the model. Per-record authorization keeps applying
underneath.

**WP3 Manifest-declared data.** A module's manifest declares its models;
core creates and migrates the tables through the existing schema system,
namespaced to the plugin, and exposes them through the record endpoints.
Uninstall follows the deletion system.

**WP4 Manifest-rendered UI.** Core renders admin pages, profile pages, menu
entries and settings groups from the manifest. Tables read from door 1;
forms post to an action the module handles. FormWriter emits every form.

**WP5 Install, permissions, uninstall.** Package format (module + manifest,
signed by the marketplace as "sandboxed, from author X"), the install screen
that shows permissions, signals, schedules, outbound hosts in plain words,
credential mint and revoke, secret settings storage. An upgrade that asks
for more than the installed version had stops and asks the admin.

**WP6 More signals.** Add signals as real plugins need them: content
published, user updated, message arrived, file uploaded. Each is a catalog
entry and a dispatch call.

**WP7 The plugin host.** Go on wazero. Loads modules, enforces per-call
memory, CPU and time limits, exposes the doors as host functions, drains its
share of the WP1 queue, runs schedules, serves UI calls from core over a
local socket. Ships and upgrades on the agent and sealer channel, installed
on a node only when it has a sandboxed plugin. Its own unit and health
check.

**WP8 Author kit.** SDK wrappers for the doors in at least Rust and
JavaScript, a manifest validator, a command that loads a local module into a
dev site and fires a test signal at it, and a log view per plugin (what it
was told, what it called, what was refused and why).

**WP9 One of our own, sandboxed.** A small real plugin built on the tier
before any outsider is invited, to find the gaps ourselves.

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

- **The API is a public promise.** Renaming or reshaping an action breaks
  someone's shipped plugin. Actions, signal payloads and manifest primitives
  need a version and a deprecation rule before the first outside plugin.
- **A feature without an API action does not exist for the tier.** The 91
  unexposed logic files are the backlog, and the API Endpoint Rules in
  CLAUDE.md become load-bearing rather than hygiene.
- **A signal's payload is a contract.** Fields are added, never renamed or
  removed.
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
- A new process on every node that installs a sandboxed plugin, with its own
  unit, monitoring and upgrade path.
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
  per call); if modules were ever allowed to stay running, revisit.
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
  Versions are pinned and upgrades reviewed. To confirm at build: the
  library's memory and time limit controls, and that its web-call feature
  can be fully disabled. Fallback if the project is abandoned: our own
  runner on wazero speaking the same module contract, so existing modules
  keep working.
- **Q7** ANSWERED 2026-10-05: no outside plugin or author yet. The tier
  opens when one appears. Until then WP9, our own sandboxed plugin, is the
  stand-in that sets build order; pick one that exercises every door.
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
