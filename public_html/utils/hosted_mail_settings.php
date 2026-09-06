#!/usr/bin/php
<?php
/**
 * hosted_mail_settings.php — set this deployment's outbound mail credentials.
 *
 * A site whose hosting somebody else runs does not open a mail-provider account
 * of its own. Its operator creates a subaccount, mints one SMTP user inside it,
 * and hands that user to this box. This script is how those values land.
 *
 * THE SETTING NAMES ARE HERE, NOT ON THE WIRE. That is the whole point of the
 * script existing rather than the management node writing rows itself. What
 * arrives is nine VALUES; which settings they land in is decided by the list
 * below, on this machine, in a file the release manifest covers.
 *
 * It matters more here than anywhere else in the vocabulary. A site whose
 * outbound mail can be pointed somewhere else is a site whose password-reset
 * emails can be pointed somewhere else — so a generic write-a-setting path
 * would not merely expose stg_settings, it would hand over the accounts. A
 * general one was built during this feature and removed for exactly that
 * reason; if a future change is tempted to reintroduce one, this is the
 * paragraph to argue with.
 *
 * THESE ARE ORDINARY, LOCALLY EDITABLE SETTINGS. Nothing marks them off-limits
 * to the site's own admin, and that is deliberate: an owner who outgrows the
 * hosted allowance moves to their own mail account by editing these very fields
 * on their own settings page. The operator writes them once; the owner owns
 * them.
 *
 * THE REACH IS EVERY MANAGED NODE, AND THAT IS ACCEPTED RATHER THAN OVERLOOKED
 * (owner, 2026-09-06). Compiled names bound WHICH settings this can write; they
 * do not bound WHICH NODES. Any deployment whose agent offers this primitive can
 * be handed these nine values by the management node it joined — a site hosted
 * by that operator, a site on the buyer's own cloud account, or a site somebody
 * self-hosts and merely enrolled for management. So a management node can point
 * any site it manages at a mail server of its choosing, and that includes the
 * mail that carries password resets.
 *
 * The reasoning, recorded so nobody has to reconstruct it: enrolling a node
 * already grants that management node apply_update, the three restores and
 * decommission_site — it can replace the site's code outright, which subsumes
 * redirecting its mail. The one distinction weighed against that is that those
 * powers are LOUD and this one is QUIET: nothing on the site looks wrong, and
 * the first symptom is somebody else receiving a reset. The owner accepted the
 * quiet case on the ground that a node's operator has already extended total
 * trust to the management node it joined.
 *
 * If that is ever revisited, the shape is a birth marker: a `managed` setting
 * written by install.sh only when the hosted bootstrap passes a flag, refused
 * here when absent. It would take effect on newly installed nodes only.
 *
 * Values arrive as one JSON object on stdin:
 *
 *   php utils/hosted_mail_settings.php <<'EOF'
 *   {"service":"smtp","host":"mail.example.net","port":"587",
 *    "username":"site-a1b2c3","password":"…","sender":"bounces@mail.example.com",
 *    "helo":"mail.example.com","hostname":"mail.example.com"}
 *   EOF
 *
 * EVERY KEY IS WRITTEN EVERY TIME, including the ones that arrive empty, so an
 * omitted value CLEARS its setting. That is what makes a push converge on a
 * desired state rather than only ever adding to it.
 *
 * DO NOT BUILD A CALLER THAT CLEARS THESE TO HAND A SITE BACK. The off-ramp for
 * a customer who outgrows the hosted mail allowance is that THEY open their own
 * provider account and type their own credentials into these very fields — and
 * a push of empties arriving afterwards would wipe what they typed and stop
 * their site sending. The operator's side of that hand-back is closing the
 * subaccount, which is done at the provider and touches nothing here. Nothing
 * calls this with empties today; the convergent shape exists so a REPLACEMENT
 * credential fully supersedes the one before it, not so a site can be blanked.
 *
 * Writes go through Setting::put, which refuses a name not declared in
 * settings.json — so a typo fails loudly here instead of minting a row nothing
 * reads.
 *
 * EIGHT VALUES ARRIVE AND NINE SETTINGS ARE WRITTEN: smtp_auth is derived here
 * from whether a username was supplied, because that is not a judgement the
 * wire needs to carry.
 *
 * Prints HOSTED_MAIL_SETTINGS=ok with the names written and NEVER a value (one
 * of them is a password, and job output is stored on the management node and
 * read by people). Exits 0 on success, 2 on unusable input, 1 on a failed write.
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
 * The nine settings this script may write, and which incoming value fills each.
 *
 * This map is the security boundary. Adding an entry widens what a management
 * node can reach on every node in the fleet, and it belongs in a commit
 * somebody reviews — not in a parameter.
 */
$mail_settings = array(
	'email_service' => 'service',
	'smtp_host'     => 'host',
	'smtp_port'     => 'port',
	'smtp_username' => 'username',
	'smtp_password' => 'password',
	'smtp_sender'   => 'sender',
	'smtp_helo'     => 'helo',
	'smtp_hostname' => 'hostname',
);

/** The providers this script will point the site at. */
$allowed_services = array('smtp', '');

$raw = stream_get_contents(STDIN);
$supplied = json_decode((string)$raw, true);
if (!is_array($supplied)) {
	fwrite(STDERR, "HOSTED_MAIL_SETTINGS=error\nThis script takes its values as a JSON object on stdin.\n");
	exit(2);
}

$service = trim((string)($supplied['service'] ?? ''));
if (!in_array($service, $allowed_services, true)) {
	// The agent's parameter spec refuses this too. Checked again here because
	// this map is what decides where the site sends its mail, and a second
	// string comparison is a cheap place to be certain.
	fwrite(STDERR, "HOSTED_MAIL_SETTINGS=error\nMail service '" . $service . "' is not one this script sets.\n");
	exit(2);
}

// Turning mail ON needs somewhere to send. Half a configuration is worse than
// none: the site would believe it was configured and every send would fail with
// an authentication error rather than the truth.
$host = trim((string)($supplied['host'] ?? ''));
if ($service !== '' && $host === '') {
	fwrite(STDERR, "HOSTED_MAIL_SETTINGS=error\nA mail service needs a host to send through.\n");
	exit(2);
}

$values = array(
	'service'  => $service,
	'host'     => $host,
	'port'     => trim((string)($supplied['port'] ?? '')),
	'username' => trim((string)($supplied['username'] ?? '')),
	// NOT trimmed: leading or trailing whitespace in a minted password is the
	// provider's business, and silently altering a credential produces an
	// authentication failure nobody can explain.
	'password' => (string)($supplied['password'] ?? ''),
	'sender'   => trim((string)($supplied['sender'] ?? '')),
	'helo'     => trim((string)($supplied['helo'] ?? '')),
	'hostname' => trim((string)($supplied['hostname'] ?? '')),
);

$written = array();
foreach ($mail_settings as $setting => $key) {
	try {
		Setting::put($setting, $values[$key]);
	} catch (Throwable $e) {
		// A declared-settings refusal, or the database. Either way the box is
		// now half-written, which is worth saying out loud: the caller
		// re-dispatches from desired state, so the next push repairs it.
		fwrite(STDERR, "HOSTED_MAIL_SETTINGS=error\n" . $setting . ': ' . $e->getMessage() . "\n");
		if ($written) {
			fwrite(STDERR, 'Already written: ' . implode(', ', $written) . "\n");
		}
		exit(1);
	}
	$written[] = $setting;
}

// smtp_auth is derived rather than sent: authentication is required exactly
// when a username was supplied, and that is not a judgement the wire needs to
// carry.
try {
	Setting::put('smtp_auth', $values['username'] !== '' ? '1' : '0');
	$written[] = 'smtp_auth';
} catch (Throwable $e) {
	fwrite(STDERR, "HOSTED_MAIL_SETTINGS=error\nsmtp_auth: " . $e->getMessage() . "\n");
	exit(1);
}

// Names only, never values.
echo "HOSTED_MAIL_SETTINGS=ok\n";
echo 'written=' . implode(',', $written) . "\n";
echo 'service=' . ($service === '' ? '(cleared)' : $service) . "\n";
exit(0);
