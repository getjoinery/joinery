# Plugin tiers: native and sandboxed

**Status:** Proposed by the owner 2026-09-09, being discussed. Nothing is
decided or built. The security consequence is tracked in
`security_inventory.md`; this spec is the design.

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

## The sandboxed tier

### The host lives outside the pool

Not a wasm runtime loaded into php-fpm through FFI: a runtime bug there is a
pool compromise, which is the thing the tier exists to prevent. A separate
plugin-host process under its own uid, the same shape as the unseal daemon,
runs the modules and reaches core over `/api/v1` with a per-plugin scoped
credential. A Go host on wazero (pure Go, no C beneath it) matches the
binaries the fleet already ships (agent, sealer) and their release channel.

### Four doors

A module can do these four things and nothing else:

1. **API calls** under the permissions the manifest declares. The credential
   is minted at install for exactly those permissions and revoked at
   uninstall. Permissions are the API's existing authorization surface, named
   in the manifest the way `plugin.json` names settings today.
2. **Events.** Core delivers the events the manifest subscribes to (a purchase,
   an arriving message, a schedule tick) to the module through the host. The
   module cannot register a hook; it can only be told.
3. **Scheduled runs**, declared in the manifest, run by the host on the
   platform's scheduler.
4. **UI declared as manifests** that core renders: forms, tables, menu items,
   settings groups, the way `settings.json` and the scaffold manifest already
   describe pages. No PHP views, no theme-chain reach, no script in the
   admin origin. If a module needs richer UI, that is a new manifest
   primitive core adds for everyone, not an escape hatch.

Data: models declared in the manifest, owned and migrated by core, reachable
only through door 1. No schema of the module's own.

Sealed content: **none** in the first version. A mediated capability can
come later if a real plugin needs it; it would be answered through the unseal
daemon, never by handing the module a key.

### What the module runs as

Any language with a wasm toolchain. The Extism plugin development kits (Rust,
Go, C, JavaScript, Python, .NET, Zig) are the reference, whether or not the
host uses Extism's runtime. The host exposes the four doors as host
functions; the module never opens a socket or a file.

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

## Open questions to toss around

- **Q1** Does a sandboxed plugin get a marketplace listing at all in 1.0, or
  is the tier invisible until the host exists? (Recommendation: invisible;
  a listing that cannot install is worse than none.)
- **Q2** Permission granularity: per model and verb ("read events, write
  calendar entries"), or per feature ("calendar")? Phone-style coarse grants
  are what people understand; per-verb is what the API enforces.
- **Q3** Where does a sandboxed module's UI show up: its own admin section,
  or merged into the feature it extends? Merged is friendlier and harder to
  render safely.
- **Q4** Does the host run one process per plugin or one per node? One per
  node is simpler; one per plugin makes a misbehaving module killable on its
  own.
- **Q5** Billing: can a sandboxed plugin be paid, and does the marketplace
  take a cut? Unrelated to security, decides whether outside authors bother.
- **Q6** Is Extism's host adopted as-is, or only its plugin-side contract
  with our own host on wazero? Adopting the host is faster; owning it keeps
  the door list ours.
