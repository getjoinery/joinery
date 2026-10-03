#!/usr/bin/php
<?php
/**
 * CLI surface of MailboxSpamPolicy — ops introspection for shell sessions
 * (spam_learning_in_core.md).
 *
 * It exists for a human on a node asking "what is this box's spam posture and
 * why". One key=value per line:
 *
 *   php spam_policy.php show
 *     filing=            the master switch (file spam into the Spam view)
 *     learning=          learn from corrections (clamped off when filing is)
 *     upstream_scanner=  provider | relay | none — what scans before this box
 *     mail_stack=        whether this box hosts its own Postfix stack
 *     milter_answering=  whether rspamd's milter is listening on 11332 (observed)
 *     corpus_spam=       messages the spam corpus has learned as spam
 *     corpus_ham=        messages the spam corpus has learned as not spam
 *     corpus_voting=     whether the corpus has enough of both to vote
 *
 * @version 1.2 - learning lives in core: corpus totals replace the controller lines
 */

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(2);
}

$command = trim((string)($argv[1] ?? 'show'));
if ($command !== 'show') {
	fwrite(STDERR, "Usage: spam_policy.php show\n");
	exit(2);
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

try {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));
	echo 'filing=' . (MailboxSpamPolicy::filingEnabled() ? '1' : '0') . "\n";
	echo 'learning=' . (MailboxSpamPolicy::learningEnabled() ? '1' : '0') . "\n";
	echo 'upstream_scanner=' . MailboxSpamPolicy::upstreamScanner() . "\n";
	echo 'mail_stack=' . (MailboxSpamPolicy::mailStackPresent() ? '1' : '0') . "\n";
	echo 'milter_answering=' . (MailboxSpamPolicy::milterAnswering() ? '1' : '0') . "\n";
	$progress = SpamBayes::progress();
	echo 'corpus_spam=' . $progress['spam'] . "\n";
	echo 'corpus_ham=' . $progress['ham'] . "\n";
	echo 'corpus_voting=' . ($progress['voting'] ? '1' : '0') . "\n";
} catch (\Throwable $e) {
	fwrite(STDERR, 'spam_policy: could not resolve the spam policy: ' . $e->getMessage() . "\n");
	exit(2);
}
exit(0);
?>
