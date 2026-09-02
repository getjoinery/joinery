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
 * Only the `deploy` tier is run here. The development tiers are not a publish
 * precondition, and could not run under the publisher anyway: it is root on
 * the local job queue, and their sandboxes hold only for an unprivileged user
 * (docs/testing.md).
 *
 * @version 1.2 - only the deploy tier gates a publish; no stamp check
 * @version 1.1
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

	/** A runner line reporting one suite passing — noise at publish time. */
	public static function isSuitePassLine($line) {
		return (bool)preg_match('/^\s*PASS\s+\S/', (string)$line);
	}
}
