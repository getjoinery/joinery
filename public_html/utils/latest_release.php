<?php
/**
 * Latest Release Redirect Endpoint
 *
 * Redirects to the most recent Joinery release archive.
 * Used by one-liner install commands and the install_node job to fetch the
 * latest version from a control plane.
 *
 * A site that publishes releases serves its own newest archive. A site that
 * consumes releases from an upstream (upgrade_source setting) chains to the
 * upstream's same endpoint, so any control plane can hand out an installable
 * release regardless of where it sits in the distribution chain. Rows whose
 * archive file is gone from static_files (relics of an earlier publishing
 * role) are skipped, never served.
 *
 * Usage:
 *   curl -sL https://dev.getjoinery.com/utils/latest_release | tar xz
 *   curl -LO https://dev.getjoinery.com/utils/latest_release
 *
 * @version 1.1
 */

// PathHelper, Globalvars, SessionControl are pre-loaded via serve.php
require_once(PathHelper::getIncludePath('data/upgrades_class.php'));

$static_dir = PathHelper::getSiteRoot() . '/static_files/';

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
// own upgrades from.
$settings = Globalvars::get_instance();
$upgrade_source = rtrim((string)$settings->get_setting('upgrade_source'), '/');
if ($upgrade_source !== '') {
    header('Location: ' . $upgrade_source . '/utils/latest_release');
    exit;
}

// No releases found
http_response_code(404);
header('Content-Type: text/plain');
echo 'No releases found';
