# Remote Archive Refresh Specification

## Overview

This specification defines a secure mechanism for client sites to request the upgrade server to refresh/regenerate its distribution archives remotely, without requiring manual login to the upgrade server.

## Problem Statement

Currently, when code changes are made on the upgrade server, an administrator must:
1. Log into the upgrade server admin panel
2. Navigate to the publish upgrade page
3. Manually trigger archive regeneration

This creates friction for deployments and requires human intervention for routine archive updates.

## Goals

1. Allow authorized client sites to trigger archive refresh on the upgrade server
2. Maintain security through explicit opt-in and IP restrictions
3. Regenerate all archives (core + all themes + all plugins) for the current version
4. Provide clear feedback on refresh status

## Version Handling

**Remote refresh regenerates the current version only.** It does not increment the version number.

This is intended for:
- Rebuilding archives after minor fixes or updates
- Refreshing theme/plugin archives when source files change

For major updates requiring a new version number, administrators should log into the upgrade server and use the standard publish workflow.

## Security Model

This feature requires **two layers of security** to be satisfied:

1. **Feature Flag** - `allow_remote_archive_refresh` setting must be explicitly enabled
2. **IP Whitelist** - Requesting IP must be in `archive_refresh_allowed_ips` list

Both must pass for a refresh request to be processed.

## Database Settings

Add the following settings to `stg_settings`:

| Setting Name | Type | Default | Description |
|--------------|------|---------|-------------|
| `allow_remote_archive_refresh` | boolean | false | Master switch to enable remote refresh requests |
| `archive_refresh_allowed_ips` | JSON array | [] | List of IP addresses allowed to request refreshes |

### Example Settings Values

```php
// Enable the feature
'allow_remote_archive_refresh' => true

// Allow specific IPs (supports individual IPs and CIDR notation)
'archive_refresh_allowed_ips' => '["192.168.1.100", "10.0.0.0/24", "203.0.113.50"]'
```

## API Endpoint

### Endpoint

```
POST /utils/publish_upgrade?refresh-archives=1
```

### Request Body

No body required. The request simply triggers a full regeneration of all archives.

### Response

**Success (200):**
```json
{
  "success": true,
  "message": "Archives refreshed successfully",
  "timestamp": "2025-01-31T15:30:00Z"
}
```

**Denied - Feature Disabled (403):**
```json
{
  "success": false,
  "error": "Remote archive refresh is not enabled on this server"
}
```

**Denied - IP Not Allowed (403):**
```json
{
  "success": false,
  "error": "IP address not authorized for archive refresh"
}
```

**Error (500):**
```json
{
  "success": false,
  "error": "Archive refresh failed",
  "details": "Error message here"
}
```

## Implementation

### Server-Side Handler

Add to `/utils/publish_upgrade.php`:

```php
// Handle remote archive refresh requests
if (isset($_GET['refresh-archives']) && $_GET['refresh-archives'] == '1') {
    header('Content-Type: application/json');

    $settings = Globalvars::get_instance();

    // Check if feature is enabled
    if (!$settings->get_setting('allow_remote_archive_refresh')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Remote archive refresh is not enabled on this server'
        ]);
        exit;
    }

    // Check IP whitelist
    $allowed_ips = json_decode($settings->get_setting('archive_refresh_allowed_ips') ?: '[]', true);
    $client_ip = $_SERVER['REMOTE_ADDR'];

    if (!is_ip_in_list($client_ip, $allowed_ips)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'IP address not authorized for archive refresh'
        ]);
        exit;
    }

    // All checks passed - regenerate archives using existing publish logic
    try {
        // Reuse existing publish functions to regenerate current version
        // This calls the same code path as manual publish, without version bump
        regenerate_current_archives();

        echo json_encode([
            'success' => true,
            'message' => 'Archives refreshed successfully',
            'timestamp' => date('c')
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Archive refresh failed',
            'details' => $e->getMessage()
        ]);
    }
    exit;
}
```

### IP Matching Function

```php
/**
 * Check if an IP address is in the allowed list
 * Supports exact matches and CIDR notation
 *
 * @param string $ip IP address to check
 * @param array $allowed_list List of allowed IPs/CIDRs
 * @return bool
 */
function is_ip_in_list($ip, $allowed_list) {
    if (empty($allowed_list)) {
        return false; // No whitelist = deny all
    }

    foreach ($allowed_list as $allowed) {
        // Exact match
        if ($ip === $allowed) {
            return true;
        }

        // CIDR match
        if (strpos($allowed, '/') !== false) {
            if (ip_in_cidr($ip, $allowed)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check if IP is within CIDR range
 */
function ip_in_cidr($ip, $cidr) {
    list($subnet, $bits) = explode('/', $cidr);
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask = -1 << (32 - $bits);
    return ($ip_long & $mask) === ($subnet_long & $mask);
}
```

### Client-Side Request (upgrade.php)

Add option in upgrade.php to request refresh before downloading:

```php
/**
 * Request the upgrade server to refresh its archives
 *
 * @return array Response from server
 */
function request_archive_refresh() {
    $settings = Globalvars::get_instance();

    $upgrade_source = $settings->get_setting('upgrade_source');

    if (empty($upgrade_source)) {
        return ['success' => false, 'error' => 'Upgrade source not configured'];
    }

    $url = rtrim($upgrade_source, '/') . '/utils/publish_upgrade?refresh-archives=1';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120 // Archive refresh may take time
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        $error = json_decode($response, true);
        return ['success' => false, 'error' => $error['error'] ?? 'Unknown error'];
    }

    return json_decode($response, true);
}
```

## Migration

### Settings Migration

```php
$migration = array();
$migration['database_version'] = '0.XX';
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'allow_remote_archive_refresh'";
$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_description) VALUES
    ('allow_remote_archive_refresh', 'false', 'Enable remote archive refresh requests from authorized clients'),
    ('archive_refresh_allowed_ips', '[]', 'JSON array of IP addresses allowed to request archive refresh');";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```
