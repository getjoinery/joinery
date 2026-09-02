#!/usr/bin/php
<?php
/**
 * fleet_enroll.php — seed this site's hosted-relay (fleet) credentials.
 *
 * When this site was bought on a hosting tier that carries a fleet slot, the
 * operator's management node mints an API key for the buyer's account on the
 * fleet service and hands this site three values: where the service is, and
 * the key pair that authenticates to it as that buyer. The mailbox Setup tab
 * reads them and offers a one-click Enroll; the DNS ownership proofs and the
 * MX edit stay the owner's own acts.
 *
 * This script is invoked by the agent's fleet_enroll primitive, which verifies
 * it against the signed release manifest before running it.
 *
 * THE SETTING NAMES ARE HERE, NOT ON THE WIRE. What arrives is three VALUES;
 * which settings they land in is decided by the list below, on this machine,
 * in a file covered by the release manifest.
 *
 * Values arrive as one JSON object on stdin, never on argv — one of them is a
 * secret and argv is visible to every process on the box:
 *
 *   php utils/fleet_enroll.php <<'EOF'
 *   {"service_url":"https://operator.example.com",
 *    "public_key":"public_…","secret_key":"secret_…"}
 *   EOF
 *
 * All three are required: a half-seeded site offers an Enroll that cannot work.
 *
 * Writes go through Setting::put, which refuses a name that is not declared.
 * The three names are the mailbox plugin's, so on a site where that plugin is
 * not active the write is refused and this script says so — there is nothing
 * to enroll there.
 *
 * Prints one line — FLEET_ENROLL=ok or =error — and exits 0 on success, 2 on
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

/**
 * The three settings this script may write, and which incoming value fills
 * each. This map is the security boundary: an entry here is a change to what
 * the management node can reach on every node in the fleet.
 */
$fleet_settings = array(
	'mailbox_fleet_service_url'    => 'service_url',
	'mailbox_fleet_api_public_key' => 'public_key',
	'mailbox_fleet_api_secret_key' => 'secret_key',
);

$raw = stream_get_contents(STDIN);
$supplied = json_decode((string)$raw, true);
if (!is_array($supplied)) {
	fwrite(STDERR, "FLEET_ENROLL=error\nThis script takes its three values as a JSON object on stdin.\n");
	exit(2);
}

$values = array(
	'service_url' => trim((string)($supplied['service_url'] ?? '')),
	'public_key'  => trim((string)($supplied['public_key'] ?? '')),
	'secret_key'  => trim((string)($supplied['secret_key'] ?? '')),
);

// The agent's parameter spec already refuses these shapes, and so does this
// script: the same bounds, checked where the row that holds them can be read.
if (!preg_match('#^https://[A-Za-z0-9.\-]+(:[0-9]{1,5})?(/[A-Za-z0-9.\-/_]*)?$#', $values['service_url'])) {
	fwrite(STDERR, "FLEET_ENROLL=error\nThe service URL must be an https address with no query string.\n");
	exit(2);
}
if (!preg_match('/^public_[a-z0-9]{8,64}$/', $values['public_key'])
		|| !preg_match('/^secret_[a-z0-9]{8,64}$/', $values['secret_key'])) {
	fwrite(STDERR, "FLEET_ENROLL=error\nThe key pair is not in the shape the platform mints.\n");
	exit(2);
}

$written = array();
foreach ($fleet_settings as $setting => $key) {
	try {
		Setting::put($setting, $values[$key]);
	} catch (Throwable $e) {
		// A declared-settings refusal (the mailbox plugin is not active here),
		// or the database. Either way the site may be half-written, which is
		// worth saying: the caller re-dispatches from desired state.
		fwrite(STDERR, "FLEET_ENROLL=error\n" . $setting . ': ' . $e->getMessage() . "\n");
		if ($written) {
			fwrite(STDERR, 'Already written: ' . implode(', ', $written) . "\n");
		}
		exit(1);
	}
	$written[] = $setting;
}

echo "FLEET_ENROLL=ok\n";
echo 'service_url=' . $values['service_url'] . "\n";
exit(0);
