#!/usr/bin/env php
<?php
/**
 * What a publish from this box would refuse, as JSON: the uncommitted files a
 * release reads from this disk instead of from the commit (held), the other
 * uncommitted files a release leaves out, and any repository git cannot read.
 *
 * The same question the Publish Upgrade button and publish_upgrade.php ask
 * first (ReleaseCommit::ownerBlockers), so the rule is not written twice.
 * `work --held` runs this and adds the session behind each file.
 *
 * Usage: php held_blockers.php
 * Output: {"core":[...],"left_out":[...],"agent":[...],"unreadable":[...]}
 * Exit:   0 once it has answered; whether anything is held is in the JSON.
 *
 * @version 1.0
 */

$bootstrap_path = __DIR__ . '/../../public_html/includes/PathHelper.php';
if (!file_exists($bootstrap_path)) {
	fwrite(STDERR, "ERROR: Cannot find PathHelper.php at: $bootstrap_path\n");
	exit(2);
}
require_once($bootstrap_path);
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

$agent_root = class_exists('AgentDistPublisher') ? AgentDistPublisher::sourcePath() : null;
echo json_encode(ReleaseCommit::ownerBlockers(PathHelper::getSiteRoot(), $agent_root)), "\n";
