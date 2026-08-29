# Spec: Edge Routing Tier — move sites between nodes without touching DNS

## Problem

Today a site's public domain is welded to the box that hosts it. The domain lives as
`mgn_site_url` on the `ManagedNode` record (`plugins/server_manager/data/managed_node_class.php`),
and public DNS points an A record straight at `mgn_host`. Moving a site to another node
therefore means editing DNS and waiting out TTL propagation — slow, manual, and not
instantly reversible.

We already run the right primitive in the wrong place: Docker-node installs call
`manage_domain.sh set SITENAME DOMAIN`, which writes an Apache `mod_proxy` vhost that
routes by `Host:` header to the container. That is reverse-proxy-by-hostname — but it is
colocated on each host and baked in at install time.

## Goal

Insert a stable indirection layer between the public name and the hosting node, so the
thing DNS points at never changes. DNS points once at an **edge tier**; the edge routes
each domain to whichever node currently hosts it, from a management-node-owned routing
table. Moving a site = flip one row + copy the site. No DNS edit, instant cutover,
instant rollback.

## Non-goals (deferred, not designed out)

- **Multi-region / anycast.** The schema is region-aware from day one (cheap to add now,
  annoying to retrofit) but the initial deployment is single-region: one edge pair behind
  a keepalived VIP. No BGP/anycast work in this spec.
- **DDoS scrubbing.** Cloudflare is deliberately not used. Volumetric-attack absorption
  is a known, accepted gap for a pre-launch platform; revisit later.
- **Multi-site-per-node.** Out of scope here, but the routing table makes it possible
  later (it decouples domain from node identity).

## Architecture

```
DNS (set once)             edge tier (Caddy)              backend nodes
app.example.com ───►   ┌──────────────────────┐  ──►  node-A  (mgn_host)
shop.foo.com    ───►   │ routing table (cached │  ──►  node-B
... all domains ───►   │ locally, fail-static) │  ──►  node-C
                       └──────────────────────┘
                              ▲ polls /routes
                              │
                   management node (Server Manager)
                   - owns the routing table (source of truth)
                   - exposes GET .../routes
                   - "move site" job flips the row
```

### Core principles (carried from the design discussion)

1. **Stateless, replicated edge.** N identical Caddy proxies; the only state is the
   routing table, held as a local cache on each. Front them with a keepalived VIP (or
   multiple A records). Losing one edge node is a non-event.
2. **Data plane / management node split, fail-static.** The edge **pulls** the routing
   table from the management node and **serves from its local cache even if the management
   node is down**. A management-node outage costs the ability to *change* routes, never
   the ability to *serve* traffic. The edge never does a per-request lookup against the
   management node or the DB.
3. **TLS terminates at the edge.** Caddy auto-provisions Let's Encrypt certs for each
   routed domain. Backends serve plain HTTP on the private side (or re-encrypt — see open
   questions). Per-node SSL provisioning is retired (see migration).

## New data model (management node)

### `EdgeNode` — `edge_node_class.php`, prefix `edn`

A lightweight entity, **separate from `ManagedNode`** (an edge node is just Caddy — it has
no Joinery install, web root, container, or upgrade lifecycle, so it does not belong in the
node model). Mirrors the conventions in `managed_node_class.php`
(SystemBase, `$prefix`, `$tablename`, `$field_specifications`, `prepare()`, Multi class).

```php
public static $prefix = 'edn';
public static $tablename = 'edn_edge_nodes';
public static $pkey_column = 'edn_id';

$field_specifications = array(
    'edn_id'              => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    'edn_name'            => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
    'edn_slug'            => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
    'edn_host'            => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false), // SSH/admin host
    'edn_ssh_user'        => array('type'=>'varchar(50)', 'is_nullable'=>false, 'default'=>"'root'"),
    'edn_ssh_key_path'    => array('type'=>'varchar(500)'),
    'edn_ssh_port'        => array('type'=>'int4', 'default'=>'22'),
    'edn_region'          => array('type'=>'varchar(50)'),     // region-aware, nullable for single-region day one
    'edn_poll_secret'     => array('type'=>'varchar(255)'),    // bearer token the edge presents to GET /routes
    'edn_last_poll_time'  => array('type'=>'timestamp(6)'),    // last successful routes pull (observability)
    'edn_enabled'         => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
    'edn_notes'           => array('type'=>'text'),
    'edn_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'edn_update_time'     => array('type'=>'timestamp(6)'),
    'edn_delete_time'     => array('type'=>'timestamp(6)'),
);
```

### `SiteRoute` — `site_route_class.php`, prefix `srt`

The routing table — **the new source of truth for domain → node binding.** `mgn_site_url`
stops being authoritative for routing (see migration).

```php
public static $prefix = 'srt';
public static $tablename = 'srt_site_routes';
public static $pkey_column = 'srt_id';

protected static $foreign_key_actions = [
    'srt_mgn_node_id' => ['table' => 'mgn_managed_nodes', 'column' => 'mgn_id', 'action' => 'set_null'],
];

$field_specifications = array(
    'srt_id'            => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    'srt_domain'        => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
    'srt_mgn_node_id'   => array('type'=>'int8'),              // current backend (FK → mgn_managed_nodes)
    'srt_backend_port'  => array('type'=>'int4'),              // backend listen port (defaults from node mgn_port)
    'srt_region'        => array('type'=>'varchar(50)'),       // region-aware; nullable day one
    'srt_tls_mode'      => array('type'=>'varchar(20)', 'default'=>"'auto'", 'is_nullable'=>false), // auto | off
    'srt_enabled'       => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
    'srt_notes'         => array('type'=>'text'),
    'srt_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'srt_update_time'   => array('type'=>'timestamp(6)'),
    'srt_delete_time'   => array('type'=>'timestamp(6)'),
);
```

`MultiSiteRoute::getMultiResults()` options: `domain`, `node_id`, `region`, `enabled`,
`deleted` — following the filter conventions in `MultiManagedNode`.

## Management-node API: `GET /routes`

The endpoint the edge polls. Returns the full active routing table as JSON. Authenticated
by the edge node's `edn_poll_secret` (bearer token), not the per-node management keys
(those are for management-node → node; this is edge → management-node).

Lives under the Server Manager management API surface. Response shape:

```json
{
  "generated_at": "2026-06-02T12:00:00Z",
  "routes": [
    { "domain": "app.example.com", "backend_host": "10.0.0.11", "backend_port": 8081, "tls": "auto" },
    { "domain": "shop.foo.com",    "backend_host": "10.0.0.12", "backend_port": 8082, "tls": "auto" }
  ]
}
```

- `backend_host`/`backend_port` are resolved by joining `SiteRoute` → `ManagedNode`
  (`mgn_host`, `srt_backend_port` ?? `mgn_port`). Only `srt_enabled` + non-deleted routes
  whose node is enabled are emitted.
- The edge stamps `edn_last_poll_time` via a lightweight write-back (or the endpoint logs
  it per authenticated caller) for observability on the dashboard.

## Edge node software (Caddy)

Caddy is the proxy engine: automatic HTTPS (Let's Encrypt) and a config model that maps
cleanly onto a pulled routing table.

- A small **route-sync sidecar** (shell/Go, runs on the edge) polls `GET /routes` on an
  interval, writes the result to a **local cache file**, and reconfigures Caddy (via its
  admin API or a regenerated Caddyfile + reload) only when the table changed.
- **Fail-static:** if the poll fails (management node down, network blip), the sidecar keeps
  the last good cache and leaves Caddy serving the current routes. It never blanks routes
  on a failed fetch.
- TLS: Caddy provisions/renews certs per `domain` with `tls = auto`. Backends receive
  proxied HTTP on the private side.

The edge is provisioned/managed through Server Manager jobs (see below) — same
"smart management node, dumb executor" pattern as the Go agent, so we are not introducing a
second ops model.

## New jobs (`JobCommandBuilder` + admin view dispatch)

Follow the existing pattern: a `build_*` method returns a step array (`ssh`/`scp`/`local`/
`api`); an admin view enqueues a `ManagementJob`. (There is no central job_type→builder
switch — `views/admin/node_detail.php` calls the builder per action and enqueues. New jobs
follow suit from the new edge/routing views.)

1. **`build_install_edge($edge_node, $params)`** — bootstrap a fresh edge host: install
   Caddy + the route-sync sidecar, write the sidecar config (management-node `/routes` URL +
   `edn_poll_secret`), enable services. `ssh`-type steps against `edn_host`.

2. **`build_move_site($site_route, $target_node, $params)`** — orchestrates a migration
   from existing primitives:
   - `build_backup_database` / `build_backup_project` on the source node,
   - `build_restore_database` / `build_restore_project` on the target node,
   - flip `srt_mgn_node_id` to the target (management-node DB write, in the result processor
     or view on success),
   - edge converges on next poll. (Optional `build_push_routes_to_edge` for instant
     cutover can layer on later; pull-with-cache is the floor.)

3. *(optional, later)* **`build_push_routes_to_edge($edge_node)`** — `api`-type step that
   pokes the edge sidecar to re-pull immediately instead of waiting for the next interval.
   Not required for correctness; a latency optimization.

## Admin UI

- New **"Edge Nodes"** admin page (CRUD over `EdgeNode`) — model the layout on
  `views/admin/targets.php` (backup-target CRUD) and `node_detail.php`. Surface
  `edn_last_poll_time` and enabled state as health.
- New **"Site Routing"** admin page (CRUD over `SiteRoute`) — domain, current node
  (dropdown of `ManagedNode`), region, enabled. A **"Move site"** action enqueues
  `build_move_site`.
- On the existing node detail view, add a read-only **"Domains routed here"** list
  (reverse lookup `SiteRoute WHERE srt_mgn_node_id = node`).

## Migration of existing binding

`mgn_site_url` currently carries the domain. On rollout:

1. Seed one `SiteRoute` per enabled node from its `mgn_site_url` (domain) → `mgn_id`.
2. `mgn_site_url` remains for display/health-check convenience but is **no longer the
   routing authority**; `SiteRoute` is. Document this in the overview doc so it is not
   mistaken for the binding.
3. Per-node SSL (`build_provision_ssl`, `mgn_ssl_state`, `tasks/ProvisionPendingSsl.php`)
   is retired for edge-routed domains — TLS now lives on the edge. Keep the path for any
   node intentionally addressed directly (no edge), gated on whether a `SiteRoute` exists.

Since the platform is pre-launch (no production users), no data-preservation migration of
live traffic is required — the seed step above is sufficient.

## Documentation (lands with implementation, not before)

Per repo docs rules (docs describe current state only, present tense), update
`plugins/server_manager/docs/overview.md` **when the feature lands**, not in this spec:

- New "Edge Routing Tier" section: the indirection model, `EdgeNode` + `SiteRoute`, the
  `GET /routes` pull contract, fail-static caching, and that DNS points at the edge VIP.
- Note that `SiteRoute` is the domain→node source of truth and `mgn_site_url` is not.
- Edge ops: provisioning an edge node, the keepalived VIP, where Caddy/sidecar config and
  the local route cache live.

## Open questions for review

1. **Backend re-encryption.** Plain HTTP edge→backend over a private network, or
   re-encrypt (Caddy → HTTPS to node)? Depends on whether edge and nodes share a trusted
   private link. Default proposal: plain HTTP on a private network; revisit if backends
   are reachable only over public paths.
2. **Sidecar implementation.** Reuse the Go agent codebase (it already does HTTP + polling)
   for the route-sync sidecar, or a standalone small binary / shell + cron? Leaning toward
   a tiny standalone poller to keep the edge free of node assumptions.
3. **Health-aware routing.** Should the edge drop a backend that fails health checks
   (Caddy active health checks), and should the management node reflect that? Out of scope
   for v1 but the schema (`mgn_health_check_url`) supports it later.
