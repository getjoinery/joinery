# Package signing: what root will install, and how the owner overrides it

**Status:** IMPLEMENTED. Written 2026-09-11 from the owner's design (below);
closes S9 of `security_inventory.md`; D1 decided (Option 1, owner 2026-09-11).
WP1–WP7 committed 2026-09-12 (8a6883eb, 81f04561) after two accepted review
rounds. Moved to implemented/ 2026-09-12 with the live proof (WP8) still to
run after the next release; that proof is tracked on the running to-do list,
not here.

## The goal, in one sentence

**Code reaches a node's tree only when it was built by us, or when the owner
has read a warning that says exactly what they are giving away and said yes
anyway.** A first-party plugin from the marketplace installs with no prompt
and works. A third-party PHP plugin installs after a warning page. Everything
else that puts code on the box is refused.

## The owner's design (2026-09-11)

- First-party plugins from the marketplace install and work with no ceremony.
- Third-party plugins install only after a warning page that says installing
  an unsigned plugin is extremely dangerous and gives the plugin access to
  everything on the site, including all mail.
- Third-party plugins are sideloaded (uploaded), never listed in the
  marketplace. The marketplace stays first-party only.
- The easy third-party path is the sandboxed wasm tier (`plugin_tiers.md`),
  not yet designed. The unsigned PHP path is the stopgap until it exists.

## The problem in plain terms

A node's code tree belongs to root, and the web server cannot write it
(`implemented/read_only_tree.md`). When the owner installs a plugin, the page
queues a request and root carries it out. Root checks that the request is one
of six known kinds and that the files came from somewhere sensible. It does
not check who built them.

**Example one, the one that matters most.** The place a node downloads
upgrades from is a plain text setting, "Upgrade source". Anyone with an admin
session can point it at any server. Click "Upgrade now", and root downloads
that server's archive, swaps it into place as the live site, and runs its
migrations as root. The same setting feeds marketplace installs. So today a
stolen admin session is root on the box in three clicks, and nothing in the
inventory's closed rows stops it: S2 bounded every setting that becomes a
path, but this setting becomes a download.

**Example two.** Every archive we publish already carries a signed list of
its files (`RELEASE_MANIFEST` and `.sig`, one Ed25519 signature over the
sha256 of every file). The agent binary carries the public key and refuses to
run a script that is not on a verified list. But no PHP on a node ever reads
the signature. Root installs the archive, then the agent trusts it because it
is on disk. The check exists at exactly the wrong end.

**Example three.** A plugin can declare a host installer that root runs on
every converge (`mailbox` does, to set up Postfix). The converger runs it for
every active plugin, first-party or not. Install any plugin, and its shell
script runs as root five minutes later.

**Example four, the customer's path.** A managed customer has no shell. They
upload a plugin ZIP; the page unpacks and checks it, then shows a shell
command to finish, which they cannot run. The owner's design wants a warning
page there instead.

## What exists (measured 2026-09-11)

| Piece | Where | State |
|---|---|---|
| Signing at publish | `TreeManifestPublisher::write()` — sha256 per file, sorted, Ed25519 detached signature via sodium; `publish_artifact()` puts the pair into every core, plugin and theme archive | Built, in every archive since the manifest gate |
| Signing key | `config/agent_signing_key` (secret, 0600 root) + `.pub` on the publishing box; `AgentDistPublisher::ensureKeys()` | Built. Two keys per channel are approved and unbuilt (`per_channel_release_signing.md`) |
| Public key on a node | Compiled into the agent binary (`updatePubKeyB64`); also in the agent bundle's `manifest.json` as `signing_public_key` | Built. No file root-side PHP can read |
| Verifier, Go | `primitives/manifest.go` `SignedTreeVerifier`; `ArtifactManifests` resolves a file to the artifact that ships it, no cross-manifest fallback, no manifest = refuse | Built, used by the agent before running any script |
| Verifier, PHP | none | **Missing** |
| Manifest coverage | `TreeManifestPublisher::$excluded_segments` excludes `vendor` everywhere, including inside a plugin directory | A signed plugin archive can carry unsigned vendor code (B3 of `vault_key_memory_exposure.md`) |
| Marketplace install | `admin_plugins_logic.php` → `RootRequest::submit('install_plugin')` → `utils/install_extension.php plugin <name>` fetches from `upgrade_source` and installs | Built, unverified download |
| Marketplace install, API | `logic/marketplace_install_logic.php` → `MarketplaceClient::install()` → `installFromTarGz()` in the web request | **Stale**: writes the root-owned tree from the pool and fails part-way (B4 below) |
| Upload | `AbstractExtensionManager::stage()` — unpacks under `uploads/staging/`, refuses path escapes, bombs, symlinks; validates the manifest; the page shows `sudo -u root php utils/install_extension.php plugin --staged=<dir>` | Built. Deliberately no root request kind (`RootRequest::NO_PACKAGE_KIND`) |
| Core upgrade | `utils/upgrade.php` downloads `<upgrade_source>/utils/upgrade?serve-upgrade=1`, deploys, copies the manifest pair to the site root | Built, unverified download |
| `upgrade_source` | `settings.json` line 1966, type text, no validation, not vault gated | Open door (B1) |
| Host installers | `_plugin_installers_start.sh` runs `host_installer` of every active plugin as root on every converge | Unconditional (B2) |
| Second-factor step-up | `SessionControl::require_recent_second_factor()` / `step_up_outstanding()`; marker in `pks_passkey_ceremonies`, session-bound | Built, used by domain security-level changes |
| Owner identity root can check | none — the recovery public key and the passkey public keys live in the database, which the pool writes | **Missing** (D1) |

## Findings

### B1 — the upgrade source setting is root code execution

`upgrade_source` is a free text setting. `upgrade.php` (run as root by the
`upgrade` root request, and by the agent's `apply_update`) downloads whatever
it names and deploys it; `install_extension.php plugin <name>` does the same
for a plugin. No signature is checked at either end. An admin session that can
edit settings owns the box. This is the admin-session-to-code class S1–S3
closed for paths, left open for a URL. **Closed by WP1 + WP2** (verification
makes the setting a download location and nothing more) and hardened by WP7.

### B2 — root runs any active plugin's host installer

`_plugin_installers_start.sh` reads the active plugin list and runs each
declared `host_installer` as root. Nothing distinguishes a plugin we built
from one that was uploaded. **Closed by WP5.**

### B3 — a signed plugin archive can carry unsigned vendor code

The manifest excludes `vendor` as a segment anywhere in the path, which is
right for the site-root `vendor` (outside `public_html`, not shipped) and
wrong inside a plugin directory, where a Composer tree is part of the plugin.
An archive that verifies can still bring bytes nobody signed. **Closed by WP0.**

### B4 — the marketplace API endpoint writes the tree from the pool

`marketplace_install_logic.php` still calls `MarketplaceClient::install()`,
which downloads and extracts into `plugins/` inside the web request. Since
the read-only tree that is a permission error part-way through an
extraction. The admin page uses the root request; the API descriptor does
not. **Closed by WP4.**

### B5 — no root-held statement of who the owner is

Root can verify our signature (after WP1) because the key will sit in a file
root owns. Root cannot verify *the owner approved this* because every
credential that identifies the owner — recovery public key, passkey public
keys, the step-up marker — lives in the database, which the web user writes.
A web-tier attacker who can execute code can therefore mint any approval the
page can. This is what D1 is about. It also applies, today, to the agent's
restore approval, which reads `backup_recovery_public_key` from the settings
store. **Not closed here; recorded for `key_management_simplification.md`.**

## Settled with the owner (2026-09-11)

- **Two tiers, told apart by our signature.** Signed = built by our
  publisher, verified by root on the node against a root-owned key file.
  Unsigned = everything else.
- **Signed packages install with no prompt**, from the marketplace or from an
  uploaded ZIP alike — an uploaded copy of a marketplace archive is the same
  bytes and verifies the same way.
- **Unsigned packages install after a warning page**, superadmin only, with
  the wording the owner gave: extremely dangerous; the plugin gets everything
  the site has, including all mail.
- **The marketplace lists first-party only.** No countersigning of anyone
  else's PHP, ever (`plugin_tiers.md`). The vault spec's countersigning idea
  is withdrawn.
- **Self-hosters with a shell keep the shell command.** It still works and
  still bypasses nothing root does not already trust (root ran it).

## D1 — how root knows the owner approved an unsigned install

**Decided 2026-09-11: Option 1.** Owner: "Option 1 works for now." Option 2
stays recorded as the later swap.

The warning page needs a "yes" root can trust. Two shapes:

**Option 1 — the second-factor step-up marker.** The warning page's
"Install anyway" button requires a recent second-factor confirmation
(`require_recent_second_factor`), the same gate a domain security-level change
uses. The root request carries the acknowledgement; root checks the marker
row exists for that session and is fresh.
- One tap for the owner. Small build. Same gate as the rest of sensitive
  administration.
- Catch: the marker is a database row. An attacker who already runs code in
  the web tier can write it, stage a package, and have root copy it into the
  tree. For that attacker the read-only tree's "nothing persists" becomes
  "nothing persists unless they forge one row". Root still never runs their
  code as root (WP3 restrictions), and every superadmin is emailed. A stolen
  admin *session* cannot do this: the marker needs the second factor at the
  keyboard.

**Option 2 — a root-pinned owner key.** Root keeps its own copy of the
superadmin's passkey public key (or recovery public key) in a root-owned
file under `config/`, pinned the first time it is enrolled, replaceable only
by a request signed by the old key. The warning page's "yes" is a WebAuthn
assertion over a challenge root minted; root verifies it against the pin
before it copies anything.
- Closes B5 for this door, and gives the agent's restore approval the same
  anchor.
- Catch: a key-management design (pin, rotation, loss) that
  `key_management_simplification.md` has not settled; two converges per
  install (mint, then verify); and the pin is trust-on-first-use, so it
  protects a site that was clean when the owner enrolled and nothing else.

**Why Option 1 now.** The unsigned path is the stopgap the wasm
tier replaces, the web-tier attacker it leaves is residual 6 of the accepted
threat model (a sandbox escape, now that the parsers are jailed), and Option
2's pin belongs to the key-management spec, where it can serve restores too.
Build the page so the "yes" is one function call, and Option 2 swaps it later
without touching the page.

## The plan, step by step

1. **Cover vendor.** The manifest of a plugin or theme artifact lists every
   file under it. (WP0)
2. **Put the key where root can read it.** Every node carries
   `config/release_verify_keys`, root:root 0644, one base64 Ed25519 public key
   per line. The converger writes it from the agent bundle's `manifest.json`
   on every tick, so it heals itself and takes a second key the day the
   stable channel ships one. (WP1)
3. **Write the PHP verifier**, root-side, one class, and make every path root
   uses to put code on the box call it: the core upgrade, the marketplace
   install, the staged upload. Nothing installs unverified except through
   step 4. (WP2)
4. **Bring back the package request kind**, now that root can tell our
   archive from a stranger's: signed → install; unsigned → refuse unless the
   request carries the owner's acknowledgement, in which case install under
   the unsigned restrictions. (WP3)
5. **Retire the stale API path.** (WP4)
6. **Gate the host installers on the signature.** (WP5)
7. **Build the warning page** and the unsigned badge. (WP6)
8. **Bound the setting.** (WP7)
9. **Prove it on a node.** (WP8)

## Work packages

- **WP0 — the manifest covers vendor inside an artifact.**
  `TreeManifestPublisher`: `vendor` leaves `$excluded_segments` and joins a
  new `$excluded_top_level` entry, so the site-root `vendor` stays out and a
  plugin's `vendor/` is listed. `tree_manifest_test.php` pins both. The Go
  parser needs nothing: same format, more lines. Publish once so every
  marketplace archive carries the wider manifest before WP2 starts refusing.

- **WP1 — `config/release_verify_keys` on every node.**
  (1) `_plugin_installers_start.sh`, root, every tick: read
  `signing_public_key` from the installed agent bundle's `manifest.json`
  (root-owned, installed from a bundle the agent verified) and write the file
  when absent or different; append, never replace, so a key from an earlier
  bundle survives a channel change. (2) `install.sh`: on a box that installs
  no agent bundle, fetch `<upgrade_source>/utils/upgrade?serve-verify-key=1`
  once at install, over TLS, and write the file — trust on first use, the
  same trust the install already places in the code it downloads. (3)
  `upgrade.php` serves that endpoint from its own `config/agent_signing_key.pub`
  on a publishing box, and from its own `release_verify_keys` otherwise.
  (4) The file's absence is a `VaultHealth` warning naming the fix.
  Pinned by `installer_contract_test` and `host_converger_gate.sh`.

- **WP2 — `PackageSignature`, core `includes/`.**
  `PackageSignature::verify(string $dir): PackageVerdict` reads
  `RELEASE_MANIFEST` and `.sig` in `$dir`, verifies the signature against
  every key in `config/release_verify_keys` (`sodium_crypto_sign_verify_detached`),
  then walks `$dir`: every file present must be listed with a matching sha256,
  every listed file must be present. Extra file, missing file, bad hash,
  unknown key, no manifest, no key file — each a distinct verdict with a
  sentence for the operator. Reads nothing outside `$dir` and the key file.
  Callers, all root-side: `utils/upgrade.php` before the deploy swap (refuse
  the deploy on any verdict but `signed`; the root node, which upgrades from
  itself, is exempt as it is today); `utils/install_extension.php` in both
  forms, after the copy into the root-owned working directory and before
  anything is read from it. A theme is verified the same way.
  `tests/security/package_signature_test.php` (safe tier): sign a fixture
  tree with a throwaway key in the harness, then verify; tamper one byte;
  add a file; remove a file; wrong key; empty key file.

- **WP3 — the `install_package` root request kind.**
  `RootRequest::KINDS` gains `install_package {type, staged_dir, unsigned_ack?}`.
  `NO_PACKAGE_KIND` and its comment go; the comment's argument moves into the
  kind's own doc block with the answer: root verifies before it moves.
  `install_extension.php --staged=<dir>` becomes what the kind runs.
  Verdict `signed` → install as today. Any other verdict, no `unsigned_ack`
  → refuse, transcript names the verdict. Any other verdict with
  `unsigned_ack` → root checks the acknowledgement (D1, Option 1: a fresh
  step-up marker for the submitting session, read by root from the database
  through the same `SessionControl` call the page used) and installs under
  **the unsigned restrictions**:
  1. Migrations run as the web user, not root: `install_extension.php`
     re-executes its migration step through `runuser -u www-data`. (Signed
     packages keep today's behaviour; they are ours.)
  2. The plugin row records `plg_trust = 'unsigned'` (new column, string,
     `signed|unsigned`, set by root at install from the verdict; a plugin
     model field, so `update_database` adds it).
  3. Every superadmin is emailed at install: name, version, who approved,
     when, from what IP, with the warning text repeated.
  4. The install is a row in the event log alongside vault window events.
  The `install_plugin` kind (marketplace, by name) keeps its shape and gains
  the same verification; an unsigned marketplace archive is refused outright,
  since the marketplace is first-party only and an unsigned one there is a
  publishing defect.

- **WP4 — retire the pool-side install.** `MarketplaceClient::install()`
  and `marketplace_install_logic.php` submit `install_plugin` / a theme
  equivalent and return the request id; `installFromTarGz()` and
  `installFromZip()` gain `refuse_from_web()`. The Marketplace page's
  install button already watches a root request panel; the API caller gets
  the request id and polls `RootRequest::status()`.

- **WP5 — host installers run only for signed plugins.**
  `_plugin_installers_start.sh`, before running a plugin's `host_installer`,
  verifies the plugin directory with `php utils/verify_package.php <dir>` (a
  thin CLI over `PackageSignature`) and skips with a logged line on any
  verdict but `signed`. Exemption: a box that holds `config/agent_signing_key`
  is the publisher and trusts its own tree (dev, the root node). Pinned by
  `host_converger_gate.sh` with an unsigned fixture plugin.

- **WP6 — the warning page and the badge.**
  `admin_plugins` upload: after `stage()`, the page asks root for a verdict
  by submitting `install_package` **without** `unsigned_ack`; `signed` →
  installed, done. Refused-unsigned → the page shows the warning: the
  owner's wording, the plugin's name, version, author from its manifest,
  the file count, and one button, "Install anyway". The button POSTs through
  `require_recent_second_factor()`, then submits `install_package` with
  `unsigned_ack`. Themes: the same on `admin_themes`. The plugins list shows
  an **Unsigned** badge on every `plg_trust = 'unsigned'` row, permanently;
  `VaultHealth` gains a row listing unsigned plugins and themes present
  (advice, never a gate). FormWriter for the form; the button is the
  single-button exception.

- **WP7 — bound `upgrade_source`.** `settings.json`: `validation`
  `^https://[A-Za-z0-9.-]+(:[0-9]+)?$`, `vault_gated: true`, per the S2
  doctrine. With WP2 in place the setting can only choose where a verified
  archive is fetched from; this keeps a typo from becoming a refused upgrade.

- **WP8 — prove it on a node.** On jeremytunnell (agent, no shell needed):
  install a marketplace plugin from its admin page and see it activate;
  upload the same archive as a ZIP and see it install with no warning;
  upload a hand-made unsigned ZIP and see the warning, the step-up, the
  install, the badge, the email; converge and see the unsigned plugin's host
  installer skipped in the log; set `upgrade_source` to a server serving a
  tampered archive and see "Upgrade now" refuse with the verdict.

## Acceptance

- A node with a clean `config/release_verify_keys` installs a marketplace
  plugin from the admin page with no prompt and the plugin activates.
- The same node refuses a core upgrade, a marketplace install and a staged
  install whose archive was altered by one byte, added to, or signed by an
  unknown key, and the transcript says which.
- An unsigned ZIP reaches the tree only through the warning page, only after
  a fresh second-factor confirmation, only with its migrations run as the web
  user, and only with every superadmin emailed.
- The converger never runs a host installer from a directory that does not
  verify, except on the publishing box.
- No web request writes `plugins/` or `theme/`; `grep` finds
  `refuse_from_web` on every install entry point.
- The manifest of a plugin archive lists its `vendor/` files.
- `upgrade_source` refuses a non-https value and needs the vault to change.

## Standing rules for the builder

- Never `git commit` or `git add`; the owner commits by path.
- Never a password, key or token in the transcript. The throwaway test key
  is minted in the harness and never printed.
- Dev's converger timer is stopped by the owner before any edit under
  `maintenance_scripts/install_tools/`; ask first.
- Schema changes are `$field_specifications` only; `update_database` on dev
  needs the owner's confirmation.
- Docs describe the current state only. Update `docs/plugin_developer_guide.md`
  (what a package must carry), `docs/deploy_and_upgrade.md` (what root
  verifies before a deploy), `implemented/read_only_tree.md` is never edited —
  the kind's own doc block carries the reasoning now.
- `DocumentText::JAIL_REQUIRED` and every `VaultHealth` row stay advisory.

## Review round 1 (2026-09-11), STOP POINT 1

Verified by the reviewer against real archives with the real key:
`mailbox-1.115.1` and `scrolldaddy-1.0.8` verify `signed`; `joinery-core-0.8.388`
fails `extra_file` on exactly the 23 `public_html/assets/vendor` files it was
published without listing, with nothing else extra, nothing missing and no
symlinks. The release that carries this code is built under the new rule, so
no separate publish-once step exists. `?serve-verify-key=1` answers 200 with
one key line on dev.

- **R1 — the early self-update block in `upgrade.php` is dead and the new
  check inside it is wrong.** `tar` is asked for `utils/upgrade.php` while the
  members are `./public_html/utils/upgrade.php`, so nothing is ever copied
  early; and `verifyListed()` is handed `utils/...` paths while the manifest
  lists `public_html/utils/...`, so it would refuse if the block ever ran.
  Delete the block; the post-extraction self-update is the one that works.
- **R2 — a crash mid-refresh leaves `plugins/<name>.refresh.<pid>`**, a
  plugin-shaped directory a filesystem sync can register. Set the old copy
  aside under a dot-prefixed name and sweep stale ones at the start.
- **R3 — recorded, not for this round:** `release_verify_keys` is append-only,
  so a compromised key cannot be retired from a node. Belongs with
  `per_channel_release_signing.md`.
- **R4 — for WP3:** `PackageAcknowledgement::check()` scans every `stepup`
  marker. Carry the marker row's id in the acknowledgement so root reads one
  row and compares its session hash.
- **R5 — for WP6:** `thm_trust` beside `plg_trust` is approved.
- Accepted as written: the transition-only load of the verifier from staging;
  `verify()` taking an optional key file; the exclusion rule living in core.

## Review round 2 (2026-09-12), STOP POINT 2

Verified by the reviewer: `php tests/run.php --changed` 21/21; the verifier
run with the real key over every live plugin and theme directory on
jeremytunnell (nine plugins, four themes) answers `signed` for all of them, so
the host-installer gate stops nothing on a real node. The existing step-up
pattern (redirect, lost POST, press again) is what the mailbox domain editor
does, so the warning page's two presses match the platform.

- **R1 — the converger honours the test hooks as root.** `JOINERY_VERIFY_PACKAGE`
  chooses the file `php` runs as root, `JOINERY_VERIFY_KEYS` skips the key
  file's ownership guard, `JOINERY_ACTIVE_PLUGINS` chooses whose installer
  runs. Nothing in a timer's environment sets them today, and that is one
  `env_keep` line away from not being true. Ignore all three when the
  effective uid is 0 and log one line saying so; the gate runs unprivileged
  and loses nothing.
- **R2 — a marketplace theme is marked `receives_upgrades = false`.**
  `install_extension_register()` sets it for every theme it registers,
  which was right when the by-name form only registered what was on disk
  and is wrong now that it fetches from the marketplace: the next upgrade
  would preserve a theme we ship. Set it only for the staged (uploaded)
  form.
- Accepted as written: the acknowledgement naming its marker row; the
  unsigned database half re-executed as the web user with a refusal rather
  than a root fallback; the `.refresh-` aside; the two new columns; the
  `upgrade_source` bound; the event-log row and the superadmin email.
- **WP8** runs after the release, on jeremytunnell, with the owner's say-so
  for the unsigned step, because that step emails every superadmin.

## Where things are (for the executor)

- Manifest writer and exclusions: `plugins/server_manager/includes/TreeManifestPublisher.php`
  (`write()`, `build()`, `$excluded_segments`, `$excluded_top_level`); test
  `plugins/server_manager/tests/tree_manifest_test.php`.
- Key files on the publisher: `AgentDistPublisher::ensureKeys()`; bundle
  `manifest.json` carries `signing_public_key`.
- Root request kinds and the no-package-kind comment: `includes/RootRequest.php`
  (`KINDS`, `NO_PACKAGE_KIND`); the runner is the host converger
  (`maintenance_scripts/install_tools/_plugin_installers_start.sh`, root, every
  tick; `read_active_plugins` and the `host_installer` loop near line 630).
- Root-side install: `utils/install_extension.php` (both forms; the staged copy
  into a root-owned working directory is already there — verify after it).
- Core upgrade download and deploy: `utils/upgrade.php` (`upgrade_source` read
  near line 281 and 521; manifest pair copied near line 1414 — verify before the
  swap, not after).
- Upload staging and validation: `includes/AbstractExtensionManager::stage()`;
  pages `adm/logic/admin_plugins_logic.php` (`action === 'upload'`) and
  `adm/logic/admin_themes_logic.php`.
- Stale pool-side install: `includes/MarketplaceClient.php::install()`,
  `logic/marketplace_install_logic.php`.
- Step-up gate: `SessionControl::require_recent_second_factor()`,
  `step_up_outstanding()`; doctrine in `docs/account_security.md` § Step-up
  confirmation (ask the outstanding-debt question, never `hasRecentStepUp()` alone).
- Health rows and notices: `includes/VaultHealth.php`, `includes/AdminNotices.php`
  (the `CertificateNotice` shape is the newest example).
- Settings declarations: `settings.json` (`upgrade_source` near line 1966;
  `validation` and `vault_gated` examples near lines 918, 1154).
- Tests: `tests/unit/installer_contract_test.php`, `tests/integration/host_converger_gate.sh`,
  `tests/security/` for the new verifier suite; run `php tests/run.php --changed`,
  then `php tests/run.php db --changed` before handoff.

## Related

- `security_inventory.md` S9 — the row this closes; B1 here is added to the
  inventory's findings as the reason S9 was larger than a plugin ZIP
- `plugin_tiers.md` — native = signed by our key, sandboxed = wasm; the
  unsigned PHP path here is neither tier and is the stopgap
- `per_channel_release_signing.md` — the second key WP1's file is shaped for
- `key_management_simplification.md` — where D1 Option 2's pin belongs
- `vault_key_memory_exposure.md` § Mitigation B — the original statement;
  countersigning withdrawn, the rest carried here
- `implemented/read_only_tree.md` § The root actor — why the kind was left
  out, and the sentence that said it comes back with S9
