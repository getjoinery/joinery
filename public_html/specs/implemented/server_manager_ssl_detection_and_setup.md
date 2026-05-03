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

Additionally implemented:

5. **Status dot color unification** — `status_color_for_node()` unified into a single method in `JobCommandBuilder`
6. **Bug fix: list_backups infinite retry loop** — suppress auto-refresh when the last backup scan attempt failed
7. **Bug fix: node-to-host assignment** — `install_node_form.php` and `node_add.php` now set `mgn_mgh_host_id` after save

---

## Piece 1: SSL Detection in `check_status`

### Two detection paths

SSL detection uses two independent paths, run in order:

**Path A — LE cert file (SSH transport only)**

`JobCommandBuilder::build_check_status_ssh()` appends one step at the end of the step array. The domain comes from `mgn_site_url`. For Docker nodes the step runs `on_host: true` because `/etc/letsencrypt/live/` lives on the Docker host, not inside the container.

```php
$domain = parse_url($node->get('mgn_site_url'), PHP_URL_HOST) ?: '';
$is_docker = (bool)$node->get('mgn_container_name');
if ($domain) {
    $domain_esc = escapeshellarg($domain);
    $steps[] = [
        'type'              => 'ssh',
        'label'             => 'Check SSL certificate',
        'on_host'           => $is_docker,
        'cmd'               => "if [ -f /etc/letsencrypt/live/{$domain_esc}/fullchain.pem ]; then ..."
                             . " echo \"SSL_CERT_FOUND domain={$domain} expiry=\$EXPIRY\";"
                             . " else echo \"SSL_CERT_MISSING domain={$domain}\"; fi",
        'continue_on_error' => true,
    ];
}
```

Emits either `SSL_CERT_FOUND domain=X expiry=Y` or `SSL_CERT_MISSING domain=X`.

**Path B — HTTPS probe (both SSH and API transport)**

When Path A finds no LE cert, or when the job used API transport (no SSH cert step ran), `JobResultProcessor::process_check_status()` performs a PHP curl HEAD to `https://$domain/` with full cert verification (`CURLOPT_SSL_VERIFYPEER => true`). This detects Cloudflare, other CDN/edge SSL, and any cert not managed by certbot.

`JobCommandBuilder::probe_https($domain, $timeout = 4)` is the shared implementation used by both the job processor and the AJAX auto-refresh path (`fetch_status_via_api()`). A successful HTTPS API call already proves HTTPS is working, so `fetch_status_via_api()` marks SSL active without a separate probe when the site URL starts with `https://` and `mgn_tls_insecure` is false.

### Result fields stored in `mgn_last_status_data`

```json
{
  "ssl_state": "active",
  "ssl_domain": "example.com",
  "ssl_detection_method": "letsencrypt",
  "ssl_le_cert": true,
  "ssl_https_probe": null,
  "ssl_expiry_raw": "Jun  1 00:00:00 2026 GMT",
  "ssl_expiry_ts": 1748736000
}
```

| Field | Values | Notes |
|-------|--------|-------|
| `ssl_detection_method` | `letsencrypt`, `https_probe` | Which path made it active |
| `ssl_le_cert` | `true`, `false` | Set on SSH path; absent on API-only nodes |
| `ssl_https_probe` | `true`, `false` | Set when probe was run; absent when LE cert was found |

### State transition rules

```
current state → new state
─────────────────────────────────────────────────────────────
null/any  + either path active   → active
active    + both paths fail      → failed   (cert disappeared)
pending   + either path active   → active
pending   + both paths fail      → pending  (let ProvisionPendingSsl proceed)
null/failed + both paths fail    → no change
```

---

## Piece 2: SSL Tile in System Health Grid

The tile renders when `mgn_ssl_state` is non-null OR `ssl_state` exists in `$status_data`.

When `active`, the tile always shows two check rows:

```
SSL   [active]

Let's Encrypt cert   ✓ / ✗ / —
HTTPS probe          ✓ / ✗ / —
[Expires Jun 1, 2026]  (if LE cert with expiry)
```

`—` means the check was not applicable for this node/transport. Green ✓ = passed, red ✗ = failed. The badge stays green as long as at least one path is active.

Legacy data (stored before `ssl_le_cert`/`ssl_https_probe` fields existed) falls back to inferring from `ssl_detection_method`.

---

## Piece 3: SSL Setup Card on Overview Tab

Shows when:
- Node has an FQDN in `mgn_site_url`
- `mgn_ssl_state` is not `active`
- `mgn_install_state` is not `installing`

Contains a server-side DNS check (`gethostbyname()` vs `mgn_host`), a table showing domain/expected/resolved IPs, and a Provision SSL button disabled until DNS is ready. Re-check DNS is a GET reload. Cloudflare-proxied domains (DNS resolves to Cloudflare IP, not the node) will show DNS mismatch and disable provisioning — this is intentional, certbot HTTP-01 challenge requires the origin IP to be reachable.

---

## Piece 4: Manual `provision_ssl` Trigger

POST handler in `node_detail.php` validates that `mgn_site_url` has a parseable FQDN and that SSH is configured (`JobCommandBuilder::has_ssh()`), creates the job, sets `mgn_ssl_state = 'pending'`, and redirects to the job detail page.

---

## Piece 5: Status Dot Color Unification

`JobCommandBuilder::status_color_for_node($node, $status_data, $last_job_failed)` is the single source of truth for the dashboard dot color. Both the dashboard page render (`index.php`) and the AJAX auto-refresh endpoint (`refresh_node_status.php`) call this method. SSL absence (FQDN with `mgn_ssl_state !== 'active'`) produces `warning` (yellow).

---

## Piece 6: Bug Fix — list_backups Infinite Retry Loop

The dashboard auto-refresh for the Backups tab was triggering indefinitely when backup scan jobs kept failing. Root cause: staleness was measured only against completed jobs. Fix: query the most recent `list_backups` job regardless of status and suppress auto-refresh if it was `failed`.

---

## Piece 7: Bug Fix — Node Not Assigned to Host on Dashboard

`install_node_form.php` and `node_add.php` were not setting `mgn_mgh_host_id` after saving a new node. Fix: after save, look up `mgh_managed_hosts` by `mgn_host` IP and set the FK if a match is found.

---

## Files Changed

| File | Change |
|------|--------|
| `plugins/server_manager/includes/JobCommandBuilder.php` | SSL cert-check step in `build_check_status_ssh()`; `probe_https()` method; `fetch_status_via_api()` marks SSL active on successful HTTPS call; `status_color_for_node()` unified |
| `plugins/server_manager/includes/JobResultProcessor.php` | Parse SSL tokens + HTTPS probe fallback in `process_check_status()`; stores `ssl_le_cert`, `ssl_https_probe`, `ssl_detection_method` |
| `plugins/server_manager/views/admin/node_detail.php` | SSL tile with dual check rows; SSL Setup card; `provision_ssl` POST handler; backup auto-refresh guard fix |
| `plugins/server_manager/views/admin/index.php` | Use `status_color_for_node()` instead of inline logic |
| `plugins/server_manager/views/admin/install_node_form.php` | Set `mgn_mgh_host_id` after node save |
| `plugins/server_manager/views/admin/node_add.php` | Set `mgn_mgh_host_id` after node save |
| `plugins/server_manager/ajax/refresh_node_status.php` | Use `status_color_for_node()` |
| `docs/server_manager.md` | SSL Management section added |
