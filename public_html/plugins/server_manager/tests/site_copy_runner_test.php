<?php
/** @joinery-test
 * name: site_copy_runner
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * site_copy_runner (specs/site_copy.md WP8): the management node's half of a
 * copy. The install command a copy's server runs (the source's release, the
 * dormant flags); a finished copy install leaving its row a copy; the copy run
 * moving one job at a time and stopping at the first that did not do its part;
 * the census compared informationally; Copy again and Discard; the node-id
 * word's builder, and the row swap made only in the answer to its result.
 *
 * @version 1.2 - the swapped rows are named for what they hold (B6)
 * @version 1.1 - the copy's server is the source's size
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$tag = bin2hex(random_bytes(3));
$source_key = base64_encode(random_bytes(32));
$copy_key = base64_encode(random_bytes(32));

$mk_node = function (string $suffix, array $set) use ($tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest SCP ' . $suffix);
	$n->set('mgn_slug', 'harnessscp-' . $suffix . '-' . $tag);
	$n->set('mgn_host', '192.0.2.60');
	$n->set('mgn_uptime_enabled', false);
	foreach ($set as $k => $v) {
		$n->set($k, $v);
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$finish = function ($job, string $status, ?array $result, string $error = '') {
	$job->set('mjb_status', $status);
	$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$job->set('mjb_error_message', $error);
	$job->set('mjb_result', $result === null ? null : json_encode($result));
	$job->save();
};

// ---------------------------------------------------------------------------
section('The install a copy\'s server runs');

$inst_node = $mk_node('inst', array('mgn_web_root' => '/var/www/html/scpsite/public_html'));
$params = array('mode' => 'copy', 'sitename' => 'scpsite', 'domain' => 'scp.example.org', 'docker_mode' => 'bare-metal',
	'admin_email' => 'owner@example.org', 'admin_password_stdin' => true,
	'copy_of' => 4242, 'copy_of_key' => $source_key, 'release' => '0.8.453');
$steps = JobCommandBuilder::build_install_node($inst_node, $params);
$session_cmd = (string)$steps[count($steps) - 1]['cmd'];
check(strpos($session_cmd, '/utils/latest_release?version=0.8.453') !== false,
	'the copy fetches its source\'s exact release, never the newest');
check(strpos($steps[0]['cmd'], 'latest_release?version=0.8.453') !== false, 'and the preflight checks that release is served');
check(strpos($session_cmd, " --dormant --copy-of=4242 --copy-of-key='" . $source_key . "'") !== false,
	'the site install is dormant and records the source\'s node id and agent key', $session_cmd);
check(strpos($session_cmd, '--admin-email') === false && empty($steps[count($steps) - 1]['stdin']),
	'a copy creates no admin account and reads no password: its accounts are its source\'s');
foreach (array(
	'docker'      => array('docker_mode' => 'docker'),
	'a bad key'   => array('copy_of_key' => 'not-a-key'),
	'no release'  => array('release' => 'latest'),
	'no source'   => array('copy_of' => 0),
) as $what => $over) {
	$threw = false;
	try { JobCommandBuilder::build_install_node($inst_node, array_replace($params, $over)); } catch (Exception $e) { $threw = true; }
	check($threw, 'a copy install with ' . $what . ' is refused');
}
$fresh = JobCommandBuilder::build_install_node($inst_node, array('mode' => 'fresh', 'sitename' => 'scpsite',
	'domain' => 'scp.example.org', 'docker_mode' => 'bare-metal'));
check(strpos((string)$fresh[count($fresh) - 1]['cmd'], 'latest_release?version') === false
	&& strpos((string)$fresh[count($fresh) - 1]['cmd'], '--dormant') === false,
	'every other install still takes the newest release and is not dormant');

// ---------------------------------------------------------------------------
section('A copy provision');

$prov = new CustomerCloudProvision(NULL);
$prov->set('cvp_origin', 'admin');
$prov->set('cvp_usr_user_id', 1);
$prov->set('cvp_domain', 'scp.example.org');
$prov->set('cvp_slug', 'harnessscp-prov-' . $tag);
$prov->set('cvp_docker_mode', 'bare-metal');
$prov->set('cvp_install_mode', 'copy');
$prov->set('cvp_source_node_id', 4242);
$prov->set('cvp_release', '0.8.453');
$threw = '';
try { $prov->prepare(); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw === '', 'an admin copy of a node, on bare metal, at a release, is a valid provision', $threw);
foreach (array(
	'on Docker'          => array('cvp_docker_mode', 'docker'),
	'with no release'    => array('cvp_release', ''),
	'from an order'      => array('cvp_origin', 'order'),
	'of no node'         => array('cvp_source_node_id', null),
) as $what => $over) {
	$p = new CustomerCloudProvision(NULL);   // a model's clone shares its fields
	foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'scp.example.org',
		'cvp_slug' => 'harnessscp-prov-' . $tag, 'cvp_docker_mode' => 'bare-metal', 'cvp_install_mode' => 'copy',
		'cvp_source_node_id' => 4242, 'cvp_release' => '0.8.453') as $k => $v) {
		$p->set($k, $v);
	}
	$p->set($over[0], $over[1]);
	$threw = false;
	try { $p->prepare(); } catch (Exception $e) { $threw = true; }
	check($threw, 'a copy provision ' . $what . ' is refused');
}

// ---------------------------------------------------------------------------
section('A finished copy install leaves the row a copy');

$inst_copy = $mk_node('instcopy', array('mgn_web_root' => '/var/www/html/scpsite/public_html',
	'mgn_install_state' => 'installing', 'mgn_copy_of_node_id' => (int)$inst_node->key));
$ijob = ManagementJob::createJob($inst_copy->key, 'install_node', $steps, array(), null);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $ijob->key);
$ijob->set('mjb_status', 'completed');
$ijob->set('mjb_output', "...\nINSTALL_SUCCESS\n");
$ijob->save();
JobResultProcessor::process($ijob);
$inst_copy->load();
check($inst_copy->get('mgn_install_state') === 'copy', 'a copy\'s successful install leaves its row in state copy, not working',
	var_export($inst_copy->get('mgn_install_state'), true));
check($inst_copy->get('mgn_ssl_state') !== 'pending', 'and does not wait for a certificate: its source\'s arrives with the export');

// ---------------------------------------------------------------------------
section('The node-id word and the row swap');

$sw_src = $mk_node('swsrc', array('mgn_web_root' => '/var/www/html/scpsite/public_html', 'mgn_host' => '192.0.2.61',
	'mgn_agent_public_key' => $source_key, 'mgn_agent_version' => '1.49.1', 'mgn_agent_primitives' => 'check_status',
	'mgn_install_state' => 'switching', 'mgn_joinery_version' => '0.8.453'));
$sw_copy = $mk_node('swcopy', array('mgn_web_root' => '/var/www/html/scpsite/public_html', 'mgn_host' => '192.0.2.62',
	'mgn_agent_public_key' => $copy_key, 'mgn_agent_version' => '1.50.0', 'mgn_agent_primitives' => 'take_node_id,site_census',
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$sw_src->key));
$built = JobCommandBuilder::build_take_node_id($sw_copy, $sw_src);
check($built === array('primitive' => 'take_node_id', 'params' => array('node_id' => (int)$sw_src->key,
	'node_slug' => (string)$sw_src->get('mgn_slug'))), 'the copy is told its source\'s node id and slug, nothing else');
$threw = false;
try { JobCommandBuilder::build_take_node_id_primitive($sw_copy, $inst_node); } catch (Exception $e) { $threw = true; }
check($threw, 'a copy is never told to take any node\'s id but its own source\'s');

$sw_prov = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'scp.example.org',
	'cvp_slug' => 'harnessscp-swprov-' . $tag, 'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal',
	'cvp_mgn_managed_node_id' => (int)$sw_copy->key) as $k => $v) {
	$sw_prov->set($k, $v);
}
$sw_prov->save();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $sw_prov->key);

// The source runs in a container: its container is part of the old machine.
$sw_src->set('mgn_container_name', 'scpswapsite');
$sw_src->save();
$tjob = ManagementJob::createFromBuild($sw_copy->key, 'take_node_id', $built, null, null);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $tjob->key);
$tjob->set('mjb_status', 'completed');
$tjob->set('mjb_output', "=== [Step 1/1] take_node_id ===\n" . json_encode(array('api_version' => '1.0',
	'data' => array('staged' => true, 'node_id' => (int)$sw_src->key, 'from_node_id' => (int)$sw_copy->key))));
$tjob->save();
JobResultProcessor::process($tjob);
$tjob->load();
$sw_src->load();
check(json_decode((string)$tjob->get('mjb_result'), true)['taken'] === false
	&& (string)$sw_src->get('mgn_agent_public_key') === $source_key,
	'processing the result on its own (a page view, a sweep) records what was staged and swaps nothing');

$taken = JobResultProcessor::complete_take_node_id($tjob);
$sw_src->load(); $sw_copy->load(); $sw_prov->load();
check($taken === (int)$sw_src->key, 'answering the result makes the swap and names the id the copy may take', (string)$tjob->get('mjb_result'));
check((string)$sw_src->get('mgn_agent_public_key') === $copy_key && (string)$sw_src->get('mgn_host') === '192.0.2.62'
	&& (string)$sw_src->get('mgn_agent_version') === '1.50.0' && $sw_src->get('mgn_install_state') === null,
	'the node keeps its id and slug and takes the copy\'s machine: its key, host and agent, and is a working site');
check((string)$sw_copy->get('mgn_agent_public_key') === $source_key && (string)$sw_copy->get('mgn_host') === '192.0.2.61'
	&& $sw_copy->get('mgn_install_state') === 'retired',
	'the copy\'s row takes the old machine and its key, retired, for the way back');
check((int)$sw_prov->get('cvp_mgn_managed_node_id') === (int)$sw_src->key, 'the provision follows its machine to the node');
check((string)$sw_src->get('mgn_container_name') === '' && (string)$sw_copy->get('mgn_container_name') === 'scpswapsite',
	'a container source\'s container stays with the old machine: the node is bare metal now');
check((string)$sw_copy->get('mgn_name') === $sw_src->get('mgn_name') . ' (old container)',
	'the retired row is named for what it holds now: the site\'s old container, not its copy', (string)$sw_copy->get('mgn_name'));
check(JobResultProcessor::complete_take_node_id($tjob) === 0, 'a result answered once is never swapped again');

SiteCopySwap::go_back($sw_src, $sw_copy);
$sw_src->load(); $sw_copy->load(); $sw_prov->load();
check((string)$sw_src->get('mgn_agent_public_key') === $source_key && $sw_src->get('mgn_install_state') === 'switching'
	&& (string)$sw_copy->get('mgn_agent_public_key') === $copy_key && $sw_copy->get('mgn_install_state') === 'copy'
	&& (int)$sw_prov->get('cvp_mgn_managed_node_id') === (int)$sw_copy->key,
	'the way back puts both machines back, the node frozen for its owner to unfreeze');
check((string)$sw_src->get('mgn_container_name') === 'scpswapsite', 'and the container comes back with its machine');
check((string)$sw_copy->get('mgn_name') === $sw_src->get('mgn_name') . ' (copy)', 'and the other row is the copy again, by name',
	(string)$sw_copy->get('mgn_name'));
$sw_src->set('mgn_container_name', null);
$sw_src->save();

$sw_src->set('mgn_install_state', null);
$sw_src->save();
$threw = '';
try { SiteCopySwap::take_over($sw_src, $sw_copy); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'a working site') !== false, 'a source that is not frozen is never taken over', $threw);
$sw_src->load();
check((string)$sw_src->get('mgn_agent_public_key') === $source_key, 'and nothing moved');

// ---------------------------------------------------------------------------
section('Preflight');

$src_status = json_encode(array('backup_recovery_state' => 'proven'));
$source_cols = array('mgn_web_root' => '/var/www/html/scpsite/public_html', 'mgn_site_url' => 'https://scp.example.org',
	'mgn_host' => '192.0.2.63', 'mgn_agent_public_key' => $source_key, 'mgn_agent_version' => '1.50.0',
	'mgn_agent_primitives' => 'copy_export,site_census,check_status', 'mgn_joinery_version' => '0.8.453',
	'mgn_last_status_data' => $src_status, 'mgn_backup_recovery_fpr' => str_repeat('ab', 32),
	'mgn_agent_server_manager' => 'inactive',
	'mgn_last_host_report' => json_encode(array('memory' => array('total_bytes' => 1000000000),
		'disk' => array('avail_bytes' => 9000000000))));
$src = $mk_node('src', $source_cols);
$has_target = (bool)JobCommandBuilder::get_target($src);
if (!$has_target) {
	harness_skip('preflight and copy runs', 'no enabled backup target on this management node');
	harness_finish();
	return;
}
check(SiteCopyRunner::source_refusals($src) === array(), 'a bare-metal site on a current release with the words, a proven key and backups can be copied',
	implode(' | ', SiteCopyRunner::source_refusals($src)));
foreach (array(
	'a container site'   => array('mgn_container_name', 'scpsite', 'container site'),
	'an old release'     => array('mgn_joinery_version', '0.8.452', 'needs ' . JobCommandBuilder::COPY_SOURCE_MIN_VERSION),
	'an older agent'     => array('mgn_agent_primitives', 'check_status', 'update'),
	'an unproven key'    => array('mgn_last_status_data', json_encode(array('backup_recovery_state' => 'unproven')), 'cannot run'),
	'a management node'  => array('mgn_agent_server_manager', 'active', 'management node'),
	'another layout'     => array('mgn_web_root', '/home/x/public_html', 'web root'),
) as $what => $c) {
	$n = new ManagedNode($src->key, TRUE);   // a model's clone shares its fields
	$n->set($c[0], $c[1]);
	$why = implode(' | ', SiteCopyRunner::source_refusals($n));
	check(strpos($why, $c[2]) !== false, 'preflight refuses ' . $what, $why);
}

$chain_time = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
$chain_id = 'chain-' . gmdate('Ymd_His', time() - 7200);
$listing = array('chains' => array(array('chain_id' => $chain_id, 'profile' => BackupProfile::MANAGER, 'bytes' => 70000000,
	'runs' => array(array('seq' => 0, 'time' => $chain_time, 'artifacts' => array('files' => 69000000, 'db' => 140000))))),
	'objects' => array(), 'error' => null);
SiteCopyRunner::$chain_lister = function ($node) use (&$listing) { return $listing; };
$chain = SiteCopyRunner::newest_chain($src);
check($chain['chain_id'] === $chain_id && $chain['time'] === $chain_time, 'the copy is made from the newest manager chain');

// The copy's server is the source's size.
$plans = function () {
	return array(
		array('id' => 'g6-nanode-1', 'memory_mb' => 1024, 'disk_mb' => 25600),
		array('id' => 'g6-standard-1', 'memory_mb' => 2048, 'disk_mb' => 51200),
		array('id' => 'g6-standard-2', 'memory_mb' => 4096, 'disk_mb' => 81920),
	);
};
check(SiteCopyRunner::same_size_type($src, SiteCopy::FROM_SOURCE, $plans) === 'g6-nanode-1',
	'a 1 GB server (it reports a little less) is copied onto the 1 GB plan');
$n = new ManagedNode($src->key, TRUE);
$n->set('mgn_last_host_report', json_encode(array('memory' => array('total_bytes' => 4106113024))));
check(SiteCopyRunner::same_size_type($n, SiteCopy::FROM_SOURCE, $plans) === 'g6-standard-2', 'a 4 GB server onto the 4 GB plan');
$n->set('mgn_last_host_report', null);
$n->set('mgn_last_status_data', json_encode(array('memory_total_mb' => 1900)));
check(SiteCopyRunner::same_size_type($n, SiteCopy::FROM_SOURCE, $plans) === 'g6-standard-1',
	'with no host report, the memory its status reports');
$n->set('mgn_last_status_data', null);
$threw = '';
try { SiteCopyRunner::same_size_type($n, SiteCopy::FROM_SOURCE, $plans); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'not reported its memory') !== false, 'with no memory reported, the size is not guessed', $threw);
$own = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'scpsize.example.org', 'cvp_slug' => 'harnessscr-size-' . bin2hex(random_bytes(3)),
	'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal', 'cvp_provider' => 'linode', 'cvp_instance_type' => 'g6-standard-1',
	'cvp_mgn_managed_node_id' => (int)$src->key) as $k => $v) {
	$own->set($k, $v);
}
$own->save();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $own->key);
check(SiteCopyRunner::same_size_type($src, SiteCopy::FROM_SOURCE, $plans) === 'g6-standard-1',
	'a server this management node created is copied onto its own plan');
$n = new ManagedNode($src->key, TRUE);
$n->set('mgn_container_name', 'scpsite');
check(SiteCopyRunner::same_size_type($n, SiteCopy::FROM_SOURCE, $plans) === 'g6-nanode-1',
	'a container\'s plan is its shared server\'s: sized by memory instead');
$own->permanent_delete();
$stale = $listing;
$stale['chains'][0]['runs'][0]['time'] = gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 3600);
SiteCopyRunner::$chain_lister = function ($node) use ($stale) { return $stale; };
$threw = '';
try { SiteCopyRunner::newest_chain($src); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'more than ' . SiteCopyRunner::BACKUP_MAX_AGE_HOURS . ' hours') !== false, 'a backup older than a day is refused, saying so', $threw);
SiteCopyRunner::$chain_lister = function ($node) use (&$listing) { return $listing; };
// Signing a chain's links reads backup storage, where this fixture chain is not;
// the builder itself is job_command_builder's to test.
SiteCopyRunner::$stage_builder = function ($node, $chain) {
	return array('primitive' => 'copy_stage', 'params' => array('chain_id' => $chain['chain_id'],
		'manifest_url' => 'https://x.invalid/m', 'artifact_urls' => array()));
};
check(SiteCopyRunner::disk_needed($chain) === 70000000 + 2 * 69000000 + 6 * 140000 + SiteCopyRunner::DISK_HEADROOM,
	'the disk a copy needs: the chain staged, the files unpacked, the database loaded, and headroom');

$stray = $mk_node('stray', array('mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$src->key));
$why = implode(' | ', SiteCopyRunner::source_refusals($src));
check(strpos($why, 'already has a dormant copy') !== false, 'a copy row made by hand still counts as the site\'s copy', $why);
$stray->soft_delete();

// ---------------------------------------------------------------------------
section('The source\'s root SSH keys travel only as the operator saw them (WP15)');

$wp15_fp1 = 'SHA256:' . str_repeat('B', 43);
$wp15_fp2 = 'SHA256:' . str_repeat('C', 43);
$wp15_n = new ManagedNode($src->key, TRUE);
check(SiteCopyRunner::source_root_keys($wp15_n)['known'] === false, 'a source that has not reported its keys: none known, none carried');
$wp15_n->set('mgn_last_host_report', json_encode(array('root_ssh' => array('keys' => array(
	array('fingerprint' => $wp15_fp1, 'carry' => true, 'type' => 'ssh-ed25519', 'key' => 'AAAAC3Nza', 'comment' => 'me'),
	array('fingerprint' => $wp15_fp2, 'carry' => false, 'type' => '', 'key' => '', 'comment' => ''))))));
$wp15_r = SiteCopyRunner::source_root_keys($wp15_n);
check($wp15_r['known'] === true && count($wp15_r['keys']) === 2 && $wp15_r['keys'][1]['carry'] === false,
	'the report is read back with each key\'s fingerprint and whether it can be carried');
$wp15_carry = new ReflectionMethod('SiteCopyRunner', 'carried_key_lines');
$wp15_carry->setAccessible(true);
check($wp15_carry->invoke(null, $wp15_n, array()) === '', 'unticked: nothing is carried');
check($wp15_carry->invoke(null, $wp15_n, array('carry_root_keys' => true, 'key_fingerprints' => $wp15_fp1)) === 'ssh-ed25519 AAAAC3Nza me',
	'ticked with the fingerprints shown: the bare key is carried, the restricted one is not');
$wp15_threw = '';
try { $wp15_carry->invoke(null, $wp15_n, array('carry_root_keys' => true, 'key_fingerprints' => $wp15_fp2)); } catch (Exception $e) { $wp15_threw = $e->getMessage(); }
check(strpos($wp15_threw, 'changed since you looked') !== false, 'keys that differ from the ones shown are refused', $wp15_threw);

// ---------------------------------------------------------------------------
section('A copy onto a server the owner brings');

$copy = SiteCopyRunner::start_own_server($src, null);
harness_register_row('scp_site_copies', 'scp_site_copy_id', $copy->key);
check($copy->status() === SiteCopy::STATUS_WAITING && $copy->get('scp_release') === '0.8.453',
	'the copy waits for its server, pinned to the source\'s release');
$cmd = SiteCopyRunner::owner_command($copy);
check(strpos($cmd, "/utils/latest_release?version=0.8.453'") !== false
	&& strpos($cmd, "site --bare-metal 'scpsite' --password-file=/root/.joinery_postgres_password 'scp.example.org'") !== false
	&& strpos($cmd, '--dormant --copy-of=' . (int)$src->key . " --copy-of-key='" . $source_key . "'") !== false,
	'the owner\'s command installs the source\'s release, site name and domain, dormant, recording the source', $cmd);
$threw = '';
try { SiteCopyRunner::start_own_server($src, null); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'already has a copy') !== false, 'a site has one copy at a time', $threw);

$ajr = new AgentJoinRequest(NULL);
$ajr_key = base64_decode($copy_key);
foreach (array('ajr_claimed_name' => 'scpsite', 'ajr_public_key' => $copy_key,
	'ajr_fingerprint' => AgentJoinRequest::fingerprint($ajr_key), 'ajr_source_ip' => '192.0.2.70',
	'ajr_agent_version' => '1.50.0', 'ajr_web_root' => '/var/www/html/scpsite/public_html', 'ajr_status' => 'pending') as $k => $v) {
	$ajr->set($k, $v);
}
$ajr->save();
harness_register_row('ajr_agent_join_requests', 'ajr_agent_join_request_id', $ajr->key);
$cands = SiteCopyRunner::candidate_joins($copy);
check(count(array_filter($cands, function ($r) use ($ajr) { return (int)$r->key === (int)$ajr->key; })) === 1,
	'a join from an agent named after the source\'s site is offered as the copy');
$cnode = SiteCopyRunner::approve_join($copy, $ajr);
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $cnode->key);
$copy->load();
check($cnode->get('mgn_install_state') === 'copy' && (int)$cnode->get('mgn_copy_of_node_id') === (int)$src->key
	&& (string)$cnode->get('mgn_agent_public_key') === $copy_key && (int)$copy->get('scp_copy_node_id') === (int)$cnode->key
	&& (string)$cnode->get('mgn_web_root') === '/var/www/html/scpsite/public_html' && $cnode->get('mgn_host') === '192.0.2.70',
	'approving makes the copy\'s row: a copy of the source, holding the joined key, host and web root');
check(!$cnode->is_operational(), 'and no automation treats it as a working site');

// The first run starts once the copy's agent has reported its words.
SiteCopyRunner::advance($copy); $copy->load();
check($copy->status() === SiteCopy::STATUS_WAITING, 'before the copy reports its words, the run waits');
$cnode->set('mgn_agent_primitives', 'host_report,copy_import,copy_stage,copy_restore,site_census,take_node_id');
$cnode->set('mgn_last_host_report', json_encode(array('memory' => array('total_bytes' => 1000000000),
	'disk' => array('avail_bytes' => 9000000000))));
$cnode->save();
SiteCopyRunner::advance($copy); $copy->load();
check($copy->status() === SiteCopy::STATUS_COPYING && $copy->get('scp_chain_id') === $chain_id,
	'then the first run starts, on the newest chain');
check($copy->steps()[0]['verdict'] === 'running' && !empty($copy->steps()[0]['job_id']),
	'and its first step is queued in the same call');

$step_job = function ($copy, int $i) {
	$job = new ManagementJob((int)$copy->steps()[$i]['job_id'], TRUE);
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
	return $job;
};
$census = function (int $rows) {
	return array('measured' => true, 'census' => array('version' => SiteCensus::VERSION,
		'tables' => array('usr_users' => 3, 'rql_request_logs' => $rows),
		'files' => array('uploads' => array('files' => 4, 'dirs' => 1, 'bytes' => 400, 'digest' => 'd1')),
		'secrets' => array('canary' => SecretBox::OPEN_OK, 'dead' => 0, 'present' => 3),
		'offloaded' => array('total' => 0, 'sampled' => 0, 'answered' => 0)));
};
$results = array(
	0 => array('completed', array('reported' => true)),
	1 => array('completed', array('exported' => true, 'bundle' => '{"body":"x","signature":"y"}')),
	2 => array('completed', array('imported' => true, 'chains' => array($chain_id), 'host_files' => 27,
		'look_path' => '/.joinery-look/0123456789abcdef0123456789abcdef')),
	3 => array('completed', array('staged' => true)),
	4 => array('completed', array('restored' => true)),
	5 => array('completed', $census(75)),
	6 => array('completed', $census(50)),
);
$ops = array();
foreach ($results as $i => $r) {
	SiteCopyRunner::advance($copy); $copy->load();
	$step = $copy->steps()[$i];
	check($step['verdict'] === 'running' && !empty($step['job_id']), 'step ' . ($i + 1) . ' (' . $step['op'] . ' on the ' . $step['on'] . ') is queued',
		(string)$copy->get('scp_halt_reason'));
	if (empty($step['job_id'])) {
		break;
	}
	$job = $step_job($copy, $i);
	$ops[] = $job->get('mjb_job_type') . '@' . ((int)$job->get('mjb_mgn_managed_node_id') === (int)$src->key ? 'source' : 'copy');
	if ($i === 2) {
		$params = json_decode((string)$job->get('mjb_parameters'), true);
	}
	$finish($job, $r[0], $r[1]);
	// The result arriving moves the run: the next step is queued at once,
	// not at the next task tick (B40).
	SiteCopyRunner::job_finished($job); $copy->load();
	if ($i < count($results) - 1) {
		check($copy->steps()[$i]['verdict'] === 'passed' && $copy->steps()[$i + 1]['verdict'] === 'running'
			&& !empty($copy->steps()[$i + 1]['job_id']),
			'its result passes step ' . ($i + 1) . ' and queues step ' . ($i + 2) . ' in the same call',
			(string)$copy->get('scp_halt_reason'));
	}
}
check($ops === array('host_report@copy', 'copy_export@source', 'copy_import@copy', 'copy_stage@copy', 'copy_restore@copy',
	'site_census@source', 'site_census@copy'), 'one job at a time, each on its own machine, in order', implode(', ', $ops));
SiteCopyRunner::advance($copy); $copy->load();
check($copy->status() === SiteCopy::STATUS_DORMANT && $copy->get('scp_last_copied_time'),
	'the run ends dormant: the source is live, so rows it wrote since its backup do not block',
	(string)$copy->get('scp_halt_reason'));
$v = $copy->census();
check(is_array($v) && (int)$v['blocking'] === 0 && count($v['differences']) === 1, 'and the comparison is kept for the page');
check($copy->get('scp_look_path') === '/.joinery-look/0123456789abcdef0123456789abcdef', 'the look link the copy reported is kept');

// Copy again: a declined export stops the run, by name.
SiteCopyRunner::copy_again($copy, null); $copy->load();
check($copy->status() === SiteCopy::STATUS_COPYING, 'Copy again starts a new run');
SiteCopyRunner::advance($copy); $copy->load();
$finish($step_job($copy, 0), 'completed', array('reported' => true));
SiteCopyRunner::advance($copy); $copy->load();
SiteCopyRunner::advance($copy); $copy->load();
$finish($step_job($copy, 1), 'failed', array('exported' => false), 'the operator declined the export');
SiteCopyRunner::advance($copy); $copy->load();
check($copy->status() === SiteCopy::STATUS_HALTED && strpos((string)$copy->get('scp_halt_reason'), 'declined') !== false
	&& $copy->steps()[2]['verdict'] === 'skipped', 'a declined export stops the run and says so; nothing after it starts',
	(string)$copy->get('scp_halt_reason'));

// A copy too small for the run stops before anything leaves the source.
$cnode->set('mgn_last_host_report', json_encode(array('memory' => array('total_bytes' => 1000000000),
	'disk' => array('avail_bytes' => 1000000))));
$cnode->save();
SiteCopyRunner::copy_again($copy, null); $copy->load();
check(!empty($copy->steps()[0]['job_id']), 'Copy again queues the run\'s first step at once, not at the next task tick');
SiteCopyRunner::advance($copy); $copy->load();
$finish($step_job($copy, 0), 'completed', array('reported' => true));
SiteCopyRunner::advance($copy); $copy->load();
check($copy->status() === SiteCopy::STATUS_HALTED && strpos((string)$copy->get('scp_halt_reason'), 'free') !== false
	&& empty($copy->steps()[1]['job_id']), 'a copy without the disk for the run stops before the export',
	(string)$copy->get('scp_halt_reason'));

// Memory: a copy holds at least its source's, except a container site's,
// whose report is its whole shared server's.
$small = new ManagedNode($cnode->key, TRUE);
$small->set('mgn_last_host_report', json_encode(array('memory' => array('total_bytes' => 1000000000),
	'disk' => array('avail_bytes' => 90000000000))));
$big = new ManagedNode($src->key, TRUE);
$big->set('mgn_last_host_report', json_encode(array('memory' => array('total_bytes' => 4106113024))));
$why = (string)SiteCopyRunner::fit_refusal($copy, $big, $small);
check(strpos($why, 'of memory') !== false, 'a 1 GB copy of a 4 GB bare-metal server is refused', $why);
$big->set('mgn_container_name', 'scpsite');
check(SiteCopyRunner::fit_refusal($copy, $big, $small) === null, 'a 1 GB copy of a container site on a 4 GB shared server is not');

// The source moved to another release: the copy cannot follow.
$src->set('mgn_joinery_version', '0.8.454');
$src->save();
SiteCopyRunner::copy_again($copy, null); $copy->load();
check($copy->status() === SiteCopy::STATUS_HALTED && strpos((string)$copy->get('scp_halt_reason'), 'exact release') !== false,
	'a source on another release than its copy\'s stops the run: discard and copy again', (string)$copy->get('scp_halt_reason'));

// Discard while the source waits for its owner to approve the export (B42):
// the export job is cancelled, and the source, asking whether the job is still
// wanted, hears that it was withdrawn and takes the request off its page.
$src->set('mgn_joinery_version', '0.8.453');
$src->save();
$cnode->set('mgn_last_host_report', json_encode(array('memory' => array('total_bytes' => 1000000000),
	'disk' => array('avail_bytes' => 9000000000))));
$cnode->save();
SiteCopyRunner::copy_again($copy, null); $copy->load();
SiteCopyRunner::advance($copy); $copy->load();
$finish($step_job($copy, 0), 'completed', array('reported' => true));
SiteCopyRunner::advance($copy); $copy->load();
$export = $step_job($copy, 1);
$export->set('mjb_status', 'running');
$export->save();
$asked = AgentChannelEndpoint::job_status_answer((int)$src->key, (int)$export->key);
check($asked !== null && $asked['withdrawn'] === false, 'while the export waits for approval the source hears it is still wanted');
check(AgentChannelEndpoint::job_status_answer((int)$cnode->key, (int)$export->key) === null,
	'and another node asking about it hears nothing');

// Discard.
$server = SiteCopyRunner::server_to_delete($copy);
SiteCopyRunner::discard($copy); $copy->load(); $cnode->load();
$export->load();
$asked = AgentChannelEndpoint::job_status_answer((int)$src->key, (int)$export->key);
check((string)$export->get('mjb_status') === 'cancelled' && $asked !== null && $asked['withdrawn'] === true,
	'Discard cancels the waiting export, and the source hears it was withdrawn', (string)$export->get('mjb_status'));
check($copy->status() === SiteCopy::STATUS_DISCARDED && $cnode->get('mgn_delete_time') && strpos($server, '192.0.2.70') !== false,
	'Discard ends the copy and removes its row, and names the server for its owner to delete at the provider', $server);
check(SiteCopy::live_for_source((int)$src->key) === null, 'and the site can be copied again');

// ---------------------------------------------------------------------------
section('After a kept switch-over, the server to delete is the old machine, never the live one (B58)');

// The copy's provision follows the NEW server, which is now the live site. A
// copy row holding the old machine must never fall back to it.
$live_prov = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'scplive.example.org',
	'cvp_slug' => 'harnessscr-live-' . bin2hex(random_bytes(3)), 'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal',
	'cvp_provider' => 'linode', 'cvp_instance_id' => '99887766', 'cvp_instance_ip' => '192.0.2.99',
	'cvp_mgn_managed_node_id' => (int)$src->key) as $k => $v) {
	$live_prov->set($k, $v);
}
$live_prov->save();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $live_prov->key);
$copy->set('scp_cvp_customer_cloud_provision_id', (int)$live_prov->key);

$old_box = $mk_node('oldbox', array('mgn_install_state' => 'retired', 'mgn_host' => '192.0.2.61'));
$copy->set('scp_copy_node_id', (int)$old_box->key);
$copy->save();
$named = SiteCopyRunner::server_to_delete($copy);
check(strpos($named, '192.0.2.61') !== false && strpos($named, '99887766') === false,
	'an old bare machine with no provision of its own is named by its address, not by the live server\'s provision', $named);
check(SiteCopyRunner::old_container($copy) === null, 'and it is not a container to remove from a host');

$old_ctr = $mk_node('oldctr', array('mgn_install_state' => 'retired', 'mgn_container_name' => 'scpoldctr'));
$copy->set('scp_copy_node_id', (int)$old_ctr->key);
$copy->save();
check(SiteCopyRunner::server_to_delete($copy) === '',
	'an old container names no server to delete at a provider: its host is shared', SiteCopyRunner::server_to_delete($copy));
$found = SiteCopyRunner::old_container($copy);
check($found && (int)$found->key === (int)$old_ctr->key,
	'and old_container() names its row, for Permanently Delete Site');

SiteCopyRunner::$chain_lister = null;
SiteCopyRunner::$stage_builder = null;
harness_finish();
