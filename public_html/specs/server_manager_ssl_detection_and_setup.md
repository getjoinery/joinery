# Server Manager: SSL Detection and Setup

## Goal

Detect the absence of a TLS certificate on a managed node and walk the admin through provisioning one via Server Manager — including a DNS readiness check and one-click certbot trigger — without leaving the admin UI.

## Background

`mgn_ssl_state` already tracks `null / pending / active / failed`, but it only gets written during auto-provisioned installs triggered by `PollHostingOrders`. Nodes added manually, nodes whose `mgn_ssl_state` was never set, and nodes whose cert was revoked or expired have no coverage. There is also no manual trigger for SSL provisioning — the only path today is the automated background task.

## Scope

Four pieces, in implementation order:

1. **SSL detection in `check_status`** — detect cert presence on the node and keep `mgn_ssl_state` current
2. **SSL tile in System Health** — surface SSL state alongside Disk/Memory/Load/Postgres in the Overview grid
3. **SSL Setup card** — actionable banner on Overview when SSL is absent, with DNS readiness check and provision button
4. **Manual `provision_ssl` trigger** — POST handler that validates, creates the job, and redirects to job detail

---

## Piece 1: SSL Detection in `check_status`

### What changes

**`JobCommandBuilder::build_check_status_ssh()`** — add one new SSH step at the end of the step array.

The domain is passed in from `mgn_site_url` (already known — no need to grep Apache config). The step must run **on the host** for Docker nodes, because `/etc/letsencrypt/live/` and the reverse-proxy Apache live on the Docker host, not inside the container. This mirrors the existing `provision_ssl` step which already uses `'on_host' => true` for Docker nodes.

```php
$domain = parse_url($node->get('mgn_site_url'), PHP_URL_HOST) ?: '';
$is_docker = (bool)$node->get('mgn_container_name');

if ($domain) {
    $domain_esc = escapeshellarg($domain);
    $steps[] = [
        'type'             => 'ssh',
        'label'            => 'Check SSL certificate',
        'on_host'          => $is_docker,
        'cmd'              => "if [ -f /etc/letsencrypt/live/{$domain_esc}/fullchain.pem ]; then"
                            . "  EXPIRY=\$(openssl x509 -enddate -noout -in /etc/letsencrypt/live/{$domain_esc}/fullchain.pem | cut -d= -f2);"
                            . "  echo \"SSL_CERT_FOUND domain={$domain} expiry=\$EXPIRY\";"
                            . " else echo \"SSL_CERT_MISSING domain={$domain}\"; fi",
        'continue_on_error' => true,
    ];
}
```

If `mgn_site_url` has no parseable host (bare IP, localhost, or unset), the step is omitted entirely — no `SSL_DOMAIN_NOT_FOUND` sentinel needed.

**`JobResultProcessor::process_check_status()`** — after parsing existing status fields, scan job output for the new tokens:

| Token in output | Action |
|-----------------|--------|
| `SSL_CERT_FOUND domain=X expiry=Y` | Set `mgn_ssl_state = 'active'`; store `ssl_domain` and `ssl_expiry_raw` in `mgn_last_status_data` |
| `SSL_CERT_MISSING domain=X` | If currently `active`: set `mgn_ssl_state = 'failed'` (cert disappeared — flag it loudly). If currently `null`: leave as `null`. Never overwrite `pending` or `failed`. |
| _(step absent — no domain in mgn_site_url)_ | No state change |

**State transition rules:**

```
current state → new state (on check_status completion)
─────────────────────────────────────────────────────
null    + CERT_FOUND    → active
active  + CERT_FOUND    → active  (no-op, update expiry)
failed  + CERT_FOUND    → active  (cert appeared manually — accept it)
pending + CERT_FOUND    → active  (certbot ran before task polled — advance state)
null    + CERT_MISSING  → null    (no change; Setup card will surface this)
active  + CERT_MISSING  → failed  (cert disappeared — flag it loudly)
pending + CERT_MISSING  → pending (still waiting — leave ProvisionPendingSsl alone)
failed  + CERT_MISSING  → failed  (no change)
```

**`mgn_last_status_data` additions** (stored alongside existing disk/memory/etc.):

```json
{
  "ssl_state": "active",
  "ssl_domain": "example.com",
  "ssl_expiry_raw": "Jun  1 00:00:00 2026 GMT",
  "ssl_expiry_ts": 1748736000
}
```

`ssl_expiry_ts` is parsed from `ssl_expiry_raw` by the result processor using `strtotime()`.

### API transport (`build_check_status_api`)

The `/api/v1/management/stats` endpoint does not currently return SSL state. No change to the API path for this piece — when a node uses API transport for check_status, ssl_state is not updated. The Setup card (Piece 3) will check `mgn_ssl_state` and show a note if the last check used API transport only.

---

## Piece 2: SSL Tile in System Health Grid

### Where

The stat tile grid in the Overview tab's System Health panel (`node_detail.php`, inside the `if ($status_data)` block), after the Joinery Version tile.

### Tile content

**State: `active`**
- Label: `SSL`
- Value: green badge `active`
- Subline: `Expires {human date}` (from `ssl_expiry_ts`; amber badge if expiry < 30 days away)

**State: `null` (never checked or explicitly missing)**
- Label: `SSL`
- Value: gray badge `not configured`
- Subline: _(empty — Setup card below handles the call-to-action)_

**State: `pending`**
- Label: `SSL`
- Value: yellow badge `pending`
- Subline: `Waiting for DNS / certbot`

**State: `failed`**
- Label: `SSL`
- Value: red badge `failed`
- Subline: `See SSL Setup below`

**No `mgn_ssl_state` and no `ssl_state` in status_data** (old check_status run before this feature):
- Tile is omitted entirely.

### Condition for rendering

Render the tile if `mgn_ssl_state` is non-null OR if `ssl_state` key exists in `$status_data`. This means the tile appears once the node has been checked with the new code.

---

## Piece 3: SSL Setup Card on Overview Tab

### When to show

Show the card when ALL of the following are true:
- Node has a `mgn_site_url` containing an FQDN (not a bare IP and not `localhost`)
- `mgn_ssl_state` is `null`, `failed`, or `pending`
- `mgn_install_state` is not `installing` (don't stack with the install banner)

Do NOT show the card when `mgn_ssl_state = 'active'`.

### Placement

Below the System Health panel, above Connection Info.

### Card layout

```
┌─────────────────────────────────────────────────────┐
│  SSL Setup                                          │
│                                                     │
│  [icon] No SSL certificate detected for             │
│         example.com                                 │
│                                                     │
│  DNS Check                                          │
│  ┌─────────────────────────────────────────────┐   │
│  │  Domain:    example.com                     │   │
│  │  Expected:  23.239.11.53 (node host)        │   │
│  │  Resolved:  23.239.11.53  ✓ DNS is ready    │   │
│  └─────────────────────────────────────────────┘   │
│                                                     │
│  [ Provision SSL ]    [ Re-check DNS ]              │
│                                                     │
│  Certbot will run on the node's host and            │
│  configure Apache to serve HTTPS for this domain.  │
└─────────────────────────────────────────────────────┘
```

**When DNS is not ready:**

```
│  Resolved:  1.2.3.4  ✗ Doesn't match node host    │
│                                                     │
│  DNS has not propagated yet. Point your domain's   │
│  A record to 23.239.11.53 and wait for it to       │
│  resolve here before provisioning SSL.              │
│                                                     │
│  [ Re-check DNS ]  (Provision SSL is disabled)     │
```

**When `mgn_host` is missing (no IP to compare against):**

Show a note: "Node host IP is not configured — cannot verify DNS. Provision SSL anyway at your own risk." Provision button is enabled regardless.

**When `mgn_ssl_state = 'pending'`:**

Replace the "Provision SSL" button with a status note: "SSL provisioning is in progress." Link to the latest `provision_ssl` job for this node if one exists.

**When `mgn_ssl_state = 'failed'`:**

Show above the DNS check: "A previous SSL provisioning attempt failed. Review the [job output →](#) before retrying." Then show DNS check + Provision button normally.

### DNS check implementation

The DNS check runs **server-side at page render time** using `gethostbyname($domain)`. This is the same approach used by `ProvisionPendingSsl`. No AJAX needed.

```php
$domain = parse_url($node->get('mgn_site_url'), PHP_URL_HOST);
$host_ip = $node->get('mgn_host');
$resolved_ip = $domain ? gethostbyname($domain) : null;
$dns_ready = ($resolved_ip && $resolved_ip !== $domain && (!$host_ip || $resolved_ip === $host_ip));
```

A **Re-check DNS** button is a `GET` link back to the same page (`$base_url . '&tab=overview'`) — the page reload re-runs the DNS check.

### Condition for "Provision SSL" button enabled

- DNS ready OR `mgn_host` is empty/missing
- AND `mgn_ssl_state !== 'pending'` (a job is not already in flight)

---

## Piece 4: Manual `provision_ssl` Trigger

### POST handler (in `node_detail.php`)

```php
if ($action === 'provision_ssl') {
    $domain = parse_url($node->get('mgn_site_url'), PHP_URL_HOST);
    if (!$domain) {
        $session->save_message(new DisplayMessage(
            'Cannot provision SSL: node has no site URL with a domain.',
            'Error', $page_regex,
            DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
        ));
        header('Location: ' . $base_url . '&tab=overview');
        exit;
    }
    if (!JobCommandBuilder::has_ssh($node)) {
        $session->save_message(new DisplayMessage(
            'Cannot provision SSL: SSH is not configured for this node.',
            'Error', $page_regex,
            DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
        ));
        header('Location: ' . $base_url . '&tab=overview');
        exit;
    }
    $settings = Globalvars::get_instance();
    $alert_email = $settings->get_setting('server_manager_provisioning_admin_alert_email') ?: '';
    $job_params = ['domain' => $domain, 'admin_email' => $alert_email];
    $steps = JobCommandBuilder::build_provision_ssl($node, $job_params);
    $job = ManagementJob::createJob($node->key, 'provision_ssl', $steps, $job_params, $session->get_user_id());
    $node->set('mgn_ssl_state', 'pending');
    $node->save();
    header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
    exit;
}
```

The button form is a standard `<form method="post">` with `action=provision_ssl` and a hidden `edit_primary_key_value` field (matching the existing pattern in `node_detail.php`). No FormWriter needed (action-only form with no user-entered fields).

### `JobResultProcessor::process_provision_ssl()` — already exists

The existing handler sets `mgn_ssl_state = 'active'` on success. No changes needed here.

### What happens after the job

1. Job created → admin redirected to job detail with live output
2. Certbot runs → job completes/fails
3. On next page view of Overview tab: `mgn_ssl_state` reflects the result; SSL tile shows state; Setup card is shown or hidden accordingly

---

## Error States Not Handled (out of scope)

- Cert renewal / expiry warnings (tracked in status tile only; auto-renewal is handled by certbot's own systemd timer)
- Multi-domain / SAN certificates
- Wildcard certs
- Non-Apache web servers (nginx)
- Cloudflare-proxied domains: DNS resolves to Cloudflare's IP, so the DNS check will correctly block provisioning. This is intentional — certbot's HTTP-01 challenge is intercepted by Cloudflare and never reaches the origin server, so certbot will fail. The admin must temporarily set the domain to DNS-only mode (gray cloud) in Cloudflare, wait for propagation to the origin IP, provision SSL, then re-enable the proxy if desired.

---

## Files to Change

| File | Change |
|------|--------|
| `plugins/server_manager/includes/JobCommandBuilder.php` | Add SSL cert-check step to `build_check_status_ssh()` |
| `plugins/server_manager/includes/JobResultProcessor.php` | Parse SSL tokens in `process_check_status()`; update `mgn_ssl_state` and `mgn_last_status_data` |
| `plugins/server_manager/views/admin/node_detail.php` | SSL tile in System Health grid; SSL Setup card; `provision_ssl` POST handler |
| `docs/server_manager.md` | Add SSL detection and setup section |
