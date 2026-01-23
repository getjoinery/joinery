<?php
/**
 * Latest Release Redirect Endpoint
 *
 * Redirects to the most recent Joinery release archive.
 * Used by one-liner install commands to fetch the latest version.
 *
 * Usage:
 *   curl -sL https://joinerytest.site/utils/latest_release | tar xz
 *   curl -LO https://joinerytest.site/utils/latest_release
 *
 * @version 1.0
 */

// PathHelper, Globalvars, SessionControl are pre-loaded via serve.php
require_once(PathHelper::getIncludePath('data/upgrades_class.php'));

// Get the most recent release
$latest = new MultiUpgrade([], ['upg_upgrade_id' => 'DESC'], 1);
$latest->load();

if ($latest->count() > 0) {
    $upgrade = $latest->get(0);
    $filename = $upgrade->get('upg_name');

    // Redirect to the actual file
    header('Location: /static_files/' . $filename);
    exit;
}

// No releases found
http_response_code(404);
header('Content-Type: text/plain');
echo 'No releases found';
