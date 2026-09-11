<?php
/**
 * Latest Release Redirect Endpoint
 *
 * Redirects to the most recent Joinery release archive.
 * Used by one-liner install commands and the install_node job to fetch the
 * latest version from a management node.
 *
 * A site that publishes releases serves its own newest archive. A site that
 * consumes releases from an upstream (upgrade_source setting) chains to the
 * upstream's same endpoint, so any management node can hand out an installable
 * release regardless of where it sits in the distribution chain. Rows whose
 * archive file is gone from static_files (relics of an earlier publishing
 * role) are skipped, never served.
 *
 * An optional ?version= asks for one specific release instead of the newest.
 * It exists so a build can be reproduced — for a bug report, or for a review
 * that has to see the same bytes twice — and is deliberately not what any
 * install path uses. A pinned installer needs a bump on every publish, and a
 * stale pin hands out old code to people who asked for current code.
 *
 * Usage:
 *   curl -sL https://getjoinery.com/utils/latest_release | tar xz
 *   curl -LO https://getjoinery.com/utils/latest_release
 *   curl -LO 'https://getjoinery.com/utils/latest_release?version=0.8.198'
 *
 * @version 1.2
 */

// PathHelper, Globalvars, SessionControl are pre-loaded via serve.php
require_once(PathHelper::getIncludePath('data/upgrades_class.php'));

$static_dir = PathHelper::getSiteRoot() . '/static_files/';
$wanted_version = isset($_GET['version']) ? trim((string)$_GET['version']) : '';

if ($wanted_version !== '') {
    // Releases are numbered major.minor.patch and stored as three integers, so
    // anything else cannot match a row. Rejecting it here also keeps a
    // caller-supplied string away from the filename comparison below.
    if (!preg_match('/^([0-9]+)\.([0-9]+)\.([0-9]+)$/', $wanted_version, $parts)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid version — expected major.minor.patch';
        exit;
    }

    $matches = new MultiUpgrade([
        'major_version' => (int)$parts[1],
        'minor_version' => (int)$parts[2],
        'patch_version' => (int)$parts[3],
    ], ['upg_upgrade_id' => 'DESC']);
    $matches->load();
    foreach ($matches as $upgrade) {
        $filename = $upgrade->get('upg_name');
        if ($filename && file_exists($static_dir . $filename)) {
            header('Location: /static_files/' . $filename);
            exit;
        }
    }

    // A version this site never published is not an error worth chaining for —
    // an upstream's 0.8.198 and ours are the same build only by coincidence of
    // numbering, and serving somebody else's is worse than saying no.
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'No release found for version ' . htmlspecialchars($wanted_version, ENT_QUOTES, 'UTF-8');
    exit;
}

// Newest local release whose archive actually exists on disk.
$recent = new MultiUpgrade([], ['upg_upgrade_id' => 'DESC'], 10);
$recent->load();
foreach ($recent as $upgrade) {
    $filename = $upgrade->get('upg_name');
    if ($filename && file_exists($static_dir . $filename)) {
        header('Location: /static_files/' . $filename);
        exit;
    }
}

// No servable local release — chain to the upstream this site receives its
// own upgrades from. The root node has no upstream: `upgrade_source` there
// names a site running an older copy of this very code, so chaining would
// answer "the latest release" with an older one than the asker was told to
// expect. Better to say plainly that there is nothing to serve.
$settings = Globalvars::get_instance();
$upgrade_source = rtrim((string)$settings->get_setting('upgrade_source'), '/');
if ($upgrade_source !== '' && !MarketplaceClient::is_root()) {
    header('Location: ' . $upgrade_source . '/utils/latest_release');
    exit;
}

// No releases found
http_response_code(404);
header('Content-Type: text/plain');
echo 'No releases found';
