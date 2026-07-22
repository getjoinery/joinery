#!/usr/bin/php
<?php
/**
 * CLI surface of MailboxSpamPolicy — ops introspection for shell sessions
 * (specs/mailbox_spam_filtering_simplification.md D4).
 *
 * The scanner ships with the mail stack, so nothing in provisioning consults
 * this anymore; it exists for a human on a node asking "what is this box's
 * spam posture and why". One key=value per line:
 *
 *   php spam_policy.php show
 *     filing=            the master switch (file spam into the Spam view)
 *     learning=          learn from corrections (clamped off when filing is)
 *     upstream_scanner=  provider | relay | none — what scans before this box
 *     scan_at_ingest=    whether relay/webhook mail is re-scored locally
 *     mail_stack=        whether this box hosts its own Postfix stack
 *     scanner_present=   whether the rspamd controller is answering (observed)
 *     controller_url=    the endpoint the scan and learn calls use
 *
 * @version 1.1
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
	echo 'scan_at_ingest=' . (MailboxSpamPolicy::scanAtIngest() ? '1' : '0') . "\n";
	echo 'mail_stack=' . (MailboxSpamPolicy::mailStackPresent() ? '1' : '0') . "\n";
	echo 'scanner_present=' . (MailboxSpamPolicy::controllerReachable() ? '1' : '0') . "\n";
	echo 'controller_url=' . MailboxSpamPolicy::controllerUrl() . "\n";
} catch (\Throwable $e) {
	fwrite(STDERR, 'spam_policy: could not resolve the spam policy: ' . $e->getMessage() . "\n");
	exit(2);
}
exit(0);
?>
