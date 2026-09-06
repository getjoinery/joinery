#!/usr/bin/php
<?php
/**
 * hosted_plan_notice.php — set this deployment's hosting-banner facts.
 *
 * A deployment somebody else runs and pays for carries five settings nothing
 * local edits: where the hosting stands, the date the current state runs to, a
 * sentence the operator wants its admins to read, how much of each allowance is
 * used, and where the owner manages it. HostedPlanNotice renders the banner
 * from them, and an empty state renders nothing at all — which is what keeps
 * every self-hosted deployment silent. All five are declared `managed` in
 * settings.json, so they stay off this site's own settings page: the management
 * node is their only author.
 *
 * THE SETTING NAMES ARE HERE, NOT ON THE WIRE — the same shape, and for the
 * same reason, as managed_domain_notice. What arrives is five VALUES; which
 * settings they land in is decided by the list below, on this machine.
 *
 * WHAT THIS ONE MAY SAY IS DELIBERATELY INERT. Every value here renders as
 * escaped text on an admin page, so the worst a compromised management node
 * achieves through it is a misleading sentence about somebody's billing. The
 * mail credentials are NOT here — they are hosted_mail_settings' — because
 * those can redirect a site's password-reset email, and the two must not share
 * a doorway.
 *
 * Values arrive as one JSON object on stdin:
 *
 *   php utils/hosted_plan_notice.php <<'EOF'
 *   {"state":"trial","until_time":"2026-11-05 00:00:00","notice":"",
 *    "allowances":"[{\"label\":\"Email sent this month\",\"percent\":12}]",
 *    "manage_url":"https://…/profile/server_manager"}
 *   EOF
 *
 * EVERY KEY IS WRITTEN EVERY TIME, including the ones that arrive empty, so an
 * omitted value CLEARS its setting. That is what retires a trial countdown once
 * the trial is over, and what returns a box to silence when its hosting ends.
 *
 * Prints HOSTED_PLAN_NOTICE=ok or =error; exits 0 on success, 2 on unusable
 * input, 1 on a write that failed.
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
 * The five settings this script may write, and which incoming value fills each.
 * This map is the security boundary; adding an entry is a reviewed commit.
 */
$notice_settings = array(
	'hosted_plan_state'      => 'state',
	'hosted_plan_until_time' => 'until_time',
	'hosted_plan_notice'     => 'notice',
	'hosted_plan_allowances' => 'allowances',
	'hosted_plan_manage_url' => 'manage_url',
);

/** The billing states the banner renders. Anything else renders nothing. */
$plan_states = array('trial', 'subscribed', 'grace', 'shutdown', '');

$raw = stream_get_contents(STDIN);
$supplied = json_decode((string)$raw, true);
if (!is_array($supplied)) {
	fwrite(STDERR, "HOSTED_PLAN_NOTICE=error\nThis script takes its five values as a JSON object on stdin.\n");
	exit(2);
}

$state = trim((string)($supplied['state'] ?? ''));
if (!in_array($state, $plan_states, true)) {
	fwrite(STDERR, "HOSTED_PLAN_NOTICE=error\nHosting state '" . $state . "' is not one this platform renders.\n");
	exit(2);
}

$expiry = trim((string)($supplied['until_time'] ?? ''));
if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $expiry)) {
	fwrite(STDERR, "HOSTED_PLAN_NOTICE=error\nThe date " . $expiry . " is not one this notice can carry.\n");
	exit(2);
}

$values = array(
	'state'      => $state,
	'until_time' => $expiry,
	'notice'     => trim((string)($supplied['notice'] ?? '')),
	'allowances' => trim((string)($supplied['allowances'] ?? '')),
	'manage_url' => trim((string)($supplied['manage_url'] ?? '')),
);

$written = array();
foreach ($notice_settings as $setting => $key) {
	try {
		Setting::put($setting, $values[$key]);
	} catch (Throwable $e) {
		fwrite(STDERR, "HOSTED_PLAN_NOTICE=error\n" . $setting . ': ' . $e->getMessage() . "\n");
		if ($written) {
			fwrite(STDERR, 'Already written: ' . implode(', ', $written) . "\n");
		}
		exit(1);
	}
	$written[] = $setting;
}

echo "HOSTED_PLAN_NOTICE=ok\n";
echo 'state=' . ($state === '' ? '(silent)' : $state) . "\n";
exit(0);
