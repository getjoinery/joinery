# Open Core — Permissive Core, Paid Extensions, Entitled Delivery

**Status:** Unbuilt spec. Two owner decisions block phase 1 (see Open
decisions); everything else is specified.
**Relationship to `specs/linode_quick_deploy_app.md`:** this spec resolves that
one's open decision #1 and deletes its licensing work. A permissive core makes
the Linode listing "open source, free" with no license line, no BYOL, and no
first-run declaration question.

## What this does for the user

Someone who wants to run a site — a club, a hobbyist, a small studio, a person
with an idea — installs Joinery and owns it outright, with no license to read,
no eligibility to assess, and no question about whether their use is allowed.
They pay us only when they want the platform to take money for them, and at
that point they are, by definition, a business with revenue.

The current license asks the opposite: every prospective user must first
determine whether they qualify as noncommercial, and the ones who don't
qualify have nothing they can buy. That filter runs before anyone has seen the
product.

## The model

Core is permissive and free. The extensions that make the platform earn — the
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
| Core license | `LICENSE.md` | Replaced with the chosen permissive license. The plugin/theme exception becomes unnecessary for permissive text and is dropped from core. |
| Commercial license | new `plugins/store/LICENSE.md`, `plugins/server_manager/LICENSE.md` | Each paid plugin carries its own commercial terms: use permitted for the purchaser, redistribution not granted. |
| Manifest declaration | `plugin.json` / `theme.json` schema | New `license` and `requires_entitlement` fields. `plugin.json` has neither today (`store/plugin.json` declares only `author`). The manifest is what makes a plugin's status legible to the installer, the marketplace, and the admin UI. |
| System-plugin flag | `plg_plugins.plg_is_system`, plugin manifests | `store` and `event_manager` are both flagged system today. A paid plugin cannot be a system plugin — see Build item 2. |
| Fresh-install plugin pull | `install.sh` `download_themes_and_plugins()` | With no `--themes`, it downloads **all system plugins**, so a fresh install would pull the paid store plugin for free. Must respect entitlement. |
| Download endpoint | `plugins/server_manager/includes/publish_theme.php` | The enforcement point. Today `?download=NAME&type=plugin` has **no permission check of any kind** — any anonymous caller can fetch any published plugin. |
| Update channel | `PluginManager::refreshFromUpstream()` | Already pulls plugin tarballs from `upgrade_source`. Gains a license key on the request. |
| Key entry | `/admin/admin_plugins` | Where a buyer pastes their key. The page already supports manual archive upload, which is the offline path. |
| Key issuance | getjoinery.com store | A license key is an ordinary product purchase. Issued on order completion, emailed, and shown in the buyer's profile. |
| Marketplace listing | getjoinery.com | Paid plugins listed with price and license; free ones unchanged. |

## Build items

### 1 — Core license switch

Replace `LICENSE.md` with the chosen permissive license (Open decision 1).
Ship it inside the release archive, which it currently is not: the archive's
top level is only `./config`, `./maintenance_scripts`, `./public_html`. Every
install performed by the published one-liner today lacks the license file
entirely. Link it from the admin footer or About surface.

### 2 — "System" must stop meaning "free"

`store` and `event_manager` are both `plg_is_system = true`, and system
plugins are auto-pulled at install. Two changes:

- `store` loses its system flag and becomes an entitled plugin.
- `event_manager` — Open decision 2. It is a system plugin today and is not a
  payments extension, so on the face of it, it stays free and system. Confirm
  rather than assume, because it is the other plugin the flag currently
  touches.
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
lists it on the buyer's profile alongside their other purchases. Renewal is a
subscription product; lapsing revokes update access only. Use Product
Requirements to collect the deployment domain at checkout if we want the key
scoped to a site — Open decision 3.

### 6 — Marketplace listing metadata

Plugins list with price and license read from the manifest. This is the whole
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

**A free third-party payments plugin is legal and possible.** With a permissive
core, nothing stops someone writing one, and nothing should. This is a pricing
problem, not a legal one: price the store extension where reimplementing it
is not worth anyone's weekend, and let its depth — multiple providers,
coupons, product requirements, subscriptions and tier billing, mobile billing,
refunds — be the reason to buy.

**A permissive core means anyone can host Joinery for others.** Accepted
deliberately: the hosting moat is Server Manager's fleet tooling, which is a
plugin and stays commercial. If the owner would rather protect hosting in the
license itself, that is Open decision 1's alternative and it costs the
permissive-license adoption effect that motivates this whole change.

**A paid plugin's source is readable by its purchaser.** PHP ships as source;
there is no obfuscation and none should be attempted. Redistribution is what
the commercial license forbids, and that is the enforceable part.

## Testing

- `safe` tier: manifest parsing of `license` and `requires_entitlement`;
  `download_themes_and_plugins()` skips entitled plugins without a key and
  reports the skip; no key value is ever written to a log or echoed.
- `db` tier: the download endpoint serves free plugins anonymously, 402s an
  entitled plugin with no key, serves it with a valid key, and serves a
  security-flagged update regardless of entitlement state.
- `db` tier: a lapsed key blocks update download and does not affect an
  installed plugin's activation or function.
- Live gate: a fresh install from the public one-liner comes up with core and
  free plugins, no store, and a clear path to buy; then a purchased key pulls
  the store plugin and checkout works end to end.

## Documentation

- `docs/plugin_developer_guide.md` — the `license` and `requires_entitlement`
  manifest fields, and what the plugin/theme boundary means for third-party
  authors now that core is permissive.
- `docs/deploy_and_upgrade.md` — entitled plugin delivery, key handling, and
  the guarantee that updates are gated but function never is.
- `plugins/store/docs/overview.md` — the store is a commercial extension;
  where the key goes.
- `docs/settings.md` — the license key setting, if the key lands in settings
  rather than in its own store.
- `docs/quickstart.md` and `docs/installation.md` — corrected licensing
  language; the fresh install no longer implies the store is included.

## Open decisions

1. **Which permissive license for core** — MIT/Apache-style (maximum adoption,
   hosting commoditized) or source-available with a hosting restriction (keeps
   the hosting line, loses the permissive-license adoption effect). This is the
   decision the rest of the spec hangs from.
2. **Does `event_manager` stay free and system?** It is not a payments
   extension, so the default answer is yes, but it is the other system-flagged
   plugin and the call should be explicit.
3. **Is a key scoped to one deployment, or to a buyer?** Per-buyer is simpler
   and friendlier — the same key works on a staging copy and a rebuild.
   Per-deployment is tighter but produces support tickets every time someone
   reinstalls. Recommendation: per-buyer.
4. **Price for the store extension**, and whether it is perpetual-with-updates
   or subscription. Perpetual-with-a-year-of-updates matches the "never breaks
   what you bought" stance most cleanly.
