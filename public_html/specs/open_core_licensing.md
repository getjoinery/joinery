# Open Core — Shield-Licensed Core, Paid Extensions, Entitled Delivery

**Status:** Unbuilt spec. Licensing decided: PolyForm Shield 1.0.0 core
(decision 1), `event_manager` free (decision 2), Server Manager paid under
the commercial license (decision 5), keys per-buyer with no activation
(decision 3), perpetual purchases with updates included (decision 4). All
five decisions resolved; exact price points are a launch-time call.
**Relationship to `specs/linode_quick_deploy_app.md`:** this spec resolves that
one's open decision #1 and deletes its licensing work. The Shield core makes
the Linode listing "free for your own use — source available", with no
eligibility for a reader to assess and no first-run declaration question. The
listing must not say "open source" — that phrase belongs to OSI-approved
licenses and Shield is not one.

## What this does for the user

Someone who wants to run a site — a club, a hobbyist, a small studio, a person
with an idea — installs Joinery and runs it as their own, personal or
commercial, with no eligibility to assess. The license forbids exactly one
thing: competing with Joinery itself — hosting it for others, rebranding it,
selling it as a product. Running your own site, including one that takes
money, is never a competing use. They pay us only when they want the platform
to take money for them, and at that point they are, by definition, a business
with revenue.

The current license asks the opposite: every prospective user must first
determine whether they qualify as noncommercial, and the ones who don't
qualify have nothing they can buy. That filter runs before anyone has seen the
product.

## The model

Core is free under PolyForm Shield 1.0.0: use, change, and redistribute for
any purpose except a product or service that competes with Joinery or with
what we sell using it. The extensions that make the platform earn — the
store (payments) and Server Manager (fleet operations) — stay commercial and
are sold through the marketplace. The paywall is a purchase, not a policy: no
usage nudges, no honor system, no phone-home, no compliance conversation.

This works because the architectural line already exists and was drawn months
ago. `LICENSE.md` already carries a **plugin and theme exception** stating that
work extending Joinery through its plugin system, theme system, base classes,
or APIs is not a derivative work and its authors may license it as they
choose. That exception cuts both ways: it lets third parties license their
plugins freely, and it lets us license ours restrictively.

### The boundary is already real

Verified in the tree, not assumed:

- `StripeHelper.php` and `PaypalHelper.php` live in `plugins/store/includes/`.
  Nothing in `includes/`, `logic/`, `data/`, `adm/`, `api/`, or `ajax/`
  references either.
- Orders, order items, cart charging, coupons, the billing catalog, and Apple
  IAP / Play Billing are all inside `plugins/store/`. Core has no cart, no
  order, no checkout, and no payment webhook.
- Core keeps tier *gating* only — `TierGatedContentRegistry`,
  `core_tier_features.json`, `data/subscription_tiers_class.php`, which has no
  price or payment field. **Core can lock content behind a tier; it cannot
  charge for one.**
- Server Manager is a plugin, so fleet operations sit on the paid side of the
  same line.

"The only way to take money in Joinery is the store plugin" is already true.
This spec is packaging, pricing, and delivery — not extraction.

### No grandfathering needed

There are no production users. Nothing has to be migrated, honored, or
converted; the model can be adopted whole rather than phased.

## Integration-point inventory

| Piece | Where | What |
|-------|-------|------|
| Core license | `LICENSE.md` | PolyForm Noncommercial 1.0.0 → PolyForm Shield 1.0.0. The plugin/theme exception and the Required Notice line carry over unchanged. |
| Per-plugin licenses | new `plugins/*/LICENSE.md` | Every first-party plugin carries its own license file: PolyForm Shield 1.0.0 for the free ones; commercial terms for `store` and `server_manager` (one production instance per purchase, staging/dev copies included, redistribution not granted). |
| Manifest declaration | `plugin.json` / `theme.json` schema | New `license`, `requires_entitlement`, and `status` fields. `plugin.json` has none of these today (`store/plugin.json` declares only `author`). The manifest is what makes a plugin's standing legible to the installer, the marketplace, and the admin UI. |
| System-plugin flag | `plg_plugins.plg_is_system`, plugin manifests | `store` and `event_manager` are the only flagged plugins today, and both lose the flag (owner decision 2026-07-30) — the plugin system-set becomes empty. Theme `is_system` flags are unaffected. See Build item 2. |
| Fresh-install plugin pull | `install.sh` `download_themes_and_plugins()` | With no `--themes`, it downloads **all system plugins**, so a fresh install would pull the paid store plugin for free. Must respect entitlement. |
| Download endpoint | `plugins/server_manager/includes/publish_theme.php` | The enforcement point. Today `?download=NAME&type=plugin` has **no permission check of any kind** — any anonymous caller can fetch any published plugin. |
| Update channel | `PluginManager::refreshFromUpstream()` | Already pulls plugin tarballs from `upgrade_source`. Gains a license key on the request. |
| Key entry | `/admin/admin_plugins` | Where a buyer pastes their key. The page already supports manual archive upload, which is the offline path. |
| Key issuance | getjoinery.com store | A license key is an ordinary product purchase. Issued on order completion, emailed, and shown in the buyer's profile. |
| Marketplace listing | getjoinery.com | Paid plugins listed with price and license; free ones unchanged. |

## Build items

### Phasing (owner, 2026-07-30): sell now, enforce later

Enforcement is deferred entirely. Phase 1 ships selling only: keys are
minted on purchase, emailed, and shown on the buyer's profile, and every
plugin carries its license file and manifest fields — but **no endpoint
checks a key anywhere**. Reason: dev releases are offered by pointing
`upgrade_source` at dev, and a download gate would break every unlicensed
site doing that. The one-instance grant is enforced by the commercial
license terms alone. With decision 4 (perpetual, updates included) there is
no lapsed state to police, so the gate currently protects nothing but the
first download.

Phase 1: build items 1, 2, 5, 6 and the key-minting parts of the tables
above. Deferred with the gate: build item 3 (the download check, the
security-release marker), build item 4's key entry (the status/badge display
still lands), the installer skip, the client key transmission, and the
gate-behavior tests. `requires_entitlement` still ships in the paid
manifests in phase 1 — declared now, read by nothing until the gate lands.

### 1 — Core license switch

Replace `LICENSE.md` with PolyForm Shield 1.0.0, keeping the plugin/theme
exception and the Required Notice line. Ship it inside the release archive,
which it currently is not: the archive's top level is only `./config`,
`./maintenance_scripts`, `./public_html`. Every install performed by the
published one-liner today lacks the license file entirely. Link it from the
admin footer or About surface.

Add a `LICENSE.md` to every first-party plugin: Shield for the free ones, the
commercial license for the store. The manifest `license` field (see
Integration-point inventory) must agree with the file.

### 2 — No more system plugins

`store` and `event_manager` are the only plugins with `is_system` in their
manifests, which makes `upgrade.php::get_system_required_extensions` always
download their files on upgrade whether or not the site takes plugin
upgrades. Owner decision 2026-07-30: **both lose the flag**, leaving the
plugin system-set empty. Plugin files arrive only when something asks for
them — the bundle installer on a fresh install, `PluginManager::install()`
on demand, or the entitled download path for the store — never as a side
effect of a core upgrade. (Theme `is_system` flags are a separate concern
and keep their meaning.)

- `store` and `server_manager` both declare `requires_entitlement` — the two
  commercial plugins gate identically.
- `event_manager` stays free (decision 2, resolved) and installs on demand;
  it is out of the `personal` bundle for marketing reasons, not pricing ones
  (`specs/linode_stackscript.md` settled the bundle).
- `download_themes_and_plugins()` skips plugins whose manifest declares
  `requires_entitlement` unless a key is supplied, and says so in its output
  rather than silently omitting them.

### 3 — Entitlement on the download endpoint

`publish_theme.php` gains a check on the `?download=` branch: if the requested
plugin's manifest declares `requires_entitlement`, require a valid license key
(header or parameter) tied to an active order, and 402 otherwise. Free plugins
and all themes keep serving anonymously — this endpoint is also how the
installer bootstraps, so an unconditional gate would break fresh installs.

The key is checked at **download and update time only**. State this in the
docs and mean it:

- No runtime enforcement, no license check on page load, no kill switch.
- An expired or lapsed license stops *updates*, never *function*. A plugin
  already installed keeps working indefinitely.
- Security fixes are never withheld. If a plugin update is security-relevant,
  it serves regardless of entitlement state.
- Manual archive upload stays available, so an air-gapped or offline
  deployment is never locked out of software it bought.

This is the trust-preserving shape: the key proves a purchase happened, it
does not police the buyer afterward.

### 4 — Key entry and status in admin

`/admin/admin_plugins` grows a license key field and shows, per plugin,
whether it is free, entitled-and-active, or entitled-and-lapsed. A lapsed
plugin shows "updates paused" — never a warning banner implying the site is
broken, because it isn't.

### 5 — Purchase flow on getjoinery.com

The license is a store product. Order completion issues a key, emails it, and
lists it on the buyer's profile alongside their other purchases. Purchases
are perpetual with updates included (decision 4) — there is no renewal
product for now, and if one is ever introduced it applies only to future
buyers; lapsing, when it exists, revokes update access only. Keys are per-buyer
with no activation (decision 3), so checkout collects nothing beyond the
purchase itself — no deployment domain, no site registration. Checkout and
the emailed key both state the grant: one production instance per purchase,
staging and dev copies included; a second production site is a second
purchase.

### 6 — Marketplace listing metadata

Plugins list with price, license, and maturity status read from the manifest.

**Maturity status.** A `status` field in `plugin.json`: one of
`experimental`, `beta`, `stable`, `deprecated`. Absent means `stable` and
renders no badge; any other value renders a badge wherever the plugin is
listed — the marketplace on getjoinery.com and `/admin/admin_plugins`
(both the installed list and anything offered for install). An unknown value
is a manifest validation error, not a silently ignored string. Status is
honest labeling only — it gates nothing: an `experimental` plugin installs,
activates, and updates exactly like a `stable` one.

Initial assignments (owner, 2026-07-30): `mailbox` — `beta`; `vault` —
`experimental`. `event_manager` is de-emphasised, not deprecated, and
carries no badge. Calendar and Drive are core features with no manifest, so
plugin status cannot label them — their beta framing belongs in product
copy, not this mechanism. This is the whole
near-term marketplace: our own extensions, sold through a channel that already
delivers them. Third-party revenue sharing, payouts, and submission review are
explicitly **not** in scope — that is a chicken-and-egg problem that cannot be
won before there are users.

## What deliberately does not get built

Recorded so these do not creep back in:

- **No usage nudges, no first-run "are you a business" declaration, no
  commercial-signal prompts.** The purchase boundary replaces all of it.
- **No phone-home.** Nothing today reports an install's identity upstream —
  the upgrade check is a pull that compares versions locally — and this spec
  keeps it that way. The only outbound identity is a license key presented
  when downloading a plugin the user bought.
- **No third-party marketplace economics.**
- **No attribution badge on public-facing sites.**

## Accepted risks

**A free third-party payments plugin is a gray area, not a right.** The
plugin/theme exception lets a third party license their own plugin as they
choose, but Shield's noncompete restricts using the *core* in or for a product
that competes with something we sell using it — and a payments plugin competes
with the store. Do not rely on that reading: price the store extension where
reimplementing it is not worth anyone's weekend, and let its depth — multiple
providers, coupons, product requirements, subscriptions and tier billing,
mobile billing, refunds — be the reason to buy.

**"Open source" is a phrase we give up.** Shield's noncompete forbids the two
uses the owner ruled out — selling Joinery hosting and selling a rebranded
Joinery — but it makes the license source-available, not open source.
Listings and docs say "free for your own use — source available", and the
fork-averse part of the developer audience will notice the difference.
Accepted with eyes open.

**A paid plugin's source is readable by its purchaser.** PHP ships as source;
there is no obfuscation and none should be attempted. Redistribution is what
the commercial license forbids, and that is the enforceable part.

## Testing

Phase 1:

- `safe` tier: manifest parsing of `license`, `requires_entitlement`, and
  `status` (unknown status values rejected); no key value is ever written to
  a log or echoed.
- `db` tier: order completion mints a key, records it against the buyer,
  order, and plugin name, and it appears on the buyer's profile; the
  download endpoint still serves every plugin anonymously — asserted
  deliberately, so the future gate lands as an intentional behavior change,
  not an accident.
- Live gate: a fresh install from the public one-liner comes up with core
  and the `personal` bundle, no store, and a clear path to buy; a real
  purchase on getjoinery.com delivers a key by email.

Deferred with the gate: 402 on keyless entitled downloads, valid-key serve,
security-flagged releases serving regardless of entitlement, lapsed-key
behavior, and the installer skip-and-report.

## Documentation

- `docs/plugin_developer_guide.md` — the `license`, `requires_entitlement`,
  and `status` manifest fields, and what the plugin/theme boundary means for
  third-party authors under the Shield core.
- `docs/deploy_and_upgrade.md` — deferred with the gate: entitled plugin
  delivery, key handling, and the guarantee that updates are gated but
  function never is. (Docs describe current state only, so nothing is
  written about the gate until it exists.)
- `plugins/store/docs/overview.md` — the store is a commercial extension;
  buying it issues a license key by email and on the buyer's profile.
- `docs/quickstart.md` and `docs/installation.md` — corrected licensing
  language; the fresh install no longer implies the store is included.

## Open decisions

1. *Resolved 2026-07-30: PolyForm Shield 1.0.0.* The owner is not ready to
   grant irrevocable permissive rights, and wants two uses foreclosed
   outright: selling Joinery hosting, and selling a rebranded Joinery.
   Shield's noncompete covers both in one clause, and running your own site —
   commercial or not — is not a competing use, so the target customer is
   unaffected. Rejected: MIT/Apache (irrevocable grant to everyone forever);
   Elastic License 2.0 (permits redistribution as long as notices stay
   intact, so rebrand-and-sell is only thinly blocked); FSL/BUSL
   (auto-convert to Apache after two years — a delayed version of the grant
   the owner declined); PolyForm Small Business (reintroduces an eligibility
   test, the thing this spec exists to remove).
2. *Resolved 2026-07-30: `event_manager` stays free (Shield) and available on
   demand.* It is out of the default install bundle for marketing reasons —
   `specs/linode_stackscript.md` settled the bundle — not pricing ones.
3. *Resolved 2026-07-30: per-buyer key, no activation; the license grants
   one production instance per purchase.* The key identifies a purchase,
   nothing more: the same key works on a staging copy, a rebuild, a moved
   server. There is no activation step, no install registry, no seat count —
   the only check anywhere is the download/update endpoint verifying the key
   maps to an active order. The one-instance scope lives in the commercial
   license terms and is stated plainly at checkout: a second production site
   is a second purchase. Enforced by the terms alone — honor system by
   design. Owner's stance for this stage: anyone buying anything is the win;
   friction against sharing is not worth friction against buyers.
4. *Resolved 2026-07-30 (model): perpetual, updates included.* No renewal
   product and no update window for now — a purchase includes updates
   indefinitely, deliberately generous as an early-buyer term (updates cost
   nothing to serve). The entitlement machinery still supports a lapsed
   state, so a paid-renewal model can be introduced later for *future*
   buyers without touching delivery — early buyers keep what they were
   sold. Exact price points are a launch-time call, deliberately not fixed
   here; expect modest numbers (the owner's stated stance: anyone buying
   anything is the win).
5. *Resolved 2026-07-30: Server Manager stays paid, under the same
   commercial no-redistribution license as the store.* Shield was rejected
   for it because Shield permits redistribution, which undermines charging.
   The owner expects low sales volume — its value is as the hosting moat and
   the fleet product, not as a revenue line, and pricing (decision 4) should
   not assume meaningful demand.
