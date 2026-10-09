<?php
/** @joinery-test
 * name: move_to_plane
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Moving a machine to another management node (move_to_plane, agent 1.67.0).
 *
 * A machine with no site has no Management Node page, so its management node
 * asks for it. The builder sends the other management node's address and this
 * node's slug, so an approval there gives it the same name and its backups
 * keep their folder. It refuses an agent without the word, an address that is
 * not a bare https host, and this management node itself.
 *
 * The agent half (staging the key, keeping the connection until approval, the
 * goodbye) is joinery-agent's move_test.go.
 *
 * Run: php plugins/server_manager/tests/move_to_plane_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

/** A stand-in node: only the columns routing reads. */
class MoveNode {
	public $key = 1;
	private $fields;
	public function __construct(array $fields) {
		$this->fields = array_merge(array('mgn_slug' => 'docker-prod', 'mgn_agent_public_key' => 'AAAA'), $fields);
	}
	public function get($f) { return $this->fields[$f] ?? null; }
}

$refusal = function (callable $fn) {
	try {
		$fn();
		return '';
	} catch (Exception $e) {
		return $e->getMessage();
	}
};

$able = new MoveNode(array('mgn_agent_version' => '1.67.0', 'mgn_agent_primitives' => 'host_report,move_to_plane'));
$older = new MoveNode(array('mgn_agent_version' => '1.66.0', 'mgn_agent_primitives' => 'host_report'));

// ---------------------------------------------------------------------------
section('An agent with the word is asked under this node\'s slug');

$built = JobCommandBuilder::build_move_to_plane($able, 'https://getjoinery.com/');
check(($built['primitive'] ?? '') === 'move_to_plane', 'the job is the move_to_plane word');
check(($built['params'] ?? null) === array('management_node' => 'https://getjoinery.com', 'name' => 'docker-prod'),
	'it names the other management node (trailing slash dropped) and this node\'s slug, so its backups keep their folder',
	json_encode($built['params'] ?? null));

// ---------------------------------------------------------------------------
section('What it refuses');

check($refusal(function () use ($older) { JobCommandBuilder::build_move_to_plane($older, 'https://getjoinery.com'); }) !== '',
	'an agent without the word, saying it needs a newer agent');
foreach (array('http://getjoinery.com', 'getjoinery.com', 'https://getjoinery.com/admin', 'https://get joinery.com', 'https://getjoinery.com?x=1') as $bad) {
	check($refusal(function () use ($able, $bad) { JobCommandBuilder::build_move_to_plane($able, $bad); }) !== '',
		'an address that is not a bare https host: ' . $bad);
}
$self = ProvisioningSetup::selfApiUrl();
check(strpos($refusal(function () use ($able, $self) { JobCommandBuilder::build_move_to_plane($able, $self); }), 'this management node') !== false,
	'this management node itself', $self);

harness_finish();
