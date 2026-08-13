# Plugin bootstrap key — every plugin gets a load point, not just vault consumers

**Status: IMPLEMENTED 2026-08-13, same day as drafted — built in the same
commit as the vault consumer platform work, because it generalizes the loader
that work created and the loader's contract should change once, not twice.
Split out of `specs/core_api_simplification.md` (whose R4 records the decision
history). The vault consumer platform spec is in `specs/implemented/` and was
not re-opened by this spec. Implementation note: the loader landed as
`includes/PluginBootstraps.php`; the test seam gained an optional per-entry
`path` override so fixture bootstraps can live in the test tree.**

## The problem

`VaultUnlock::loadConsumerBootstraps()` is the only code path in the tree
that loads `plugins/{name}/includes/bootstrap.php` — verified by grep, no
other core file loads a plugin bootstrap. Everything a bootstrap registers is
therefore transitively gated on the plugin declaring `vaultConsumer`:

- `UploadPurposeRegistry::register()` — a chunked-upload purpose
- `File::registerDecryptHook()` / `registerStreamingDecryptHook()`
- `VaultDeferredWork::register()`
- any policy callable (the `MailIdentityGuard` shape)

A plugin that wants an upload purpose but no vault has no load point. The
registries themselves are fine; the front door is vault-shaped.

## The change

A top-level `bootstrap` key in `plugin.json`:

```json
"bootstrap": "includes/bootstrap.php"
```

- A core loader — `PluginBootstraps::load()` or a `PluginHelper` method,
  implementation's choice — requires every **active** plugin's declared
  bootstrap, once per request, lazily from the same call sites that trigger
  loading today. `VaultUnlock::loadConsumerBootstraps()` keeps its name and
  every call site and delegates to it.
- The `vaultConsumer` block in `plugin.json` **drops its `bootstrap` field**;
  a vault consumer's bootstrap is just its plugin bootstrap. Mailbox and
  joinery_ai migrate their path to the top-level key. `vaultConsumer` keeps
  everything else: `order`, `reseals`, `caches` — the obligations, not the
  loading.
- Core consumers in `vault_consumers.json` are unchanged — they have no
  `plugin.json`, so their entries keep carrying their own bootstrap paths.
- Everything the vault work built moves with the loader, unchanged in
  behavior: the `beginLoading()`/`endLoading()` attribution wraps every
  plugin bootstrap load (a plugin without a `vaultConsumer` block simply
  accrues no vault obligations), the once-per-request guard, and the
  loaded-outside-the-loader detection against `get_included_files()`. The
  invariant generalizes with it: plugin bootstraps load only through this
  loader.
- Load order: `vaultConsumer.order` continues to govern consumers (the
  deterministic sort the vault registry established). Plugins with no
  `vaultConsumer` block load after ordered consumers, sorted by plugin name —
  deterministic, and no current behavior depends on their relative order.
- A declared bootstrap file that is missing is logged and skipped, never
  fatal — the standard malformed-declaration treatment.

## Docs

- `docs/plugin_developer_guide.md` gains a short *Bootstrap* section: the
  key, what belongs in a bootstrap (registry registrations, hooks — no
  request work), and when it loads. The upload-purpose and decrypt-hook
  passages stop implying vault consumership; the *Building a Vault Consumer*
  section's registration paragraph points at the shared key.
- `docs/sealed_vault.md` — the consumer-registration passage describes
  `vaultConsumer` as obligations riding on the plugin bootstrap, current-state
  voice.

## Tests

- A bootstrap-only plugin (no `vaultConsumer`) loads and can register an
  upload purpose — the capability the current gate denies. Uses the
  `setPluginDeclarationsForTests()` seam the vault work established.
- A `vaultConsumer` plugin's obligations still attribute correctly with the
  bootstrap path in its new location.
- The existing vault suites (`vault_registry_test`,
  `sealed_consumer_declaration_test`) adapt to the moved key and stay green;
  `mailbox_reseal_test.php` stays green untouched, as always.
- A declared-but-missing bootstrap file is skipped and logged.

## Non-goals

- No change to what bootstraps may register, to any registry's semantics, or
  to the vault obligation model (`reseals`/`caches`/rotation guard).
- No eager loading — the lazy, call-site-triggered model is unchanged.
- No re-opening of `specs/implemented/vault_consumer_platform.md`; this spec
  only relocates the front door that work built.
