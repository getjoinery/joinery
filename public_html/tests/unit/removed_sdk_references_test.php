<?php
/** @joinery-test
 * name: removed_sdk_references
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * No code names a vendor SDK the platform removed
 * (specs/email_provider_sdk_removal.md WP8).
 *
 * The email provider and AWS SDKs left composer.json; every provider talks
 * to its service through ProviderHttp and AwsSigV4. A `use` of one of their
 * namespaces, or a package name in a string, would fatal or mislead the
 * first time it ran, on a node whose vendor/ no longer holds the package.
 * Every PHP file, composer.json and the docs are read; specs/ is history
 * and is not.
 *
 * Run: php tests/run.php --filter=removed_sdk_references
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$NAMESPACES = array(
	'Mailgun'      => '/(?:\buse\s+|\\\\|\bnew\s+)Mailgun\\\\/',
	'Postmark'     => '/(?:\buse\s+|\\\\|\bnew\s+)Postmark\\\\/',
	'Brevo'        => '/(?:\buse\s+|\\\\|\bnew\s+)Brevo\\\\/',
	'Mailjet'      => '/(?:\buse\s+|\\\\|\bnew\s+)Mailjet\\\\/',
	'Resend'       => '/(?:\buse\s+Resend\b|\\\\Resend(?:\\\\|::)|\bResend::client\b)/',
	'SendGrid'     => '/(?:\buse\s+SendGrid\b|\\\\SendGrid(?:\\\\|\b(?!Provider))|\bnew\s+SendGrid\b)/',
	'MailchimpAPI' => '/\bMailchimpAPI\\\\/',
	'Aws'          => '/(?:\buse\s+|\\\\|\bnew\s+)Aws\\\\/',
);
$PACKAGES = array(
	'mailgun/mailgun-php', 'kriswallsmith/buzz', 'nyholm/psr7', 'wildbit/postmark-php',
	'getbrevo/brevo-php', 'mailjet/mailjet-apiv3-php', 'resend/resend-php', 'sendgrid/sendgrid',
	'sendgrid/php-http-client', 'jhut89/mailchimp3php', 'aws/aws-sdk-php', 'aws/aws-crt-php',
	'php-http/discovery',
);

$base = rtrim(PathHelper::getBasePath(), '/');
$site = dirname($base);
$roots = array($base, $site . '/maintenance_scripts');
$skip = array($base . '/specs/', $base . '/vendor/', $site . '/vendor/');

$files = array();
foreach ($roots as $root) {
	if (!is_dir($root)) { continue; }
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$path = $f->getPathname();
		foreach ($skip as $s) { if (strpos($path, $s) === 0) { continue 2; } }
		if (strpos($path, '/.git/') !== false || strpos($path, '/node_modules/') !== false) { continue; }
		if (preg_match('/\.(php|md|json)$/', $path)) { $files[] = $path; }
	}
}
// This file names them all by design.
$self = realpath(__FILE__);

$ns_hits = array();
$pkg_hits = array();
foreach ($files as $path) {
	if (realpath($path) === $self) { continue; }
	$src = @file_get_contents($path);
	if (!is_string($src)) { continue; }
	$rel = substr($path, strlen($site) + 1);
	if (substr($path, -4) === '.php') {
		foreach ($NAMESPACES as $label => $re) {
			if (preg_match($re, $src)) { $ns_hits[] = $label . ' in ' . $rel; }
		}
	}
	foreach ($PACKAGES as $pkg) {
		if (strpos($src, $pkg) !== false) { $pkg_hits[] = $pkg . ' in ' . $rel; }
	}
}

section('Removed SDK namespaces');
check(count($files) > 500, 'the scan read the tree', count($files) . ' files');
check(empty($ns_hits), 'no PHP file uses a removed SDK namespace', implode('; ', $ns_hits));

section('Removed package names');
check(empty($pkg_hits), 'no file names a removed package', implode('; ', $pkg_hits));

harness_finish();
