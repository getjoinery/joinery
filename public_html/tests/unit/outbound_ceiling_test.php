<?php
/** @joinery-test
 * name: outbound_ceiling
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * What the settings page says about the site's speed ceiling (OutboundCeiling;
 * specs/node_outbound_and_transfer.md WP5).
 *
 *  - told() reads the file the machine's joinery-limits unit writes into the
 *    site: root's figure, the one in force, and whether the management node
 *    set them; a value that is not a figure is none, and no file is null;
 *  - helptext_append() says the server's figure and the one in force, and how
 *    to go higher: on a self-hosted site, the command to run on the server
 *    (with --site for a container site) and what it risks; where someone else
 *    hosts the site, or the management node set the figures, to ask them.
 *
 * Run: php tests/run.php safe --filter=outbound_ceiling
 *
 * @version 1.1 - the limits' state: off, refused, the ceiling refused, a site not covered
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$dir = sys_get_temp_dir() . '/outbound_ceiling_test_' . getmypid();
@mkdir($dir);
harness_defer(function () use ($dir) { array_map('unlink', glob($dir . '/*') ?: array()); @rmdir($dir); });
$told_at = function (string $text) use ($dir): string {
	$p = $dir . '/told_' . md5($text);
	file_put_contents($p, $text);
	return $p;
};

section('told(): what the machine last told the site');
check(OutboundCeiling::told($dir . '/absent') === null, 'no file: the machine has said nothing');
check(OutboundCeiling::told($told_at("host_ceiling_mbit=200\nceiling_mbit=50\nset_by=plane\n"))
	=== array('state' => 'on', 'host_ceiling_mbit' => 200, 'ceiling_mbit' => 50, 'set_by' => 'plane'), 'the figures and who set them');
check(OutboundCeiling::told($told_at("host_ceiling_mbit=\nceiling_mbit=\nset_by=\n"))
	=== array('state' => 'on', 'host_ceiling_mbit' => null, 'ceiling_mbit' => null, 'set_by' => ''), 'empty figures are none; no state (an older unit) is on');
check(OutboundCeiling::told($told_at("state=hacked\nhost_ceiling_mbit=200; rm\nceiling_mbit=-5\nset_by=someone\nextra=1\n"))
	=== array('state' => 'on', 'host_ceiling_mbit' => null, 'ceiling_mbit' => null, 'set_by' => ''), 'what is not a figure, a state or plane is nothing');
check(OutboundCeiling::told($told_at("state=off\nhost_ceiling_mbit=\nceiling_mbit=\nset_by=\n"))['state'] === 'off', 'the state the machine told');

section('helptext_append(): the server\'s figure and how to go higher');
$t = OutboundCeiling::helptext_append(null, false, '');
check(strpos($t, 'has not said what its own figure is') !== false, 'nothing told: says so', $t);
$self = array('host_ceiling_mbit' => 200, 'ceiling_mbit' => 50, 'set_by' => '');
$t = OutboundCeiling::helptext_append($self, false, 'mysite');
check(strpos($t, "server's figure for this site: 200 Mbit/s") !== false && strpos($t, 'In force now: 50 Mbit/s') !== false,
	'the server\'s figure and the one in force', $t);
check(strpos($t, 'sudo joinery-limits ceiling 500 --site=mysite') !== false && strpos($t, "run up the provider's bill") !== false,
	'self-hosted, a container site: the command with --site, and what going higher risks', $t);
$t = OutboundCeiling::helptext_append($self, false, '');
check(strpos($t, 'sudo joinery-limits ceiling 500 (') !== false, 'bare metal: the machine\'s command, no --site', $t);
$t = OutboundCeiling::helptext_append(array('host_ceiling_mbit' => 200, 'ceiling_mbit' => 200, 'set_by' => 'plane'), false, 'mysite');
check(strpos($t, 'set by whoever hosts this site') !== false && strpos($t, 'joinery-limits') === false,
	'set by the management node: ask whoever hosts it, no command', $t);
$t = OutboundCeiling::helptext_append($self, true, 'mysite');
check(strpos($t, 'set by whoever hosts this site') !== false, 'a site someone else hosts: the same', $t);
$t = OutboundCeiling::helptext_append(array('host_ceiling_mbit' => null, 'ceiling_mbit' => null, 'set_by' => ''), false, '');
check(strpos($t, 'sets no speed ceiling for this site') !== false && strpos($t, 'In force now: none') !== false,
	'no ceiling from the server: a figure here is the only one', $t);

section('helptext_append(): what the limits\' state means for the site');
$t = OutboundCeiling::helptext_append(array('state' => 'off', 'host_ceiling_mbit' => null, 'ceiling_mbit' => null, 'set_by' => ''), false, 'mysite');
check(strpos($t, 'limits are off: nothing holds') !== false && strpos($t, 'In force now') === false, 'off: nothing holds the site, never a stale figure', $t);
$t = OutboundCeiling::helptext_append(array('state' => 'refused', 'host_ceiling_mbit' => null, 'ceiling_mbit' => null, 'set_by' => ''), false, 'mysite');
check(strpos($t, 'not in force') !== false, 'refused: not in force', $t);
$t = OutboundCeiling::helptext_append(array('state' => 'ceiling_failed', 'host_ceiling_mbit' => 200, 'ceiling_mbit' => null, 'set_by' => ''), false, 'mysite');
check(strpos($t, 'could not put its speed ceiling in force') !== false && strpos($t, 'In force now') === false, 'the ceiling refused: says so, no figure in force', $t);
$t = OutboundCeiling::helptext_append(array('state' => 'uncovered', 'host_ceiling_mbit' => null, 'ceiling_mbit' => null, 'set_by' => ''), false, 'mysite');
check(strpos($t, 'do not cover this site') !== false && strpos($t, 'move_site_to_own_network.sh') !== false, 'a site not covered: says why and how', $t);

harness_finish();
