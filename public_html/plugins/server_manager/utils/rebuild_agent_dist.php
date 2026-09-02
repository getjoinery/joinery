<?php
/**
 * rebuild_agent_dist.php - bring public_html/agent_dist up to the agent source.
 *
 * Usage:  php plugins/server_manager/utils/rebuild_agent_dist.php
 *
 * The bundle is what publish_upgrade.php ships to every management node, and it
 * is built from the agent checkout named by server_manager_agent_source_path.
 * The support bundle rides beside it in the same directory and a rebuilt
 * agent_dist starts empty, so both are brought current here, in the order the
 * publisher uses.
 * The safe tier's agent_bundle_drift test fails whenever the two disagree, and
 * a publish accepts a tree only after the safe tier has passed on it — so after
 * bumping the agent's version, the developer rebuilds the bundle here, runs the
 * tier, then publishes. publish_upgrade.php runs the same AgentDistPublisher
 * and finds the bundle already current.
 *
 * Exit 0 when the bundle is current (built, unchanged or carried), 1 when a
 * rebuild was needed and failed.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit("This tool runs from the command line only.\n");
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SupportBundlePublisher.php'));

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	fwrite(STDERR, "Refusing to run as root: the bundle it writes would be root-owned and the next publish as the site's user could not replace it.\n");
	exit(2);
}

$site_root = PathHelper::getSiteRoot();
$say = function ($line) { echo $line, "\n"; };
$result = AgentDistPublisher::publish($site_root, $say);
if ($result['status'] !== AgentDistPublisher::STATUS_FAILED) {
	$bundle = SupportBundlePublisher::publish($site_root, $say);
	if (!in_array($bundle['status'], array('built', 'skipped', 'carried'), true)) {
		fwrite(STDERR, "Support bundle: {$bundle['message']}\n");
		exit(1);
	}
}

$source  = $result['source_version']  ?? '?';
$bundled = $result['bundled_version'] ?? 'none';
switch ($result['status']) {
	case AgentDistPublisher::STATUS_BUILT:
		echo "agent_dist rebuilt: v{$bundled} (agent source v{$source}).\n";
		exit(0);
	case AgentDistPublisher::STATUS_SKIPPED:
		echo "agent_dist already current at v{$bundled}; nothing to do.\n";
		exit(0);
	case AgentDistPublisher::STATUS_CARRIED:
		echo "No agent source on this machine; agent_dist v{$bundled} carried forward unchanged.\n";
		exit(0);
	default:
		fwrite(STDERR, "agent_dist is v{$bundled} but the agent source is v{$source}, and the rebuild failed. Fix the agent build and run this again.\n");
		exit(1);
}
