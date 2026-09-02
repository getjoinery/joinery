<?php
/**
 * PublishTestGate - run the test runner as a publish precondition.
 *
 * publish_upgrade.php builds its archive from whatever is on the publisher's
 * disk at that moment, half-finished edits included, so the tree is asked to
 * pass its tests before anything is written. This class owns the mechanics —
 * start the runner for one tier, relay its output as it comes, read the exit
 * code — so the rule can be tested against a fake runner. Which tiers to run
 * is the publisher's decision (see publish_upgrade.php).
 *
 * Only the `deploy` tier is run here. The development tiers cannot run under
 * the publisher, which is root on the local job queue: their sandboxes hold
 * only for an unprivileged user (docs/testing.md). For those, verifyStamp()
 * checks the runner's own PASS stamp against the tree about to be archived.
 *
 * @version 1.1 - verifyStamp: development tiers are proven by the runner's stamp, not rerun as root
 * @version 1.0
 */
class PublishTestGate {

	/**
	 * Run one tier through the runner and relay its output.
	 *
	 * Per-suite PASS lines are dropped on the way through: at publish time the
	 * signal is failures, skips and the summary. Everything else the runner
	 * prints is handed to $out one line at a time, without the newline.
	 *
	 * @param string   $runner  Path to tests/run.php
	 * @param string   $tier    Tier name, as run.php takes it
	 * @param callable $out     Receives each relayed line
	 * @return array ['ok' => bool, 'exit_code' => int|null, 'started' => bool]
	 *               started=false means the runner process could not be opened.
	 */
	public static function run($runner, $tier, callable $out) {
		if (!is_file($runner)) {
			return array('ok' => false, 'exit_code' => null, 'started' => false);
		}
		if (!preg_match('/^[a-z][a-z0-9_-]*$/', (string)$tier)) {
			throw new InvalidArgumentException("Not a tier name: '{$tier}'");
		}
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . $tier . ' 2>&1';
		$proc = popen($cmd, 'r');
		if (!is_resource($proc)) {
			return array('ok' => false, 'exit_code' => null, 'started' => false);
		}
		while (($line = fgets($proc)) !== false) {
			$line = rtrim($line, "\r\n");
			if (self::isSuitePassLine($line)) {
				continue;
			}
			$out($line);
		}
		$rc = pclose($proc);
		return array('ok' => $rc === 0, 'exit_code' => $rc, 'started' => true);
	}

	/**
	 * Accept a development tier on the strength of the runner's PASS stamp for
	 * this exact tree. Same result shape as run(): started=true always (there is
	 * no process to fail to start), ok is the verdict, exit_code null.
	 *
	 * @param string   $public_html  The tree about to be archived
	 * @param string   $tier         Tier name
	 * @param callable $out          Receives explanatory lines
	 */
	public static function verifyStamp($public_html, $tier, callable $out) {
		if (!preg_match('/^[a-z][a-z0-9_-]*$/', (string)$tier)) {
			throw new InvalidArgumentException("Not a tier name: '{$tier}'");
		}
		$v = TestTierStamp::verify($public_html, $tier);
		if ($v['ok']) {
			$s = $v['stamp'];
			$out("The {$tier} tier passed on this exact tree at {$s['passed_at']}"
				. (!empty($s['user']) ? " (run by {$s['user']})" : '')
				. (isset($s['totals']['tests']) ? ", {$s['totals']['tests']} tests" : '') . '.');
			if (!empty($s['totals']['skipped_needs'])) {
				$out('  Skipped in that run (unmet needs): ' . implode(', ', $s['totals']['skipped_needs']));
			}
			return array('ok' => true, 'exit_code' => null, 'started' => true);
		}
		$out("No accepted {$tier} run for this tree: {$v['reason']}.");
		if ($v['changed']) {
			$out('  Differs from the stamped tree:');
			foreach (array_slice($v['changed'], 0, 15) as $c) $out('    - ' . $c);
			if (count($v['changed']) > 15) $out('    ... and ' . (count($v['changed']) - 15) . ' more');
		}
		$out("Run `php tests/run.php {$tier}` as the site's user on this tree, then publish again.");
		return array('ok' => false, 'exit_code' => null, 'started' => true);
	}

	/** A runner line reporting one suite passing — noise at publish time. */
	public static function isSuitePassLine($line) {
		return (bool)preg_match('/^\s*PASS\s+\S/', (string)$line);
	}
}
