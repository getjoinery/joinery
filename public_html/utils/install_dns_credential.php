#!/usr/bin/php
<?php
/**
 * install_dns_credential.php — keep an installer's DNS credential for the
 * setup wizard's one DNS publish.
 *
 * A first-boot installer that created the site's DNS records holds a token
 * the wizard needs once more, minutes later, for the mail records. This
 * script seals it into the site (DnsInstallCredential); the wizard's publish
 * uses it once and deletes it.
 *
 * The value arrives as one JSON object on stdin, never on argv — it is a
 * secret and argv is visible to every process on the box:
 *
 *   php utils/install_dns_credential.php <<'EOF'
 *   {"driver":"linode","credential":{"access_token":"…"}}
 *   EOF
 *
 * Prints INSTALL_DNS_CREDENTIAL=ok or =error; exits 0 on success, 2 on
 * unusable input, 1 on a write that failed.
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

$supplied = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($supplied)) {
	fwrite(STDERR, "INSTALL_DNS_CREDENTIAL=error\nThis script takes a JSON object on stdin.\n");
	exit(2);
}
$driver = trim((string)($supplied['driver'] ?? ''));
$credential = is_array($supplied['credential'] ?? null) ? $supplied['credential'] : array();
if ($driver === '' || !$credential) {
	fwrite(STDERR, "INSTALL_DNS_CREDENTIAL=error\nBoth driver and credential are required.\n");
	exit(2);
}
try {
	DnsInstallCredential::store($driver, $credential);
} catch (InvalidArgumentException $e) {
	fwrite(STDERR, "INSTALL_DNS_CREDENTIAL=error\n" . $e->getMessage() . "\n");
	exit(2);
} catch (Throwable $e) {
	fwrite(STDERR, "INSTALL_DNS_CREDENTIAL=error\n" . $e->getMessage() . "\n");
	exit(1);
}
echo "INSTALL_DNS_CREDENTIAL=ok\n";
echo 'driver=' . $driver . "\n";
exit(0);
