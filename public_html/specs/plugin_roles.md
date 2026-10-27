# Plugin Roles — Spec

**Status:** Draft 1, 2026-10-27. For thinking through; not scheduled. Open
questions at the bottom (Q1–Q5).

## What this is for

Today a plugin can require another plugin only by its exact folder name. Events
requires `store`, so if someone writes a better store, events will not accept
it, and the site has to run ours or go without events. That makes mix-and-match
impossible. A third-party plugin can add something new, but it can never stand
in for one of ours.

The goal: a plugin can say "I fill the store role" (or the email lists role),
and every plugin that needs that role accepts it. The site owner picks which
plugin fills each role.

Saying it is the easy half. The hard half is that "fills the store role" has to
be a promise someone can keep: a written list of what other code may ask of
the role, which any plugin filling it must answer (§3.4). Without that, a
substitute passes the requirement check and then crashes the first time events
calls a store class it doesn't have.

**Not in scope:**
- **Things a site runs several of at once.** Payment gateways, fulfillment
  types and email audiences already have registries that take any number of
  providers (§2.5). A role is for a job exactly one plugin does at a time.
- **Moving data from one provider to another.** That is the providers'
  business. This spec only makes the switch possible (§3.3).

## 1. How requirements work today

- **`depends` matches a plugin's exact name.** Activation looks up the
  dependency by name, requires it to be active, and checks the version
  constraint against that plugin's own version
  (`includes/PluginManager.php:736-767`). `conflicts` is also by name
  (`:769-778`).
- **Five plugins declare `depends`:** `dns_filtering`, `bookings` and
  `event_manager` require `store`; `mail_apps` requires `mailbox`;
  `support_desk` requires `joinery_ai`.
- **`provides` does nothing.** The developer guide says so
  (`docs/plugin_developer_guide.md:327-335`). The only code that reads it
  refuses a plugin that lists `"theme"` (`includes/PluginHelper.php:174`). No
  real `plugin.json` declares it.
- **A plugin others depend on can't be switched off or removed.** Deactivation
  and uninstall both ask `getDependents()` (`PluginManager.php:1171-1174`,
  `:1392-1394`, `:1916`). B1 and B2 (§6) were in this check.
- **Themes require plugins by name.** `requires_plugins` in `theme.json` is
  checked when a theme is switched on (`includes/ThemeManager.php:216-230`,
  `adm/logic/admin_settings_logic.php:44-60`). It also blocks switching off a
  plugin the active theme needs (`PluginManager.php:1153-1167`).
  `phillyzouk-html5` and `devonnearhill-html5` use it.
- **Sandboxed plugins have no `depends` at all.** Their manifest refuses any
  key it doesn't list (`docs/sandboxed_plugins.md:86`). What a sandboxed plugin
  needs is stated as API actions, model classes and signals, and install
  refuses it when the site lacks one (`includes/SandboxCompatibility.php:26-80`).
  That is already a requirement on a contract rather than on a plugin. Any
  plugin that registers the action satisfies it.
- **A same-named substitute can't even be installed.** The class and table
  collision scan compares a new plugin's data classes against every other
  plugin on disk, active or not (`PluginManager.php:781-830`). A replacement
  store that reused `Product` and `pro_products` is refused while ours is in
  the tree. Every site ships ours in the tree.

## 2. How plugins reach into each other today

This is what "compatible" would have to cover. There are five ways, counted
outside tests:

1. **"Is plugin X on?" checks.** There are 43 `isPluginActive('x')` or
   `get_by_plugin_name('x')` checks:
   - Store: 6 in core, 5 in other plugins.
   - Events: 5 in core, 3 in other plugins.
   - Mailbox asks about `joinery_ai` 7 times and `server_manager` 6 times.
2. **Core routes handed to a named plugin.** Core `serve.php` has 29 routes
   tagged `'plugin' => 'x'`, such as `/cart`, `/product/{slug}` and
   `/event/{slug}`: 13 for store and 12 for events. `RouteHelper` 404s the URL
   when that plugin is off and serves the view from its folder
   (`includes/RouteHelper.php:517-532`).
3. **"Does class X exist?" checks.** There are 109 `class_exists('X')` checks
   on plugin class names, 36 of them in core (CLAUDE.md names this the truthful
   "is this feature here?" test). They answer for one plugin's class names
   only.
4. **Direct calls into another plugin's models.** Five files in events,
   bookings and ScrollDaddy construct store models (`Product`, `Order`,
   `OrderItem`, `ShoppingCart`).
5. **Stored ids that point into another plugin's tables.**
   - Event registrations keep an order id and an order item id
     (`plugins/event_manager/data/event_registrants_class.php:56-57`).
   - Bookings keep a product id (`plugins/bookings/data/bookings_class.php:31`).

### 2.5 The seams that already work

There are a few registries where one side never names the other:
- **`FulfillmentRegistry`.** The store never learns that events exist. Events
  registers an `event_registration` provider (`plugins/store/includes/FulfillmentRegistry.php`,
  `plugins/event_manager/serve.php:73`).
- **`RecipientGroupProviderRegistry`.** Events adds its audiences to it
  (`plugins/event_manager/serve.php:44-45`).
- **The `MailingListProvider` interface.** MailChimp plugs in behind it
  (`includes/mailing_list_providers/MailingListProvider.php:12`).

These are the right model for a role's contract. They have one limit:
`FulfillmentProvider` methods take a `Product $product`
(`FulfillmentRegistry.php:35`, `:58`). Even the cleanest seam names the store's
own model class, so a substitute store could not hand events anything events
would accept. A role's contract has to avoid that (§3.4).

## 3. Proposal

### 3.1 A role has a name and a version

A plugin lists the roles it fills in `plugin.json`, each with the version of
the role's contract it meets:

```json
"provides": { "email_lists": "1.0" }
```

- **Our plugin declares its own role.** `email_lists` provides `email_lists`.
- **A role is named after the plugin that first filled it.** A substitute named
  `better_lists` declares `"provides": {"email_lists": "1.0"}`.
- **`provides` changes from an informational list to this object.** No real
  plugin uses the list form, so nothing breaks. The theme check at
  `PluginHelper.php:174` moves with it.
- **The role's version is the contract's, not the plugin's.** It goes up only
  when the contract changes. Additions are free; a breaking change raises the
  major number. This is the rule sandboxed plugins already have for the
  platform API (`docs/sandboxed_plugins.md` § Compatibility).

### 3.2 Needing a role is a different statement from needing a plugin

Here a dependent says what it needs:

```json
"needs":   { "email_lists": ">=1.0" },
"depends": { "mailbox": ">=1.139.0" }
```

- **`needs`:** any active plugin that fills this role at a matching contract
  version. "I use only the contract."
- **`depends`:** this exact plugin, version-checked against its own version.
  "I reach into its internals." That is what it means today, unchanged.

Keeping the two apart says, in the manifest, which dependents survive a swap
(Q1).

### 3.3 One provider per role at a time, and a way to swap

- **A second provider is refused.** Activating a second plugin that fills an
  already-filled role is refused, naming the plugin that fills it now. This
  works like a built-in `conflicts`.
- **A role's provider stays on while anything needs it.** Switching it off is
  refused while an active plugin `needs` the role. This mirrors today's
  `depends` rule (§1).
- **Swapping needs its own action.** Without one, the swap deadlocks: B can't
  be switched on while A fills the role, and A can't be switched off while
  events needs the role. **Switch provider** on the Plugins page switches A
  off and B on in one transaction. If B's activation fails, everything rolls
  back and A keeps the role.
- **Themes use `needs` too.** `theme.json` takes `needs` beside
  `requires_plugins`, checked at the same three places (§1).
- **Core routes go to the provider.** A route tagged with a role name is served
  by whichever plugin fills it now (the `RouteHelper.php:517-532` lookup). The
  contract lists the views a provider must ship for those URLs.

### 3.4 The contract

For each role, core holds:
- **An interface** listing what other code may ask of the role.
- **A doc page** saying what each method promises, and what any id the role
  hands out means. Other plugins store those ids (§2, item 5), so "an order id
  this role can look up later" is part of the promise.

Callers ask for the role, not a plugin:

```php
$lists = PluginRoles::get('email_lists');   // null when nothing fills it
if ($lists) { $lists->subscribe($user_id, $list_id); }
```

That call replaces both `isPluginActive('email_lists')` and
`class_exists('MailingList')` at each caller that uses the role. The provider
registers its implementation when it loads, the way events registers its
fulfillment provider today.

The rules that make the contract hold:
- **Contract types only.** Contract methods take and return plain values and
  types the contract itself defines, never a provider's model class. This is
  the `Product $product` limit from §2.5.
- **A `needs` dependent touches only the contract.** A test scans each plugin
  that `needs` a role for direct use of the original provider's class names,
  and fails the gate on one. A plugin that really does need the internals
  declares `depends` on that exact plugin instead, and the manifest says so.
- **Narrow contracts.** A contract holds what dependents actually call today,
  not the whole plugin. The extension points in `email_lists_plugin.md` §4 are
  most of the email lists contract already.

### 3.5 Which roles

Most plugins never need a role. Mailbox, server manager and the AI plugin are
ours to keep, and depending on them by name is fine. Candidates where a
substitute is plausible:

| Role | Who needs it today | Size of the contract |
|---|---|---|
| `email_lists` | events (audiences, the waiting-list newsletter box), store (the newsletter requirement), core signup and profile pages | Small. It is being cut out fresh (`email_lists_plugin.md`), so the contract and the plugin are drawn together. |
| `store` | events, bookings, ScrollDaddy, mailbox, core admin pages and 13 routes | Large. 3 plugins construct store models directly, and 2 store order and product ids. |
| `events` | store, mailbox, the calendar feed, the site directory, 12 routes | Medium. |

Recommendation (Q2): build the mechanism with `email_lists` as its only role,
and draw store's contract when a real substitute author shows up.

## 4. Sandboxed plugins

Outside authors will most likely write sandboxed plugins (spec `plugin_tiers`).
A sandboxed plugin can't implement a PHP interface, and its manifest has no
`needs`. Its requirements are already contract-shaped (§1). Two ways forward
(Q3):

- **Native roles only, for now.** A sandboxed plugin can't fill a role or need
  one. Catch: a third-party email lists replacement would have to be native
  code, which only installs behind the unsigned-package warning.
- **Contracts stated as API actions plus signals**, so a sandboxed plugin can
  fill a role through its host and can `need` one in its manifest. Catch:
  every contract call becomes a call into the plugin host, with the host's
  few-second limit, so a role that sits on a page's main path, like the store's
  cart, would be slow.

## 5. What changes, by file

- `includes/PluginManager.php`:
  - Read `provides` and `needs` in validation.
  - Refuse a second provider of a role.
  - Extend `getDependents()` to cover `needs`.
  - Add the swap operation.
- `includes/PluginRoles.php` (new): register and look up providers.
- `includes/roles/` (new): one interface per role.
- `includes/RouteHelper.php`: a route tagged with a role name goes to its
  provider.
- `includes/ThemeManager.php`, `adm/logic/admin_settings_logic.php`: theme
  `needs`.
- `includes/PluginHelper.php:174`: the theme check moves to the new `provides`
  form.
- `adm/admin_plugins.php` and its logic:
  - Show which plugin fills each role.
  - Add the **Switch provider** action.
- **Retire the stored dependency table.** Since B1, nothing reads it:
  `data/plugin_dependencies_class.php`, `storeDependencies()`
  (`PluginManager.php:873`) and its cleanup in uninstall. Dropping the table is
  an `update_database` step.
- Tests:
  - A role's resolution, the one-provider rule, the swap and its rollback.
  - The `needs`-touches-only-the-contract scan.

## 6. Bugs found while mapping this

- **B1 (fixed, uncommitted).** A plugin's requirements were recorded only when
  it was switched on, so a requirement added in a later upgrade was never
  recorded. On dev the record held two of the five: nothing showed that
  events, bookings or ScrollDaddy need the store. So nothing stopped the store
  being switched off or uninstalled underneath them. `getDependents()` now
  reads each installed plugin's current `plugin.json`, the way the version
  check already reads live manifests (`PluginManager.php:752-758`).
  `PluginManager` 1.17. The developer guide now states the rule.
- **B2 (fixed, uncommitted).** Switching a plugin off was blocked even by
  plugins that need it but are themselves off, against the code's own comment
  ("other active plugins"). An inactive plugin runs nothing, and its own
  activation refuses while what it needs is off. Deactivation now counts only
  active dependents. Uninstall still counts every installed one, whose stored
  ids may point at the tables uninstall drops.

## 7. Docs

- `docs/plugin_developer_guide.md`:
  - `provides` and `needs`.
  - The one-provider rule.
  - **Switch provider**.
  - How to write a plugin that fills a role.
- **One doc per role**, beside its interface: the contract, what its ids mean,
  and the views its routes need.
- `docs/sandboxed_plugins.md`: only if Q3 lets sandboxed plugins take part.

## 8. Build order

1. Read `provides` and `needs`, the one-provider rule, the theme `needs`, and
   their tests.
2. `PluginRoles` plus the route lookup.
3. The **Switch provider** action and its rollback test.
4. The first contract (`email_lists`), built with that plugin.
5. The scan test for `needs` dependents.
6. Retire the stored dependency table.

## Open questions

- **Q1. One key or two for requirements?**
  - **Two keys, `needs` and `depends` (recommended).** The manifest says which
    dependents survive a swap. Today's five `depends` keep their meaning.
    Catch: authors learn two words.
  - **One key**, where `depends` on a role name means any provider. Catch: the
    same line changes meaning the day the original plugin starts declaring its
    role, and a dependent that needs our plugin's internals can no longer say
    so.
- **Q2. Which role first?**
  - **`email_lists` only (recommended).** Its contract is small and drawn
    along with the plugin. Catch: the mechanism is proven on one role.
  - **Also store.** Catch: store's contract is large, and would be designed
    without a real substitute to test it against.
- **Q3. Can sandboxed plugins fill or need a role?** See §4.
  - **Recommended: native only to start**, with contracts kept to plain values
    so the action-and-signal form can follow without redesign.
- **Q4. Can a free plugin fill a paid role?** A free substitute for our paid
  `email_lists` would take that revenue.
  - **Yes (recommended).** That is the point of mix-and-match. The
    included-mail check lives in core (`email_lists_plugin.md` §5), so it holds
    for any provider. Catch: our paid plugin has to be worth paying for.
  - **No:** a paid role accepts only signed providers. Catch: it contradicts
    the reason for roles.
- **Q5. Where do role contracts live?** In core `includes/roles/`
  (recommended), because a contract has to outlive the plugin that first filled
  it. Catch: core grows an interface per role, though not the feature itself.
