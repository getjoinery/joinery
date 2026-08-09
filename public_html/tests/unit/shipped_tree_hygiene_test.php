<?php
/** @joinery-test
 * name: shipped_tree_hygiene
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Everything under public_html/ is copied into the release archive and lands on
 * every deployment. So one site's content must never live here: a script that
 * builds getjoinery.com's marketing pages has no business on a customer's
 * install, and a component template carrying getjoinery's copy as a default
 * seeds those words into somebody else's new page.
 *
 * The intended home for per-site content is a content pack — a file the operator
 * moves around, outside the application tree entirely
 * (specs/content_pack_feature.md). Until that ships, the seeders live in
 * {site root}/content_packs/, which is gitignored and outside public_html.
 *
 * This test is the thing that notices when one comes back.
 *
 * Run:  php tests/unit/shipped_tree_hygiene_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');

harness_boot();

$root = dirname(__DIR__, 2);

/**
 * Deployment names that must not appear in a filename under public_html.
 * A file named after one site is, by definition, not shared platform code.
 */
$deployment_names = ['getjoinery', 'scrolldaddy', 'phillyzouk', 'jeremytunnell', 'mapsofwisdom', 'galactictribune'];

// Themes and plugins are the sanctioned homes for per-deployment presentation,
// so a theme directory named after its site is expected and fine. Migrations
// legitimately name what they rename. Specs, docs and tests discuss sites by
// name. What is left — a runnable script named after one deployment — is the
// thing that should not be in shared code.
$exempt_prefixes = [
    $root . '/theme/',
    $root . '/plugins/',
    $root . '/specs/',
    $root . '/docs/',
    $root . '/tests/',
    $root . '/migrations/',
];

section('No site-specific scripts in the shipped tree');

$offenders = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $path => $info) {
    if (!$info->isFile()) {
        continue;
    }
    $skip = false;
    foreach ($exempt_prefixes as $prefix) {
        if (strpos($path, $prefix) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    // Scripts only. Screenshots and other debris named after a site are noise
    // in the tree, not a content-leak risk, and are somebody else's cleanup.
    if (strtolower($info->getExtension()) !== 'php') {
        continue;
    }

    $name = strtolower($info->getFilename());
    foreach ($deployment_names as $deployment) {
        if (strpos($name, $deployment) !== false) {
            $offenders[] = str_replace($root . '/', '', $path);
            break;
        }
    }
}

check(
    empty($offenders),
    'no runnable script under public_html is named after a single deployment',
    empty($offenders) ? 'none found' : ('found: ' . implode(', ', $offenders) . ' — move it to {site root}/content_packs/ or into the theme/plugin that owns it')
);

section('Content packs live outside the shipped tree');

$pack_dir = dirname($root) . '/content_packs';
check(
    !is_dir($root . '/content_packs'),
    'content_packs is not inside public_html',
    is_dir($root . '/content_packs') ? 'found public_html/content_packs — it would ship' : 'correct'
);

// Not every checkout has packs, and that is fine — this only asserts that when
// they exist they are somewhere a release cannot pick them up.
if (is_dir($pack_dir)) {
    check(
        strpos(realpath($pack_dir), realpath($root)) !== 0,
        'the content pack directory sits outside public_html',
        realpath($pack_dir)
    );
}

harness_finish();
