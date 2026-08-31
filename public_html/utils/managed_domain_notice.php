#!/usr/bin/php
<?php
/**
 * managed_domain_notice.php — set this deployment's managed-domain facts.
 *
 * A deployment whose domain was bought for it at checkout carries four settings
 * that nothing local edits: the domain, when the registration runs out, where
 * its custody currently sits, and where the owner goes to take it over.
 * ManagedDomainNotice renders the take-ownership countdown from them, and an
 * empty custody state renders nothing at all — which is what keeps every
 * ordinary deployment silent. All four are declared `managed` in settings.json,
 * so they are kept off this site's own settings page: the management node is
 * their only author.
 *
 * This script is how it authors them. It is invoked by the agent's
 * managed_domain_notice primitive, which verifies it against the signed release
 * manifest before running it.
 *
 * THE SETTING NAMES ARE HERE, NOT ON THE WIRE. That is the whole point of the
 * script existing rather than the management node writing the rows itself. What
 * arrives is four VALUES; which settings they land in is decided by the list
 * below, on this machine, in a file covered by the release manifest. A generic
 * write-a-setting path would hand whatever is on the other end of the channel
 * the entire stg_settings table.
 *
 * Values arrive as one JSON object on stdin:
 *
 *   php utils/managed_domain_notice.php <<'EOF'
 *   {"domain":"example.com","expiry_time":"2027-03-14 09:15:00",
 *    "state":"operator_managed","manage_url":"https://…/profile/server_manager/domain"}
 *   EOF
 *
 * EVERY KEY IS WRITTEN EVERY TIME, including the ones that arrive empty. The
 * caller converges on desired state rather than adding to it, so an omitted
 * value CLEARS its setting — that is what retires a stale expiry date after a
 * renewal, and what returns a box to silence once custody is the owner's.
 *
 * Writes go through Setting::put, which refuses a name that is not declared in
 * settings.json. A typo therefore fails loudly here instead of minting a row
 * nothing reads.
 *
 * Prints one line — MANAGED_DOMAIN_NOTICE=ok or =error — and exits 0 on
 * success, 2 on unusable input, 1 on a write that failed.
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
 * The four settings this script may write, and which incoming value fills each.
 *
 * This map is the security boundary. Adding an entry is a change to what the
 * management node can reach on every node in the fleet, and it belongs in a
 * commit somebody reviews — not in a parameter.
 */
$notice_settings = array(
	'managed_domain_name'        => 'domain',
	'managed_domain_expiry_time' => 'expiry_time',
	'managed_domain_state'       => 'state',
	'managed_domain_manage_url'  => 'manage_url',
);

/** The custody states that mean anything here. Anything else renders nothing. */
$notice_states = array('operator_managed', 'push_requested', 'push_sent', 'self_custody', '');

$raw = stream_get_contents(STDIN);
$supplied = json_decode((string)$raw, true);
if (!is_array($supplied)) {
	fwrite(STDERR, "MANAGED_DOMAIN_NOTICE=error\nThis script takes its four values as a JSON object on stdin.\n");
	exit(2);
}

$domain = strtolower(trim((string)($supplied['domain'] ?? '')));
if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
	fwrite(STDERR, "MANAGED_DOMAIN_NOTICE=error\nA notice needs the domain it is about.\n");
	exit(2);
}

$state = trim((string)($supplied['state'] ?? ''));
if (!in_array($state, $notice_states, true)) {
	// The agent's parameter spec already refuses this, and so does this script:
	// an unrecognised state reaching the notice would render a countdown nobody
	// can act on, and the second check costs a string comparison.
	fwrite(STDERR, "MANAGED_DOMAIN_NOTICE=error\nCustody state '" . $state . "' is not one this platform renders.\n");
	exit(2);
}

$values = array(
	'domain'      => $domain,
	'expiry_time' => trim((string)($supplied['expiry_time'] ?? '')),
	'state'       => $state,
	'manage_url'  => trim((string)($supplied['manage_url'] ?? '')),
);

$written = array();
foreach ($notice_settings as $setting => $key) {
	try {
		Setting::put($setting, $values[$key]);
	} catch (Throwable $e) {
		// A declared-settings refusal, or the database. Either way the box is
		// now half-written, which is worth saying out loud: the caller
		// re-dispatches from desired state, so the next push repairs it.
		fwrite(STDERR, "MANAGED_DOMAIN_NOTICE=error\n" . $setting . ': ' . $e->getMessage() . "\n");
		if ($written) {
			fwrite(STDERR, 'Already written: ' . implode(', ', $written) . "\n");
		}
		exit(1);
	}
	$written[] = $setting;
}

echo "MANAGED_DOMAIN_NOTICE=ok\n";
echo 'domain=' . $domain . "\n";
echo 'state=' . ($state === '' ? '(silent)' : $state) . "\n";
exit(0);
