<?php
/**
 * Shared test harness — one assertion surface, one result contract, one runner.
 *
 * Generalized from tests/functional/api/api_test_harness.php. Any test requires
 * this file, calls harness_boot(), builds sections of check() assertions, and
 * lets the registered shutdown reporter (or an explicit harness_finish()) emit
 * the result. The API suites layer their HTTP-specific helpers on top of this in
 * api_test_harness.php.
 *
 * Metadata lives in a parseable header comment on every runnable test so the
 * discovery runner (tests/run.php) can read a test's tier and env WITHOUT
 * executing it, and refuse to run it in the wrong environment. harness_boot()
 * reads that same header from the calling file — one source of truth:
 *
 *   /** @joinery-test
 *    * name: cloud_offload_engine
 *    * tier: safe            # safe | db | test-db | live | deploy  (blast radius)
 *    * env: dev-only         # any | prod-verify | dev-only    (where it may run)
 *    * needs: []             # e.g. [stripe-test-keys, macmini, mailgun]
 *    * /
 *
 * Result contract (consumed by run.php and the dashboard, nothing else):
 *   {name, tier, stats: {total, passed, failed, skipped},
 *    sections: [{title, checks: [{label, passed, detail?}]}], duration_ms}
 */

if (!defined('JOINERY_HARNESS_LOADED')) {
	define('JOINERY_HARNESS_LOADED', 1);
	// Marks the start of the JSON result contract on a CLI --json run, so the
	// discovery runner can locate it even after fatal-error output pollution.
	define('JOINERY_RESULT_SENTINEL', '@@JOINERY_TEST_RESULT@@');

	// The one domain a fixture address is ever minted at. Shared deliberately by
	// harness_fixture_email() and by the cleanup patterns that recognise its
	// mail: if those two ever named the domain separately they could drift, and
	// a cleanup anchored to the wrong domain either misses its own mail or
	// reaches somebody else's.
	define('HARNESS_FIXTURE_DOMAIN', 'dev.getjoinery.com');

	// ---- bootstrap ---------------------------------------------------------
	// PathHelper cannot be located through PathHelper; find it relative to this
	// file (tests/lib/harness.php → public_html root is two directories up).
	require_once(dirname(__DIR__, 2) . '/includes/PathHelper.php');
	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	// ---- shared mutable state (global script scope) ------------------------
	$GLOBALS['__harness'] = array(
		'meta'      => array('name' => '', 'tier' => 'safe', 'env' => 'dev-only', 'needs' => array()),
		'sections'  => array(),   // [{title, checks: [{label, passed, detail}]}]
		'current'   => null,      // index into sections of the open section
		'passed'    => 0,
		'failed'    => 0,
		'skipped'   => 0,
		'deferred'  => array(),   // LIFO teardown callables
		'started'   => microtime(true),
		// Identity of THIS run, used to name fixtures and to recognise the mail
		// they caused at teardown. Set once here so every fixture shares it.
		'run_token'  => bin2hex(random_bytes(4)),
		'started_utc' => gmdate('Y-m-d H:i:s'),
		'finished'  => false,
		'booted'    => false,
	);
}

// ==========================================================================
// Metadata header parsing (no execution — safe to run over any file)
// ==========================================================================

/**
 * Parse the @joinery-test header of a file into
 * ['name','tier','env','needs'], or null if the file carries no header.
 *
 * Handles both PHP block comments (`* key: value`) and shell comments
 * (`# key: value`). Inline `# ...` comments after a value are stripped, so the
 * annotated examples in the spec parse cleanly.
 */
function harness_parse_metadata($filepath) {
	if (!is_readable($filepath)) return null;
	// Only the head of the file can carry the header; cap the read.
	$head = (string)file_get_contents($filepath, false, null, 0, 4096);
	// Anchor the marker to the start of a header line (after comment framing
	// only) so a mere prose MENTION of "@joinery-test" mid-line — docs, a test
	// that describes the header format — is not mistaken for a real header and
	// discovered as a phantom test.
	if (!preg_match('/^[ \t\/*#]*(@joinery-test\b)/m', $head, $mm, PREG_OFFSET_CAPTURE)) return null;
	$marker_offset = $mm[1][1];

	// timeout_explicit records whether the header actually declared a timeout —
	// the dashboard only marks a test CLI-only when its author explicitly set a
	// long cap, so default-cap tests stay web-runnable.
	$meta = array('name' => '', 'tier' => 'safe', 'env' => 'dev-only', 'needs' => array(),
		'timeout' => 180, 'timeout_explicit' => false);
	$after = substr($head, $marker_offset);
	$lines = preg_split('/\r\n|\r|\n/', $after);
	foreach ($lines as $i => $raw) {
		if ($i === 0) continue; // the marker line itself
		// Normalize away comment framing: leading *, #, / and whitespace.
		$line = ltrim($raw, " \t*#/");
		if ($line === '' ) continue;
		// End of the comment block.
		if (strpos($raw, '*/') !== false && strpos($raw, ':') === false) break;
		if (!preg_match('/^(name|tier|env|needs|timeout)\s*:\s*(.*)$/i', $line, $m)) {
			// A non key:value line ends the header region (blank framing aside).
			if (strpos($line, '@') === 0) continue;
			break;
		}
		$key = strtolower($m[1]);
		$val = $m[2];
		// Strip a trailing inline comment (the "# safe | db | ..." annotations).
		$hash = strpos($val, '#');
		if ($hash !== false) $val = substr($val, 0, $hash);
		$val = trim($val);
		// tier/env are closed vocabularies — normalize case so a header typo
		// like `tier: Live` cannot slip past the membership checks below.
		if ($key === 'tier' || $key === 'env') $val = strtolower($val);
		if ($key === 'needs') {
			$val = trim($val, "[] \t");
			$meta['needs'] = $val === '' ? array()
				: array_values(array_filter(array_map('trim', explode(',', $val))));
		} elseif ($key === 'timeout') {
			// Per-test wall-clock cap (seconds). Non-numeric → default; clamp 1–1800.
			$meta['timeout'] = is_numeric($val) ? max(1, min(1800, (int)$val)) : 180;
			$meta['timeout_explicit'] = true;
		} else {
			$meta[$key] = $val;
		}
	}

	// Fail closed on BOTH axes. An unparseable/blank env → dev-only. An
	// unknown/mistyped tier → live: `safe` is the most-run batch (every
	// `php tests/run.php` executes it), so defaulting an unrecognized tier there
	// is fail-OPEN — a `tier: Live` typo would run a real-effect suite in the
	// pre-deploy gate. `live` never runs unless explicitly named, so a typo
	// fails safe (the test simply won't run until its header is corrected).
	if (!in_array($meta['env'], array('any', 'prod-verify', 'dev-only'), true)) $meta['env'] = 'dev-only';
	if (!in_array($meta['tier'], array('safe', 'db', 'test-db', 'live', 'deploy'), true)) $meta['tier'] = 'live';
	if ($meta['name'] === '') $meta['name'] = pathinfo($filepath, PATHINFO_FILENAME);
	return $meta;
}

// ==========================================================================
// Boot / environment enforcement
// ==========================================================================

/**
 * Bootstrap a test run. Reads the calling file's @joinery-test header (so tier
 * and env are declared exactly once, in the header the runner also reads),
 * enforces the env gate, and registers the shutdown reporter.
 *
 * $overrides lets a caller that has no file header (or wants to override it)
 * supply name/tier/env/needs directly.
 */
function harness_boot(array $overrides = array()) {
	$h = &$GLOBALS['__harness'];

	$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
	$file = $caller[0]['file'] ?? '';
	$parsed = $file ? harness_parse_metadata($file) : null;
	if ($parsed) $h['meta'] = $parsed;
	if ($overrides) $h['meta'] = array_merge($h['meta'], $overrides);

	$h['started'] = microtime(true);
	$h['booted'] = true;

	harness_enforce_env();

	// A test run must not send mail through a real delivery service. dev's
	// email_service is mailgun, so every suite that created a user or triggered a
	// notification was posting real messages to Mailgun, which delivered them —
	// roughly twenty per run piling into the production unmatched box.
	//
	// SMTP rather than dry-run on purpose: the send path stays exercised end to
	// end, it just terminates at the local relay instead of a paid service that
	// delivers to the internet. Set in memory for this process only, so nothing
	// about the site's real configuration changes.
	//
	// The transport guard alone proved insufficient: dev's local Postfix is a
	// REAL mail server (this box is the live mail test machine) and relays to
	// the internet, and alert-type mail addresses real operators rather than
	// harnesstest_ fixtures — sixty operator alerts reached a real inbox
	// through "local" SMTP on 2026-08-25. So recipients are guarded too:
	// email_test_mode redirects every send to the joineryemailtests store
	// alias, where suites can inspect it in iem_inbound_email_messages and
	// nothing reaches a person. The original recipients ride the subject line.
	//
	// A test that is ABOUT a provider or about real delivery overrides these
	// after boot — harness_set_setting_mem() is exactly the intended escape
	// (inbound_forwarding_relay and setup_topology already use it for the
	// transport; a suite asserting delivery to a specific address sets
	// email_test_mode '0' the same way).
	//
	// SCOPE, stated plainly: this is THIS PROCESS ONLY. A suite that drives the
	// web server (needs=dev-web — the functional API tests) makes its requests
	// in Apache, which reads the site's real email_service and will happily send
	// through the paid provider. Fixture addresses live on the dev inbound
	// domain so nothing escapes to a stranger, but the send is real. Closing
	// that needs a cross-process signal, which does not exist yet.
	if (($h['meta']['env'] ?? '') !== 'prod-verify') {
		harness_set_setting_mem('email_service', 'smtp');
		harness_set_setting_mem('email_fallback_service', 'smtp');
		harness_set_setting_mem('email_test_mode', '1');
		harness_set_setting_mem('email_test_recipient', 'joineryemailtests@' . HARNESS_FIXTURE_DOMAIN);
		$h['test_recipient'] = 'joineryemailtests@' . HARNESS_FIXTURE_DOMAIN;

		// Mail this run sends is cleaned up at BOTH ends, because one end is not
		// enough. Redirecting it keeps it away from people; this is what keeps it
		// from accumulating for good, since a message delivered to an address no
		// alias claims is in no mailbox and so is never trashed by anyone.
		//
		// Both passes gate themselves — see harness_mail_cleanup_allowed(). They
		// are DELETE passes, and this block is reached by more than the dev box:
		// a deploy-tier test declares `env: any` and runs on a customer node.
		//
		// Now: whatever earlier runs left behind, which has certainly finished
		// being delivered. This is the pass that actually empties the box.
		harness_cleanup_stale_delivered_mail();

		// And at the end: registered FIRST so LIFO teardown runs it LAST, giving
		// the relay every spare moment to hand over what this run sent. It still
		// misses anything delivered after that — measured, not assumed — which is
		// exactly what the boot pass above collects on the next run.
		harness_defer('harness_cleanup_delivered_mail');
	}

	register_shutdown_function('harness_shutdown_report');

	// Graceful SIGTERM handling (CLI only): the runner wraps each test in
	// `timeout -k 5s`, which sends SIGTERM then SIGKILL 5s later. On plain
	// SIGKILL, register_shutdown_function never runs and teardown is skipped —
	// stranding fixtures / Stripe objects / emulator processes. Converting
	// SIGTERM into exit(1) lets the shutdown reporter run teardown and emit a
	// failing contract inside the 5s grace window. Needs the pcntl extension.
	//
	// Ctrl-C is the same problem arriving as a different signal: PHP's default
	// disposition for SIGINT terminates the process outright, so the cleanup
	// closures — which live only in this process's memory — are lost and every
	// fixture the test created is stranded in the dev database. Converting the
	// interrupt into exit(1) runs teardown on the way out.
	if (php_sapi_name() === 'cli' && function_exists('pcntl_signal')) {
		pcntl_async_signals(true);
		pcntl_signal(SIGTERM, function () {
			$GLOBALS['__harness']['sigterm'] = true;
			exit(1);
		});
		pcntl_signal(SIGINT, function () {
			$GLOBALS['__harness']['sigint'] = true;
			exit(1);
		});
	}
}

/**
 * Runtime env enforcement — the second layer behind run.php's pre-spawn gate,
 * so invoking a dev-only test directly by path on production still refuses.
 *
 * The `debug` setting is the platform's master dev/prod discriminator (1 on
 * dev, 0 on prod; StripeHelper keys live-vs-test payments off it). `dev-only`
 * tests refuse unless it is on. `any` (read-only/pure) and `prod-verify`
 * (deliberately prod-runnable, self-cleaning) pass.
 */
function harness_enforce_env() {
	$h = &$GLOBALS['__harness'];
	$env = $h['meta']['env'];
	if ($env === 'any' || $env === 'prod-verify') return;

	// dev-only
	if (Globalvars::get_instance()->get_setting('debug')) return;

	$reason = "dev-only test '" . $h['meta']['name'] . "' refuses to run: the 'debug' setting is off "
		. "(this looks like production). No fixtures, rate limits, test-DB, or Stripe test keys are touched.";
	if (harness_wants_json()) {
		$h['skipped']++;
		harness_emit_json(array(array('title' => 'Environment', 'checks' => array(
			array('label' => 'env gate (dev-only)', 'passed' => false, 'detail' => $reason),
		))));
	} else {
		echo "SKIP: $reason\n";
	}
	$h['finished'] = true;
	exit(0);
}

// ==========================================================================
// Assertions
// ==========================================================================

/** Open a named section; subsequent check()s are grouped under it. */
function section($title) {
	$h = &$GLOBALS['__harness'];
	$h['sections'][] = array('title' => (string)$title, 'checks' => array());
	$h['current'] = count($h['sections']) - 1;
	if (!harness_wants_json() && php_sapi_name() === 'cli') echo "\n== $title ==\n";
}

/** The single assertion. Records the check into the open section (creating a
 *  default one if none is open) and updates the pass/fail counters. */
function check($condition, $label, $detail = '') {
	$h = &$GLOBALS['__harness'];
	if ($h['current'] === null) section('Tests');
	// Guard against the arg-order swap check($label, $condition): a non-empty
	// string in the condition slot is always truthy, so the real assertion would
	// be silently discarded and the check would pass unconditionally. This is not
	// a hypothetical — an entire unit suite shipped green while asserting nothing
	// because of exactly this. The signature of the swap is a human-readable
	// string where the condition belongs AND a non-string (bool/array/int, the
	// real condition) where the label belongs; a legitimate truthy-string check
	// like check($url, 'url present') always has a string label and is untouched.
	if (is_string($condition) && $condition !== '' && !is_string($label)) {
		$h['sections'][$h['current']]['checks'][] = array(
			'label' => 'check() misuse: arguments appear swapped',
			'passed' => false,
			'detail' => 'condition slot holds a string ("' . substr($condition, 0, 80)
				. '") — use ok($label, $condition) for label-first assertions',
		);
		$h['failed']++;
	}
	$passed = (bool)$condition;
	$h['sections'][$h['current']]['checks'][] = array(
		'label' => (string)$label, 'passed' => $passed, 'detail' => (string)$detail,
	);
	if ($passed) $h['passed']++; else $h['failed']++;
	if (!harness_wants_json() && php_sapi_name() === 'cli') {
		echo '  ' . ($passed ? 'PASS' : 'FAIL') . ": $label" . ($detail !== '' && !$passed ? " — $detail" : '') . "\n";
	}
	return $passed;
}

/**
 * Label-first assertion alias: ok($label, $condition, $detail=''). Many
 * hand-rolled suites wrote their pass/fail counter as ok($label, $cond); this
 * lets those bodies migrate to the shared harness unchanged.
 */
function ok($label, $condition, $detail = '') {
	return check($condition, $label, $detail);
}

/** Record a skipped check (e.g. an unmet `needs` prerequisite). */
function harness_skip($label, $detail = '') {
	$h = &$GLOBALS['__harness'];
	if ($h['current'] === null) section('Tests');
	$h['sections'][$h['current']]['checks'][] = array(
		'label' => (string)$label, 'passed' => null, 'detail' => (string)$detail,
	);
	$h['skipped']++;
	if (!harness_wants_json() && php_sapi_name() === 'cli') echo "  SKIP: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

// ==========================================================================
// Fixtures + LIFO teardown
// ==========================================================================

/** Register an arbitrary teardown callable; run in LIFO order at finish. */
function harness_defer(callable $fn) {
	$GLOBALS['__harness']['deferred'][] = $fn;
}

/**
 * The address a fixture should use when it needs a real, deliverable one.
 *
 * ONE naming rule, owned here, because two things depend on the shape: a
 * fixture address must not collide with the next run's (a leftover user from a
 * SIGKILLed run would otherwise take the email and the next run fails with
 * "email already been used"), and teardown has to be able to recognise the mail
 * these addresses received without knowing which suite sent it. A suite that
 * invents its own address gets neither.
 *
 * The domain is the dev inbound domain deliberately: mail to it is delivered
 * and stored locally, so nothing escapes to a stranger even when a send is
 * real, and harness_cleanup_delivered_mail() can then remove it.
 */
function harness_fixture_email($label) {
	$h = &$GLOBALS['__harness'];
	return 'harnesstest_' . strtolower($label) . '_' . $h['run_token'] . '@' . HARNESS_FIXTURE_DOMAIN;
}

/**
 * May this process delete delivered test mail at all?
 *
 * Three conditions, and the first is the one that matters: THIS IS A DELETE
 * PASS, so it must never run anywhere but the dev box.
 *
 *  - debug on. The platform's own dev/prod discriminator, the same one
 *    harness_enforce_env() trusts. env alone is NOT enough: deploy-tier tests
 *    declare `env: any` and run on customer production nodes via
 *    `run.php deploy`, so an env check would have let a permanent-delete sweep
 *    loose on a customer's database.
 *  - a tier that can actually send. `safe` promises "no persistent side
 *    effects" and cannot send mail without breaking that promise, so a delete
 *    pass there would earn nothing and cost the tier its contract. `deploy`
 *    reads only and assumes no repository.
 *  - a store alias to match. Without one there is nothing to recognise.
 *
 * The domain anchor on both patterns is the other half of the same care: a
 * customer's own mail to an address starting harnesstest_ at THEIR domain is
 * not ours to delete, and only HARNESS_FIXTURE_DOMAIN is ever minted here.
 */
function harness_mail_cleanup_allowed() {
	$h = &$GLOBALS['__harness'];

	if (trim((string)($h['test_recipient'] ?? '')) === '') {
		return false;
	}
	if (!in_array($h['meta']['tier'] ?? '', array('db', 'test-db', 'live'), true)) {
		return false;
	}
	if (!Globalvars::get_instance()->get_setting('debug')) {
		return false;
	}
	return class_exists('InboundEmailMessage');
}

/**
 * Remove the mail this run caused to be delivered and stored.
 *
 * A test run sends real mail. harness_boot() points it at the local relay and
 * redirects every recipient to the test store alias, so nothing reaches a
 * person — but the relay is a real mail server, so those messages are really
 * delivered and land in iem_inbound_email_messages addressed to an address no
 * alias claims. Nothing then removes them: unmatched mail sits in no mailbox,
 * so nobody trashes it. Left alone the suites deposit ~1,550 messages a month
 * into dev's Unmatched box.
 *
 * Two shapes are ours, and only these two:
 *  - the test store alias, holding everything email_test_mode redirected;
 *  - harness_fixture_email() addresses, which receive mail sent by a process
 *    this one cannot configure. A dev-web suite makes its requests inside
 *    Apache, which reads the site's real email settings and sends for real, so
 *    the redirect above never applies to it — but the recipient is still a
 *    fixture address, and that is enough to recognise it here.
 *
 * Bounded to mail received since this run booted, so it can only ever remove
 * what this run could have caused. Restricted to NULL-alias rows, so a suite
 * that delivers into a real mailbox on purpose keeps its evidence.
 *
 * Deletion goes through permanent_delete() per row, which reclaims the
 * attachment Files and the stored raw object; a bulk DELETE would leak both.
 *
 * BOTH passes match on iem_recipient as plaintext, which is true today only
 * because an inbound row never seals it — it is the receiving address, routing
 * metadata rather than content, even on an otherwise sealed row. iem_recipient
 * IS in $sealed_fields for the outbound case, so if unmatched-mail sealing ever
 * extends that to inbound (specs/mailbox_unmatched_sealing.md), both passes
 * quietly stop matching and the accumulation returns with nothing failing.
 *
 * Teardown alone does not empty the box, and measurement is what says so:
 * delivery is asynchronous, and a suite that sends on its way out is still
 * being delivered when teardown finishes. Registering this first (LIFO makes it
 * the LAST teardown step) buys every spare moment and is not enough — a real
 * run was observed leaving one message that arrived seconds after the sweep.
 *
 * So it runs at BOTH ends, and the boot pass is the one that actually collects
 * stragglers: what the previous run sent has certainly landed by the time the
 * next one starts. See harness_cleanup_stale_delivered_mail().
 */
function harness_cleanup_delivered_mail() {
	$h = &$GLOBALS['__harness'];

	if (!harness_mail_cleanup_allowed()) {
		return;
	}

	$recipient = trim((string)$h['test_recipient']);
	$token = $h['run_token'];
	$since = $h['started_utc'];

	try {
		$db = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT iem_inbound_email_message_id AS id
		          FROM iem_inbound_email_messages
		         WHERE iem_iea_inbound_email_alias_id IS NULL
		           AND iem_direction = 'inbound'
		           AND iem_received_time >= ?
		           AND (iem_recipient = ? OR iem_recipient LIKE ?)";
		$q = $db->prepare($sql);
		$q->execute(array($since, $recipient,
			'harnesstest\_%\_' . $token . '@' . HARNESS_FIXTURE_DOMAIN));
		$ids = $q->fetchAll(PDO::FETCH_COLUMN);
	} catch (\Throwable $e) {
		echo "  WARNING: could not look up delivered test mail: " . $e->getMessage() . "\n";
		return;
	}

	$removed = 0;
	foreach ($ids as $id) {
		try {
			$msg = new InboundEmailMessage((int)$id, TRUE);
			if (!$msg->key) { continue; }
			$msg->permanent_delete();
			$removed++;
		} catch (\Throwable $e) {
			// One unreclaimable message must not cost the rest their cleanup,
			// and must never fail the test that has already passed.
			echo "  WARNING: could not remove delivered test mail $id: " . $e->getMessage() . "\n";
		}
	}
	if ($removed) {
		echo "  cleaned up $removed delivered test message(s)\n";
	}
}

/**
 * Collect what earlier runs left behind, at boot.
 *
 * This is the half that actually works, and the reason is timing rather than
 * cleverness: mail a previous run sent has certainly been delivered by the time
 * the next run starts, whereas mail THIS run sends may still be in flight when
 * its own teardown fires. The end-of-run pass takes what it can reach; this one
 * takes the rest, one run later.
 *
 * Two differences from the teardown pass, both deliberate:
 *
 *  - it matches ANY fixture address, not just this run's, because the whole
 *    point is collecting somebody else's leftovers;
 *  - it therefore refuses to touch anything recent. SETTLE_SECONDS is a floor,
 *    not a delay: a suite asserting on mail it just sent does so within seconds,
 *    and lanes run alongside each other, so anything younger than the floor may
 *    belong to a run still in progress. Older than that and no live assertion
 *    can be depending on it.
 *
 * The gap between the two passes — a straggler younger than the floor when the
 * next run boots — closes on the run after that, and mailbox_unmatched_retention_days
 * is the long backstop under both.
 */
function harness_cleanup_stale_delivered_mail() {
	$h = &$GLOBALS['__harness'];

	// Old enough that no in-flight suite can still be asserting on it.
	$settle_seconds = 600;

	if (!harness_mail_cleanup_allowed()) {
		return;
	}

	$recipient = trim((string)$h['test_recipient']);

	try {
		$db = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT iem_inbound_email_message_id AS id
		          FROM iem_inbound_email_messages
		         WHERE iem_iea_inbound_email_alias_id IS NULL
		           AND iem_direction = 'inbound'
		           AND iem_received_time < ?
		           AND (iem_recipient = ? OR iem_recipient LIKE ?)
		         LIMIT 500";
		$q = $db->prepare($sql);
		$q->execute(array(
			gmdate('Y-m-d H:i:s', time() - $settle_seconds),
			$recipient,
			'harnesstest\_%@' . HARNESS_FIXTURE_DOMAIN,
		));
		$ids = $q->fetchAll(PDO::FETCH_COLUMN);
	} catch (\Throwable $e) {
		echo "  WARNING: could not look up stale test mail: " . $e->getMessage() . "\n";
		return;
	}

	$removed = 0;
	foreach ($ids as $id) {
		try {
			$msg = new InboundEmailMessage((int)$id, TRUE);
			if (!$msg->key) { continue; }
			$msg->permanent_delete();
			$removed++;
		} catch (\Throwable $e) {
			// Never fail a run over housekeeping for a previous one.
			echo "  WARNING: could not remove stale test mail $id: " . $e->getMessage() . "\n";
		}
	}
	if ($removed) {
		echo "  cleaned up $removed stale test message(s) from earlier runs\n";
	}
}

/**
 * Create a test user at the given permission level, registered for cleanup.
 * Email is unique per $suffix AND per process: the random run token means a
 * leftover user from a killed run (SIGKILL skips teardown) can never collide
 * with the next run's same-suffix fixture ("email already been used").
 */
function make_user($suffix, $permission = 0) {
	// Building a fixture is its own unit of work: every value below is generated
	// right here, so none of it can be sealed content this suite decrypted
	// earlier. Without the boundary, any suite that reads protected data before
	// creating its next user would be refused by the hot-turn rule
	// (includes/SealedEgressGuard.php) for a write that leaks nothing.
	require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
	return SealedEgressGuard::isolate(function () use ($suffix, $permission) {
		return make_user_row($suffix, $permission);
	});
}

function make_user_row($suffix, $permission = 0) {
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	$user = new User(NULL);
	$user->set('usr_first_name', 'HarnessTest');
	$user->set('usr_last_name', 'User' . $suffix);
	$user->set('usr_email', harness_fixture_email($suffix));
	$user->set('usr_password', User::GeneratePassword('TestPassword_' . $suffix));
	$user->set('usr_permission', $permission);
	$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
	// A harness user is a usable user: activated, so it can sign in through
	// either door when activation_required_login is on. Tests about the
	// activation gate itself set usr_is_activated back to false explicitly.
	$user->set('usr_is_activated', true);
	// And already past onboarding, for the same reason. The first-login setup
	// wizard interrupts EVERY page for an account that has never dismissed it
	// while the deployment has outstanding steps (SetupSteps::shouldInterrupt),
	// so without this a fixture's web session is redirected to /setup before it
	// ever reaches the page under test — which reads as a login failure, or as
	// a permission gate answering 302 instead of 401, in whichever suite hits
	// it. Tests about the wizard itself clear this back to null explicitly.
	$user->set('usr_setup_dismissed_time', gmdate('Y-m-d H:i:s'));
	$user->save();
	$user->load();
	harness_register_user($user);
	return $user;
}

/**
 * Create a machine API key for $user_id ($permission: 1=read, 2=write, 3=r+w,
 * 4=+delete), registered for cleanup. Returns ['api_key'=>ApiKey,'secret_key'=>plaintext].
 */
function make_machine_key($user_id, $name, $permission = 4) {
	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
	$secret_plaintext = 'secret_' . LibraryFunctions::random_string(16);
	$key = new ApiKey(NULL);
	$key->set('apk_usr_user_id', $user_id);
	$key->set('apk_name', $name);
	$key->set('apk_public_key', 'public_' . LibraryFunctions::random_string(16));
	$key->set('apk_secret_key', ApiKey::GenerateKey($secret_plaintext));
	$key->set('apk_type', ApiKey::TYPE_MACHINE);
	$key->set('apk_permission', $permission);
	$key->set('apk_is_active', TRUE);
	$key->save();
	$key->load();
	harness_register_key_id($key->key);
	return array('api_key' => $key, 'secret_key' => $secret_plaintext);
}

/** Register a created row for teardown (deleted LIFO with everything else). */
function harness_register_row($table, $pkey_column, $id) {
	harness_defer(function () use ($table, $pkey_column, $id) {
		$db = DbConnector::get_instance()->get_db_link();
		try {
			$q = $db->prepare("DELETE FROM $table WHERE $pkey_column = ?");
			$q->execute(array($id));
		} catch (\Throwable $e) {
			echo "  WARNING: could not delete $table row $id: " . $e->getMessage() . "\n";
		}
	});
}

function harness_register_key_id($id) {
	harness_defer(function () use ($id) {
		$db = DbConnector::get_instance()->get_db_link();
		try {
			$q = $db->prepare("DELETE FROM apk_api_keys WHERE apk_api_key_id = ?");
			$q->execute(array($id));
		} catch (\Throwable $e) {
			echo "  WARNING: could not delete api key $id: " . $e->getMessage() . "\n";
		}
	});
}

/**
 * Register a User object for teardown: permanent_delete with a soft-delete
 * fallback so a test account can never be logged into even if the FK sweep fails.
 */
function harness_register_user($user) {
	harness_defer(function () use ($user) {
		$db = DbConnector::get_instance()->get_db_link();
		try {
			$user->permanent_delete();
		} catch (\Throwable $e) {
			echo "  WARNING: could not permanently delete user " . $user->key . " (" . $e->getMessage() . "); soft-deleting\n";
			if ($db->inTransaction()) $db->rollBack();
			try {
				$q = $db->prepare("UPDATE usr_users SET usr_delete_time = now() WHERE usr_user_id = ?");
				$q->execute(array($user->key));
			} catch (\Throwable $e2) {
				echo "  WARNING: soft delete also failed for user " . $user->key . ": " . $e2->getMessage() . "\n";
			}
		}
	});
}

/** Run every deferred teardown callable in LIFO order. Safe after an exception.
 *  Catches \Throwable (not just Exception) so a TypeError/Error in one teardown
 *  closure cannot abort the remaining teardowns or escape to the caller — an
 *  escaping Error would skip the result-contract emit and lose every check. */
function harness_teardown_data() {
	$h = &$GLOBALS['__harness'];
	foreach (array_reverse($h['deferred']) as $fn) {
		try { $fn(); } catch (\Throwable $e) { echo "  WARNING: teardown step failed: " . $e->getMessage() . "\n"; }
	}
	$h['deferred'] = array();
}

// ==========================================================================
// Settings — raw DB accessors and in-memory (this-process-only) overrides
// ==========================================================================

function get_setting_raw($name) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
	$q->execute(array($name));
	$row = $q->fetch(PDO::FETCH_ASSOC);
	return $row ? $row['stg_value'] : null;
}

function set_setting_raw($name, $value) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?");
	$q->execute(array($value, $name));
}

/**
 * Snapshot the Globalvars in-memory settings cache (for restore below).
 *
 * Reflection reaches private members without `setAccessible(true)` — that has
 * been a no-op since PHP 8.1 and is deprecated in 8.5. Do not add it back here
 * in particular: this function runs in most tests, and the deprecation it
 * raised became the last entry in `error_get_last()`, which is what
 * harness_shutdown_report() prints when a test dies. Every crash on 8.5 was
 * therefore reported as "setAccessible is deprecated" regardless of its actual
 * cause, which cost a real debugging session.
 */
function harness_settings_snapshot() {
	$gv = Globalvars::get_instance();
	$ref = new ReflectionProperty('Globalvars', 'settings');
	$arr = $ref->getValue($gv);
	return is_array($arr) ? $arr : array();
}

/** Restore a snapshot taken by harness_settings_snapshot(). */
function harness_settings_restore($snapshot) {
	$gv = Globalvars::get_instance();
	$ref = new ReflectionProperty('Globalvars', 'settings');
	$ref->setValue($gv, $snapshot);
}

/**
 * Override one setting in the Globalvars in-memory cache only — never persisted,
 * scoped to this process. On first use it snapshots the cache and defers a
 * restore, so overrides evaporate at teardown.
 */
function harness_set_setting_mem($key, $value) {
	static $snapshotted = false;
	if (!$snapshotted) {
		$snapshot = harness_settings_snapshot();
		harness_defer(function () use ($snapshot) { harness_settings_restore($snapshot); });
		$snapshotted = true;
	}
	$gv = Globalvars::get_instance();
	$ref = new ReflectionProperty('Globalvars', 'settings');
	$arr = $ref->getValue($gv);
	if (!is_array($arr)) $arr = array();
	$arr[$key] = $value;
	$ref->setValue($gv, $arr);
}

// ==========================================================================
// Test-database mode
// ==========================================================================

/**
 * Switch DbConnector to the copied test database and defer the close, replacing
 * the copy-pasted set_test_mode()/close_test_mode() ctor/dtor pairs and the
 * "which DB am I on" banner. Emits the banner on CLI human runs.
 */
function harness_test_mode() {
	$db = DbConnector::get_instance();
	$db->set_test_mode();
	harness_defer(function () use ($db) { $db->close_test_mode(); });

	if (!harness_wants_json() && php_sapi_name() === 'cli') {
		$name = $db->get_db_link()->query('SELECT current_database()')->fetchColumn();
		echo "  Test database: $name\n";
	}
}

// ==========================================================================
// Output + finish
// ==========================================================================

/** Whether JSON output is requested (CLI --json, or web ?json=1 / ?ajax=1). */
function harness_wants_json() {
	if (php_sapi_name() === 'cli') {
		return in_array('--json', $GLOBALS['argv'] ?? array(), true);
	}
	return (isset($_GET['json']) && $_GET['json']) || (isset($_GET['ajax']) && $_GET['ajax']);
}

/**
 * Assemble and print the JSON result contract.
 *
 * On CLI the contract is prefixed with a sentinel on its own line so run.php
 * can extract it unambiguously even when a fatal error made the platform error
 * handler print its own blob to stdout first. On the web it is emitted as pure
 * application/json for direct debugging.
 */
function harness_emit_json($sections = null) {
	$h = &$GLOBALS['__harness'];
	$sections = $sections === null ? $h['sections'] : $sections;
	$contract = array(
		'name'  => $h['meta']['name'],
		'tier'  => $h['meta']['tier'],
		'env'   => $h['meta']['env'],
		'needs' => $h['meta']['needs'],
		'stats' => array(
			'total'   => $h['passed'] + $h['failed'],
			'passed'  => $h['passed'],
			'failed'  => $h['failed'],
			'skipped' => $h['skipped'],
		),
		'sections'    => $sections,
		'duration_ms' => (int)round((microtime(true) - $h['started']) * 1000),
	);
	$json = json_encode($contract);
	if (php_sapi_name() === 'cli') {
		echo "\n" . JOINERY_RESULT_SENTINEL . $json . "\n";
	} else {
		header('Content-Type: application/json');
		echo $json;
	}
}

/** Print the human summary (CLI) or emit the JSON contract, then exit. */
function harness_finish() {
	$h = &$GLOBALS['__harness'];

	// A test-db-tier suite that never switched to the copied database wrote
	// everything to the live one — the exact bug this tier exists to prevent.
	// The declaration is a promise about blast radius; hold the test to it.
	if ($h['meta']['tier'] === 'test-db' && $h['booted']
			&& !DbConnector::get_instance()->test_mode_was_used()) {
		check(false, 'a test-db tier suite must enter test-database mode',
			'no set_test_mode()/harness_test_mode() call happened — writes went to the live database');
	}

	$h['finished'] = true;

	// Deferred fixture cleanup runs on BOTH exits: here on a clean finish, and
	// in harness_shutdown_report() on a crash. Without this call a passing test
	// leaks every harness_register_* fixture (rows, users, keys) into the dev DB.
	harness_teardown_data();

	if (harness_wants_json()) {
		harness_emit_json();
	} else {
		$total = $h['passed'] + $h['failed'];
		echo "\n================================\n";
		echo $h['meta']['name'] . " [" . $h['meta']['tier'] . "]\n";
		echo "PASSED: {$h['passed']}   FAILED: {$h['failed']}"
			. ($h['skipped'] ? "   SKIPPED: {$h['skipped']}" : '')
			. "   ($total checks)\n";
	}
	exit($h['failed'] > 0 ? 1 : 0);
}

/**
 * Shutdown reporter and the contract's crash-safety net.
 *
 * Every test MUST end by calling harness_finish() (which sets finished=true and
 * exits). If this reporter fires with finished=false, the script died before
 * finishing — a fatal error, an uncaught exception, or an early exit() — which
 * is always a failure. We record it as a failing check (the platform's own
 * fatal handler runs first and overwrites error_get_last() with its own
 * warnings, so we cannot rely on the error type; the absence of a clean finish
 * is the signal), run teardown, and emit a failing contract so a crash can
 * never be misread as a pass.
 */
function harness_shutdown_report() {
	$h = &$GLOBALS['__harness'];
	if ($h['finished'] || !$h['booted']) return;

	if (!empty($h['sigint'])) {
		// Ctrl-C. Our handler exit()ed here so teardown still runs.
		check(false, 'interrupted before harness_finish()',
			'the run was interrupted (SIGINT); teardown ran, so no fixtures were left behind');
	} elseif (!empty($h['sigterm'])) {
		// timeout(1) sent SIGTERM (the test exceeded its wall-clock cap); our
		// handler exit()ed here so teardown could still run before SIGKILL.
		check(false, 'killed by timeout before harness_finish()',
			'the test exceeded its declared timeout and was terminated');
	} else {
		$err = error_get_last();
		$detail = $err ? ($err['message'] . ' at ' . $err['file'] . ':' . $err['line']) : 'no error captured';
		check(false, 'test crashed before harness_finish()', $detail);
	}
	harness_teardown_data();
	harness_finish();
}
