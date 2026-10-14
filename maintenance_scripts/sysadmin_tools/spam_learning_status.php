<?php
/**
 * spam_learning_status.php — whether what this site's members mark as spam or
 * not spam reaches its spam filter, as ONE JSON object on stdout. Counts only.
 *
 * The spam_learning_status observe word of specs/agent_recipes_and_vocabulary.md
 * (the running list, 2026-10-03: whether a member's Mark as spam on
 * jeremytunnell reached the filter took read-only SSH and a hand-written query,
 * because the cron log's "Taught 0" cannot tell a correction already taught
 * from one never recorded). The agent runs this file, verified against the
 * release manifest, with no argv and no stdin.
 *
 * What it prints is SpamLearning::status() (the mailbox plugin): the last
 * week's corrections split by what became of them, the corpus's size and
 * newest token, and the two policy switches. Never a subject, a sender, an
 * address or a row id. Where the mailbox plugin is not active, it prints
 * {"mailbox":"inactive"}.
 *
 * It reads and never writes. It lives in sysadmin_tools/, outside the web
 * root, as site_census.php does; the CLI check below is the second line.
 *
 * Exit 0 with the object; exit 1 with an ERROR: line when it could not count
 * (the database unreachable).
 *
 * Validate with `php -l` only — never the file validator, which executes the
 * file it is checking.
 *
 * @version 1.0
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

if (count($argv) > 1) {
	fwrite(STDERR, "Usage: php spam_learning_status.php (it takes no arguments)\n");
	exit(2);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');

try {
	// An inactive plugin's classes do not resolve, which is the answer here.
	if (!class_exists('SpamLearning')) {
		echo json_encode(array('mailbox' => 'inactive')) . "\n";
		exit(0);
	}
	echo json_encode(array('mailbox' => 'active') + SpamLearning::status()) . "\n";
} catch (\Throwable $e) {
	echo 'ERROR: the spam learning counts could not be read: ' . get_class($e) . "\n";
	exit(1);
}
exit(0);
