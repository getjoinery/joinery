# Provisioning Activation Page — One-Click Pipeline Setup

**Status:** Implemented 2026-07-18. Built on dev, 36-check db suite +
safe tier green, page live-verified on dev. First real use is the step-4
activation on getjoinery.com in
`specs/new_site_deployment_fortress_verification.md`.

## Why

Activating the hosting provisioning pipeline was a manual checklist
(`specs/automated_hosting_provisioning_setup.md`): create a service user by
hand, generate an API key by hand, paste values into settings, create a
Question, activate three scheduled tasks. The deployment program's doctrine
is **nothing manual unless it is a one-off** — any future operator (or
control plane) activating provisioning should click through a guided page,
not follow a document. The activation of getjoinery.com itself becomes the
first test of the automated path.

## What was built

**Server Manager → Provisioning** (`/admin/server_manager/provisioning_setup`,
permission 10, admin menu slug `server-manager-provisioning`): a status page
where every pipeline requirement shows a live badge and every automatable
step is a one-click, idempotent action. The actions live in an engine class
so they are testable without a session:

- `plugins/server_manager/includes/ProvisioningSetup.php` — the engine
- `plugins/server_manager/logic/admin_provisioning_setup_logic.php` — POST
  actions (post-redirect-get with session messages) + status render
- `plugins/server_manager/views/admin/provisioning_setup.php` — the page

### Sections / actions

1. **Store API connection** — detects the self-store case (API URL is this
   site's own origin). One click: creates the service user
   (`provisioning@<host>`), mints a machine API key, writes
   `server_manager_getjoinery_api_url` / `..._api_public_key` /
   `..._api_secret_key`. A loopback probe badge calls the API with the
   stored credentials exactly as Poll Hosting Orders does (an empty result
   set counts as success). Rotation is a button that retires the user's
   previous pipeline keys. Remote-store case: the key is minted on the store
   site and pasted into the settings; the page states this.
2. **Domain question** — creates the required short-text Question and writes
   `server_manager_provisioning_domain_question_id`; lists the products the
   question is attached to (attachment is what makes a product a hosting
   product, so it stays per-product).
3. **Emails** — welcome from address/name + admin alert address, edited in
   place (FormWriter).
4. **Scheduled tasks** — activates Poll Hosting Orders, Provision Pending
   SSL, Provision Customer Cloud: creates missing `sct_` rows
   (`every_run`), resumes paused ones.
5. **Shared-host fulfillment** — status only: count of provisioning-enabled
   hosts, link to the dashboard host edit.
6. **Customer-cloud fulfillment** — Linode OAuth configured badge (links to
   OAuth Providers), SSH key path with key/.pub existence badges, referral
   URL, region/type/image defaults (FormWriter).
7. **Products** — the remaining per-product manual steps, stated inline.

## Decisions

- **Service user permission is 5, not 3.** The API resolves
  `current_user_permission` from the user account's `usr_permission`, and
  the default model read authorization is owner-or-permission-≥5. The
  pipeline reads other buyers' order items/requirements, so a permission-3
  service user (what the original checklist prescribed) could never have
  worked — the checklist was written before the path was exercised. The
  key's own `apk_permission` axis is a CRUD capability and is set to 3
  (read + write, no delete). The service user has
  `usr_password_recovery_disabled` set: no password recovery into an
  admin-level account whose mailbox nobody reads.
- **Admin page, not installer.** Provisioning is an operator opt-in most
  sites never enable; install.sh stays zero-config. Opt-in features are
  activated from the admin interface of the plugin that owns them.
- **Engine/page split.** All state changes live in static
  `ProvisioningSetup` methods returning result arrays, so the db-tier suite
  exercises them directly and the page stays a thin badge-and-button
  surface.
- **Settings are read fresh from the table** (not the request-cached
  Globalvars singleton) inside the engine, so a value written earlier in
  the same request is visible to later steps.

## Tests

`plugins/server_manager/tests/provisioning_setup_test.php` (db tier, 36
checks): setting round-trip, credential minting (user permission/recovery
flags, key ownership/capability, hashed-at-rest secret that verifies),
no-op idempotency, rotation semantics, question create/reuse, task
activate/resume idempotency, status reflection. The suite snapshots and
restores every piece of global state it touches (settings, task rows and
their active flags, pre-existing pipeline keys) and deletes only rows it
created, so a run leaves the deployment's real configuration unchanged.

## Gotchas encountered (platform facts)

- `amu_admin_menus.amu_slug` is varchar(32); a 33-char plugin menu slug
  fails the whole plugin sync with a PDO truncation error.
- Multi collections do not auto-load on iteration: `getIterator()` returns
  whatever is loaded, so a `foreach` over a freshly constructed Multi
  silently iterates nothing — call `->load()` first.
- The harness signature is `check($condition, $label)`; the harness guards
  against the swapped-argument form.

## Documentation updated

- `plugins/server_manager/docs/overview.md` — Activation subsection under
  Hosting Provisioning.
- `specs/automated_hosting_provisioning_setup.md` — rewritten around the
  page; only genuine one-offs remain as operator steps.
- `specs/customer_cloud_provisioning.md` — activation section routes
  through the page.
