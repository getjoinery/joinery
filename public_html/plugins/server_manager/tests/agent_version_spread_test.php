<?php
/** @joinery-test
 * name: agent_version_spread
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * Different agent versions across the fleet (specs/agent_recipes_and_vocabulary.md,
 * WP6). The two directions a release can break, held against this plane's own
 * intake and routing:
 *
 *   - an OLDER agent's poll, recorded from a real 1.42.0 node: it is still
 *     taken in whole, and the node is below the floor, so it is offered
 *     apply_update and nothing else, and every feature says so with the one
 *     standard sentence;
 *   - a NEWER agent's poll: fields this plane has never heard of are set aside
 *     unread and the rest of the report is kept; words this plane has no
 *     builder for are stored and never routed.
 *
 * The agent half of the second direction (a newer agent against a plane that
 * refuses a field) is joinery-agent's vocabulary_test.go.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

/** A stand-in node: only the columns routing reads. */
class SpreadNode {
	public $key = 1;
	private $fields;
	public function __construct(array $fields) {
		$this->fields = array_merge(array('mgn_slug' => 'spreadnode', 'mgn_agent_public_key' => 'AAAA'), $fields);
	}
	public function get($f) { return $this->fields[$f] ?? null; }
}

/** A node as the claim intake would leave it, from one claim body. */
function spread_node_from_claim(array $claim) {
	$in = AgentChannelEndpoint::known_claim_fields($claim);
	$refusal = AgentChannelEndpoint::validation_error($in, AgentChannelEndpoint::claim_request_spec());
	check($refusal === null, 'the claim validates', (string)$refusal);
	return new SpreadNode(array(
		'mgn_agent_version'    => (string)($in['agent_version'] ?? ''),
		'mgn_agent_primitives' => AgentChannelEndpoint::normalised_vocabulary($in['primitives'] ?? ''),
		'mgn_agent_recipes'    => AgentChannelEndpoint::normalised_recipes($in['recipes'] ?? ''),
	));
}

// Recorded from a dev-plane node running agent 1.42.0 (2026-09-23).
const SPREAD_142_WORDS = 'agent_converge,agent_report,apply_update,backup_run,check_status,clone_export_arm,decommission_site,delete_backup,disk_usage,download_backup,fleet_enroll,host_converge,host_report,hosted_mail_settings,hosted_plan_notice,install_report,list_backups,log_table_tail,managed_domain_notice,managed_domain_prepare,provision_certificate,publish_upgrade,recovery_key_report,reset_failed_unit,restart_agent,restore_chain,restore_database,restore_objects,restore_project,run_plugin_installers,site_log,ssl_probe_clear,ssl_probe_place,stage_chain,unit_journal,upload_backup,verify_backup';

// ---------------------------------------------------------------------------
section('The floor');

check(AgentVocabulary::is_version(AgentVocabulary::FLOOR), 'the floor is a version');
check(!defined('JobCommandBuilder::PRIMITIVE_MIN_AGENT_VERSION'),
	'the per-word version table is gone: the floor and the reported words decide');
check(AgentVocabulary::steps_behind('1.42.0', '1.43.0') === 1 && AgentVocabulary::steps_behind('1.40.2', '1.43.0') === 3
	&& AgentVocabulary::steps_behind('1.43.0', '1.43.1') === 1 && AgentVocabulary::steps_behind('1.44.0', '1.43.0') === 0,
	'how far behind counts releases, and a newer agent is not behind');

// ---------------------------------------------------------------------------
section('An older agent\'s poll: taken in whole, offered apply_update only');

$older = spread_node_from_claim(array(
	'node_id' => 7, 'agent_version' => '1.42.0', 'primitives' => SPREAD_142_WORDS,
	'recipes' => 'agent_supervision:armed:pass,disk_headroom:armed:pass,fail2ban:not-applicable',
	'bundle_version' => '', 'log_access' => 'on', 'server_manager' => 'inactive', 'script_trust' => 'ok',
));
check(AgentVocabulary::below_floor($older), 'a 1.42.0 node is below the floor');
check(JobCommandBuilder::has_primitive($older, 'apply_update'), 'it is offered apply_update, the way up');
foreach (array('host_report', 'check_status', 'site_log', 'backup_run', 'restore_database') as $w) {
	check(!JobCommandBuilder::has_primitive($older, $w), "it is not routed {$w}, though it reports the word");
}
$missing = AgentVocabulary::missing_words($older, array('host_report', 'restart_unit', 'apply_update'));
check($missing === array('host_report', 'restart_unit'), 'a feature asking for words learns every one it lacks', var_export($missing, true));
$text = AgentVocabulary::needs_newer_agent_text($older, $missing);
check(strpos($text, 'Needs a newer agent; update this node.') === 0 && strpos($text, AgentVocabulary::FLOOR) !== false,
	'the standard state says to update and names the floor', $text);
$spread = AgentVocabulary::spread($older);
check($spread['below_floor'] === true && $spread['version'] === '1.42.0', 'the node list flags it below the minimum');

$silent = new SpreadNode(array('mgn_agent_version' => '', 'mgn_agent_primitives' => ''));
check(AgentVocabulary::below_floor($silent) && JobCommandBuilder::has_primitive($silent, 'apply_update')
	&& !JobCommandBuilder::has_primitive($silent, 'check_status'),
	'an agent too old to report anything is below the floor and offered apply_update only');
check(AgentVocabulary::spread(new SpreadNode(array('mgn_agent_public_key' => ''))) === null
	&& !AgentVocabulary::below_floor(new SpreadNode(array('mgn_agent_public_key' => ''))),
	'a node with no agent has no version to spread and is not "below the floor"');

// ---------------------------------------------------------------------------
section('A newer agent\'s poll: unknown fields set aside, unknown words never routed');

$newer = spread_node_from_claim(array(
	'node_id' => 7, 'agent_version' => '9.1.0',
	'primitives' => SPREAD_142_WORDS . ',restart_unit,file_head,word_from_the_future',
	'recipes' => 'service_health:armed:pass,recipe_from_the_future:armed',
	'log_access' => 'on', 'a_field_from_the_future' => array('anything' => true), 'another' => 'x',
));
check(!AgentVocabulary::below_floor($newer), 'a newer agent is above the floor');
check(JobCommandBuilder::has_primitive($newer, 'restart_unit') && JobCommandBuilder::has_primitive($newer, 'host_report'),
	'the words it reports and this plane can build are routed');
check(!JobCommandBuilder::has_primitive($newer, 'word_from_the_future'),
	'a word this plane has no builder for is stored and never routed');
check(strpos((string)$newer->get('mgn_agent_primitives'), 'word_from_the_future') !== false,
	'but it is kept in the report, so the node page can show what the node offers');
check(AgentVocabulary::missing_words($newer, array('restart_unit', 'file_head')) === array(),
	'a feature built from words the newer node reports is offered in full');
check(AgentVocabulary::spread($newer)['behind'] === 0, 'and a node newer than this plane is never shown behind');

// ---------------------------------------------------------------------------
section('A node at the floor that reports no vocabulary is not guessed at');

$quiet = new SpreadNode(array('mgn_agent_version' => AgentVocabulary::FLOOR, 'mgn_agent_primitives' => ''));
check(!JobCommandBuilder::has_primitive($quiet, 'host_report') && JobCommandBuilder::has_primitive($quiet, 'apply_update'),
	'every agent at the floor reports its words; an empty list is no evidence of one');

harness_finish();
