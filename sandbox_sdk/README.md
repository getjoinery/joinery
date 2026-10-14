# Joinery sandboxed plugin kit

Everything an author needs to write a sandboxed plugin: a WebAssembly module
and a `manifest.json` that a Joinery site installs from a zip, with no
warning, and runs apart from itself (docs/sandboxed_plugins.md in the
Joinery tree has the whole contract).

| Folder | What it is |
|---|---|
| `rust/joinery-plugin` | The Rust crate: the calls (`Call`, `UiInput`, `SignalInput`, `ScheduleInput`, `UiAnswer`) and the doors (`api`, `http`, `log`) over Extism's Rust PDK |
| `js/` | The JavaScript kit: `joinery-sdk.js` (the same calls and doors), `joinery.d.ts` and `build.sh`, over Extism's JS PDK |
| `examples/notifier` | A real plugin in Rust: posts site events to a chat webhook, keeps a log of what it sent in its own model, sends a daily digest, and has an admin page |
| `examples/greeter-js` | A small plugin in JavaScript: answers a form with the site's member count |

A Go module works too (`GOOS=wasip1 GOARCH=wasm go build -buildmode=c-shared`
with Extism's Go PDK): the plugin host's own test module,
`maintenance_scripts/install_tools/sandbox_host/testdata/fixture`, is one.

## Writing one

1. **The manifest.** Name the plugin and say what it may do: the API actions
   and records it uses, the signals it wants, its schedules, the hosts it
   posts to, its settings, its own models and its pages. An admin approves
   exactly that, in plain words, before it runs.
2. **The module.** Export the calls the manifest needs: `on_ui` for forms,
   `on_signal` for signals, `on_schedule` for schedules. Each is given
   `{"input": …, "settings": {…}}` and answers JSON. A signal your export
   fails (an error, or out of time) is tried again later; one it answers is
   done, so fail only what could work next time.
3. **Build.**
   - Rust: `cargo build --release --target wasm32-unknown-unknown`
     (`rustup target add wasm32-unknown-unknown` once).
   - JavaScript: `bash js/build.sh plugin.js plugin.wasm` (needs `extism-js`
     and binaryen's `wasm-merge`/`wasm-opt` on the PATH).
4. **Package.** A folder named as the plugin holding exactly `manifest.json`
   and the one `.wasm`, zipped. Nothing else may be in it.

## Trying it on a dev site

On the site's server, from `public_html/` (a dev site: the `debug` setting on):

```
php utils/sandbox_plugin.php validate path/to/notifier        # or the zip
php utils/sandbox_plugin.php load path/to/notifier            # install, approve, activate
php utils/sandbox_plugin.php call notifier ui send_test '{"text":"hi"}'
php utils/sandbox_plugin.php call notifier signal account.signup '{"display_name":"Ada"}'
php utils/sandbox_plugin.php call notifier schedule digest
php utils/sandbox_plugin.php log notifier
```

`validate` runs anywhere and checks the manifest, the module's exports and
imports against it, and whether the site has what it asks for. `call` prints
what the module answered and what it asked of each door. The same log is on
the admin side: Plugins › the plugin › Call log.

On any site, upload the zip on the Plugins page.
