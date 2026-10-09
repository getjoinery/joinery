<?php
/** @joinery-test
 * name: node_form_ssh_key_optional
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The Overview tab's Connection Settings form asks for an SSH Key Path only of a
 * node with no paired agent. A node the agent manages has no SSH to configure,
 * and the form used to refuse to save its slug, name or Hosted at without one:
 * the browser blocked the post and said nothing, which stopped WP6 step 3
 * (matching a moved node's slug) on the rehearsal.
 *
 * Proven over HTTP as a signed-in superadmin, on two throwaway nodes (one
 * agent-paired, one not), removed at the end.
 *
 * Run: php plugins/server_manager/tests/node_form_ssh_key_optional_test.php [base_url] [origin_ip]
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_http_boot($argv);
harness_boot();

$run_id = substr(md5(uniqid('nfs', true)), 0, 6);
$super = make_user('Nfs' . $run_id, 10);
$super->set('usr_is_activated', true);
$super->save();
$jar = harness_jar_new('nfs');

$make = function (string $label, bool $paired) use ($run_id) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest ssh form ' . $label . ' ' . $run_id);
	$node->set('mgn_slug', 'harness-ssh-' . $label . '-' . $run_id);
	$node->set('mgn_host', '192.0.2.' . ($paired ? '21' : '22'));
	$node->set('mgn_web_root', '/var/www/html/harness' . $label . '/public_html');
	if ($paired) {
		$node->set('mgn_agent_public_key', base64_encode(str_repeat("\x02", 32)));
		$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	}
	$node->save();
	return $node;
};
$paired = $make('paired', true);
$plain = $make('plain', false);

check(harness_web_login($jar, $super->get('usr_email'), 'TestPassword_Nfs' . $run_id) !== null, 'the superadmin is signed in');

$form_for = function ($node) use ($jar) {
	return harness_request('GET', '/admin/server_manager/node_detail?mgn_managed_node_id=' . $node->key . '&tab=overview&edit=1',
		array('jar' => $jar, 'accept' => null));
};
// The label carries the asterisk and the field's validation says required.
$key_label_required = function (string $body): bool {
	return preg_match('#SSH Key Path\s*\*#', $body) === 1;
};

section('A node with no paired agent still needs an SSH key path');
$r = $form_for($plain);
check($r['status'] === 200, 'its Overview renders', 'status ' . $r['status']);
check(strpos($r['body'], 'mgn_ssh_key_path') !== false, 'the form carries the SSH Key Path field');
check($key_label_required($r['body']), 'the field is marked required');

section('A node an agent manages does not');
$r = $form_for($paired);
check($r['status'] === 200, 'its Overview renders', 'status ' . $r['status']);
check(strpos($r['body'], 'mgn_ssh_key_path') !== false, 'the form still carries the SSH Key Path field');
check(!$key_label_required($r['body']), 'the field is not marked required');
check(strpos($r['body'], 'SSH Key Path') !== false, 'the field keeps its label');

$paired->permanent_delete();
$plain->permanent_delete();
harness_finish();
