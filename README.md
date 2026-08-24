# Joinery

**Self-hosted, encrypted email — on a server you own.**

Joinery runs your mail on your own machine: it receives mail directly (Postfix,
MX, DKIM, SPF/DMARC checks), stores every message sealed on disk, and unseals it
only while you're present and proven — a passkey, a recovery code, or a
passphrase. There is no provider reading your mail to sell you something, and no
subscription that ends with your archive held hostage.

Email is the flagship, but it sits on a full site platform: accounts and
profiles, subscription tiers, a store with Stripe and PayPal, files, calendars,
posts and photos, a private AI assistant, and a REST API. Run just the mail, or
run the whole thing.

- **Website:** <https://getjoinery.com>

---

## Encrypted mail, specifically

Each member gets an encryption identity — a keypair whose secret half never
touches the disk unwrapped. Incoming mail is sealed to that key on arrival.
Unlocking opens a bounded window; when it closes, the content is unreadable
again, including to the server process that stored it.

The same lock protects everything else that should be private: AI chat history,
protected conversations, private files in Drive, and the built-in password
manager. Drive's Fortress folders and the password manager go further — their
keys are unwrapped only in your browser, so the server never holds them at all.

- [Mailbox](public_html/plugins/mailbox/docs/overview.md) — inbound mail,
  domains, aliases, forwarding, DKIM/SRS, spam filtering
- [Sealed Vault](public_html/docs/sealed_vault.md) — the encryption identity,
  the unlock window, and how features plug into it
- [Passkeys](public_html/docs/passkeys.md) ·
  [Account Security](public_html/docs/account_security.md)
- [Drive Encryption](public_html/docs/drive_encryption.md)

## Also included

Members and permissions · subscription tiers and billing · a store with coupons,
orders and product requirements · events and registrations · Drive file storage
with sync clients · calendars · posts, pages and photo galleries · messaging and
social features · a local-model AI assistant · scheduled tasks · backups with
incremental chains · fleet management for multiple servers · native iOS and
Android apps.

Everything ships as a plugin or a core subsystem, so you activate what you want
and leave the rest off.

## Install

On a fresh Ubuntu 24.04 or 26.04 server, bare metal or Docker:

```bash
bash maintenance_scripts/install_tools/install.sh
```

The script handles the database, configuration, dependencies, SSL and the first
admin account. Never done this before? The
[Quick Start](public_html/docs/quickstart.md) walks through renting a server and
pointing a domain at it. For every option, see
[Installation](public_html/docs/installation.md).

**Requirements:** PHP 8.x with php-fpm, PostgreSQL, Apache with mod_rewrite,
Composer.

## For developers

Joinery is built to be extended. A **plugin** is a self-contained folder with its
own models, logic, views, admin pages, settings and menus — drop it in, activate
it, and its pages route automatically. A **theme** owns the look: override any
view or asset by placing a file of the same name in your theme, and leave the
rest to the platform.

```
public_html/
  serve.php          # Front controller — every request routes through here
  data/              # Models (Active Record; schema is defined in the class)
  logic/             # Business logic (LogicResult pattern)
  views/             # Templates
  adm/               # Admin interface
  includes/          # Core classes (autoloaded by name)
  theme/             # Themes
  plugins/           # Plugins, each with the same structure
  api/               # REST API
  migrations/        # Data migrations
```

Adding a page takes no route configuration: create `views/foo.php` and `/foo`
works. Add `logic/foo_logic.php` and it's wired in. Define a field in a model and
the column appears — schema follows the class, not a migration file.

Start here:

- [Plugin Developer Guide](public_html/docs/plugin_developer_guide.md) — plugins,
  themes, settings, menus
- [Theme Integration](public_html/docs/theme_integration_instructions.md)
- [Routing](public_html/docs/routing.md) ·
  [Logic Architecture](public_html/docs/logic_architecture.md) ·
  [Admin Pages](public_html/docs/admin_pages.md)
- [FormWriter](public_html/docs/formwriter.md) ·
  [Component System](public_html/docs/component_system.md) ·
  [Scaffolding](public_html/docs/scaffolding.md)
- [API](public_html/docs/api.md) · [Testing](public_html/docs/testing.md) ·
  [Deploy and Upgrade](public_html/docs/deploy_and_upgrade.md)
- [Full documentation index](public_html/docs/index.md)

## Contributions

Small fixes are welcome — bug fixes, documentation corrections, and similar
focused changes can come straight in as pull requests.

Large features generally can't be accepted into core. The right home for a
substantial feature is almost always a plugin or theme, which you own outright
and may license however you choose. If you believe something truly belongs in
core, open a discussion before writing code.

Joinery core ships under both a noncommercial and a commercial license, so by
submitting a pull request you agree that your contribution may be distributed
under both.

## License

Joinery core is source-available under the
[PolyForm Noncommercial License 1.0.0](LICENSE.md) with a Joinery Required Notice
and a Plugin and Theme Exception: free to run for personal and noncommercial
purposes, and free to build and distribute your own plugins and themes under
whatever license you choose.

Commercial use is licensed separately, under the
[Joinery Business License](LICENSE-BUSINESS.md) — one production instance,
unlimited users, lifetime updates, and use it for anything your business does
short of building a competing product. Bundled plugins and themes carry their
own `LICENSE.md`; a few (the store and server manager) are sold under their own
commercial terms. To buy a business license, see
[Joinery](https://getjoinery.com).
