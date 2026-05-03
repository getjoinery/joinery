---
# Content Pack Feature

## Problem

Joinery's "content" lives in two places: theme files (filesystem) and DB rows (settings, pages,
products, pricing tiers, navigation). This split means there is no lightweight way to snapshot,
share, or restore a specific site configuration. Preserving a site's look+content today requires
either a full DB backup (heavy, not portable) or manually reconstructing the DB state from scratch.

This is a recurring friction point when:
- Pivoting a deployment to a new audience (the original org-facing getjoinery.com content needs
  to be preserved when the site is rebuilt for developers)
- Standing up a new sister-brand deployment that should start from a known content baseline
- Sharing a "starter kit" with a new Joinery operator
- Demoing the platform with realistic content that looks like a real site

## Proposed Solution: Content Packs

A **content pack** bundles a theme with a declarative DB content manifest. Packing and unpacking
is a first-class platform operation.

### Pack structure

```
theme/mytheme/
  theme.json
  assets/
  views/
  ...
  content/
    pack.json          ← manifest: version, description, dependencies
    settings.json      ← stg_settings rows to seed (name → value pairs)
    pages.json         ← page/post content rows (if page system exists)
    products.json      ← products and pricing tiers
    navigation.json    ← nav items (if stored in DB)
```

The `content/` directory is optional — a theme without it is just a theme. A theme with it is
a content pack.

### Import behavior

On import (theme install or explicit "apply content pack" action):
- Theme files are copied as normal
- Each content file is processed by a content seeder
- Seeder uses upsert semantics: creates rows that don't exist, skips rows that do
  (so importing a pack onto an existing site with customized settings doesn't clobber them)
- An "overwrite" flag forces replacement of existing rows (useful for demo resets)

### Export behavior

Admin action: "Export content pack" on the Themes admin page.
- Packages current theme files
- Dumps relevant DB content to the `content/` directory JSON files
- Produces a downloadable `.zip`

### Content scope

What goes in a content pack vs. what stays deployment-specific:
- **In pack:** settings that describe the site's identity and copy (site_name, tagline,
  og_description, marketing copy settings, nav structure, tier features copy, product names/prices)
- **NOT in pack:** credentials, API keys, Mailgun config, Stripe keys, per-deployment secrets,
  user data, transactional data

### Migration system interaction

Content packs are NOT migrations. Migrations run automatically as part of `upgrade.php` and are
for schema/data changes that must apply to every deployment. Content packs are opt-in, operator-
controlled, and represent a site's configuration baseline — not system evolution.

## Open Questions

1. **Conflict resolution UI** — when importing onto an existing site, which settings already have
   non-default values? Should the importer show a diff before applying?

2. **Versioning** — pack format version vs. Joinery version compatibility. Should `pack.json`
   declare a minimum Joinery version?

3. **Plugin content** — plugins can declare their own settings. Should plugin-owned settings be
   exportable in a pack, or is that the plugin's responsibility?

4. **Page/post system** — the current platform has minimal CMS-style page storage. Content packs
   may be more valuable once that system is more developed.

5. **Hosted starter kits** — longer term, could getjoinery.com list downloadable starter packs
   (org starter, developer framework starter, etc.)? This feature would enable that.

## Immediate Use Case (trigger for this spec)

getjoinery.com is being pivoted from org-facing to developer-facing. The current org-focused
theme + content needs to be preserved. Short-term: spin up `orgs.getjoinery.com` as a sister
deployment before the pivot (see sister_brand_deployment.md pattern). Longer-term: the content
pack feature would let the org site be exported as a reusable starter kit.

## Related Specs

- `specs/implemented/multiple_domain_capability.md` — sister-brand deployment pattern
- `specs/sister_brand_deployment.md` — NetworkSentry deployment runbook
