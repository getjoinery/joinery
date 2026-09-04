<?php
/** @joinery-test
 * name: agent_artifact_channel
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The artifact channel and the vocabulary report
 * (specs/agent_machine_posture_and_relay_converge.md §3, §4, §8-R2).
 *
 * Two mechanisms land together here because they answer the same question from
 * two ends: what can this machine actually do, and where does it get the code
 * to do it.
 *
 * WHAT IS WORTH TESTING, and it is not "the endpoint returns bytes":
 *
 *   - Routing asks the NODE. A version number is an inference about a
 *     vocabulary; the 0.8.352 rollout made that inference for nine agents and
 *     collected nine refusals. A primitive absent from a node's reported list
 *     must not be dispatched to it, whatever the version map would allow.
 *   - The fallback stays live. Agents at 1.10.0 and earlier never report, so
 *     PRIMITIVE_MIN_AGENT_VERSION is a contract, not dead code, and a test that
 *     only exercised the new path would let it rot.
 *   - What a node reports is normalised before it is believed. It is
 *     attacker-controllable text that decides routing.
 *   - The bundle really is signed, with the key the agent verifies against, and
 *     it is byte-stable when nothing changed. Both matter on a machine nobody
 *     can log into: an unsigned bundle is refused on the node with no way to
 *     see why from here, and a bundle that churns hands every siteless machine
 *     a download per release for no reason.
 *
 * The node-side half — the tar hardening, the signature check, the two-sided
 * manifest verification — is tested in the agent's own suite, where the
 * enforcement lives. Testing it from here would only prove this plane's opinion
 * of it, and this plane is the party the node does not trust.
 *
 * Run: php plugins/server_manager/tests/agent_artifact_channel_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

/** An unsaved node carrying just the columns routing consults. */
function artifact_test_node($version, $vocabulary) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'Artifact channel test');
	$node->set('mgn_slug', 'artifact-channel-test');
	// A paired agent: the public key is what has_agent_channel() reads.
	$node->set('mgn_agent_public_key', base64_encode(str_repeat("\x01", 32)));
	$node->set('mgn_agent_version', $version);
	$node->set('mgn_agent_primitives', $vocabulary);
	return $node;
}

// ======================================================================
section('Routing asks the node what it can do, not what version it is');
// ======================================================================

// The failure this exists to prevent, stated as a test: a node running a
// version the map is happy with, whose agent does not actually ship the
// primitive. The map says yes; the node's own list says no; the node wins.
$claims_less = artifact_test_node('9.9.9', 'check_status,list_backups');
check(!JobCommandBuilder::has_primitive($claims_less, 'apply_update'),
	'a primitive absent from the reported vocabulary is not routed to that node',
	'the node reported check_status,list_backups and the plane dispatched apply_update anyway — '
	. 'that is a guaranteed refusal traded for a working transport');

$claims_it = artifact_test_node('9.9.9', 'apply_update,check_status');
check(JobCommandBuilder::has_primitive($claims_it, 'apply_update'),
	'a primitive the node reports IS routed to it',
	'the node said it ships apply_update and the plane declined to use it');

// The node's word beats the version floor in the permissive direction too. A
// machine running a hand-built or pre-release agent that genuinely ships the
// primitive is not held back by a version string it does not match.
$old_version_new_vocabulary = artifact_test_node('1.9.1', 'apply_update');
check(JobCommandBuilder::has_primitive($old_version_new_vocabulary, 'apply_update'),
	'the reported vocabulary outranks the version floor',
	'a node that says it ships apply_update was refused on the strength of its version number');

check(!JobCommandBuilder::has_primitive($claims_it, 'definitely_not_an_operation'),
	'an operation with no builder is never routed as a primitive',
	'has_primitive() must still require a build_<op>_primitive method');

$unpaired = artifact_test_node('9.9.9', 'apply_update');
$unpaired->set('mgn_agent_public_key', '');
check(!JobCommandBuilder::has_primitive($unpaired, 'apply_update'),
	'a node with no paired agent is never routed a primitive',
	'a reported vocabulary must not stand in for a pairing');

// ======================================================================
section('The version floor stays live for agents that never report');
// ======================================================================

// Agents at 1.10.0 and earlier send no vocabulary. Their column is empty, and
// the map is the only thing that can answer for them — which is why it is a
// fallback rather than something deleted once the report exists.
check(JobCommandBuilder::has_primitive(artifact_test_node('1.10.0', ''), 'apply_update'),
	'a silent agent at the floor version is still routed the primitive',
	'the PRIMITIVE_MIN_AGENT_VERSION fallback stopped working for agents that predate the report');

check(!JobCommandBuilder::has_primitive(artifact_test_node('1.9.1', ''), 'apply_update'),
	'a silent agent below the floor version is not routed the primitive',
	'this is the exact case that produced nine refusals on the 0.8.352 rollout');

check(!JobCommandBuilder::has_primitive(artifact_test_node('', ''), 'apply_update'),
	'a node whose agent version is unknown routes away from the primitive',
	'an unknown vocabulary must not be guessed at optimistically');

check(array_key_exists('apply_update', JobCommandBuilder::PRIMITIVE_MIN_AGENT_VERSION),
	'PRIMITIVE_MIN_AGENT_VERSION still carries its fallback rows',
	'the map is the contract for every agent that predates vocabulary reporting; it is not dead code');

// ======================================================================
section('A reported vocabulary is normalised before it is believed');
// ======================================================================

check(AgentChannelEndpoint::normalised_vocabulary('check_status,apply_update')
		=== AgentChannelEndpoint::normalised_vocabulary('apply_update,check_status'),
	'a re-ordered report is the same vocabulary',
	'ordering must not read as a change, or every poll writes the column again');

check(AgentChannelEndpoint::normalised_vocabulary('a,check_status,,check_status,UPPER,../x,drop table')
		=== 'check_status',
	'names that could not be a primitive are dropped',
	'got: ' . AgentChannelEndpoint::normalised_vocabulary('a,check_status,,check_status,UPPER,../x,drop table'));

$flood = [];
for ($i = 0; $i < AgentChannelEndpoint::MAX_VOCABULARY_NAMES + 50; $i++) {
	$flood[] = 'prim_' . $i;
}
$capped = AgentChannelEndpoint::normalised_vocabulary(implode(',', $flood));
check(count(explode(',', $capped)) <= AgentChannelEndpoint::MAX_VOCABULARY_NAMES,
	'a node cannot stuff the column with an unbounded list',
	'stored ' . count(explode(',', $capped)) . ' names');

check(AgentChannelEndpoint::normalised_vocabulary('') === '',
	'no report normalises to no report',
	'an empty report must stay empty, or the version fallback never runs again');

// ======================================================================
section('The artifact request is a closed set, and names no path');
// ======================================================================

// The node picks from a compiled-in list of KINDS and, for a binary, an
// architecture matched against a pattern. It never names a file, so nothing it
// sends is resolved as a path on this plane.
// The endpoint's own spec, not a copy of it. A copy can agree with itself while
// disagreeing with the code, which is the one failure a path-safety test must
// not have.
$spec = AgentChannelEndpoint::artifact_request_spec();

check(AgentChannelEndpoint::validation_error(
		['node_id' => 1, 'kind' => 'agent_binary', 'platform' => 'linux-amd64'], $spec) === null,
	'a well-formed artifact request is accepted');

check(AgentChannelEndpoint::validation_error(
		['node_id' => 1, 'kind' => 'agent_binary', 'platform' => '../../etc/shadow'], $spec) !== null,
	'a platform that looks like a path is refused',
	'the architecture is matched, never interpolated, and a value that is not an architecture never reaches the manifest lookup');

check(AgentChannelEndpoint::validation_error(
		['node_id' => 1, 'kind' => 'agent_binary', 'file' => 'joinery-agent-linux-amd64.gz'], $spec) !== null,
	'a request that names a file is refused as an undeclared field',
	'a node that could name a file could name any file; the plane names it from its own manifest');

$kinds = AgentChannelEndpoint::ARTIFACT_KINDS;
check($kinds === ['agent_manifest', 'agent_binary', 'bundle_manifest', 'bundle_body', 'release_manifest'],
	'the artifact kinds are exactly the five the agent asks for',
	'the agent and the plane agree by convention here, the way the primitive vocabulary does; '
	. 'a kind on one side and not the other is a request that silently 400s: ' . implode(',', $kinds));

// release_manifest is the one kind whose whole purpose is to reach a node that
// can do nothing else. A node in that state names an artifact and a version;
// everything about resolving those to a file happens on this side.
check(AgentChannelEndpoint::validation_error(
		['node_id' => 1, 'kind' => 'release_manifest', 'owner' => '', 'version' => '0.8.370'], $spec) === null,
	'a release manifest request names an owner and a version');
check(AgentChannelEndpoint::validation_error(
		['node_id' => 1, 'kind' => 'release_manifest', 'path' => '/etc/passwd'], $spec) !== null,
	'a release manifest request that names a path is refused as an undeclared field',
	'the node never names a path here either');

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ReleaseManifestSource.php'));
check(ReleaseManifestSource::valid_owner('../../etc') === false,
	'and a traversal owner is refused before anything is resolved');
check(ReleaseManifestSource::valid_version('../0.8.370') === false,
	'and so is a traversal version');

// ======================================================================
section('The support bundle is signed with the release key and is byte-stable');
// ======================================================================

$site = sys_get_temp_dir() . '/joinery_bundle_test_' . getmypid();
$made_ok = true;
foreach (['config', 'public_html'] as $dir) {
	if (!is_dir($site . '/' . $dir) && !mkdir($site . '/' . $dir, 0755, true)) { $made_ok = false; }
}
check($made_ok, 'a throwaway site tree can be staged', $site);

// Copy the declared contents out of the real tree, so the test is about the
// files the bundle actually ships rather than about invented ones.
$real_site = dirname(PathHelper::getIncludePath(''));
$copied = true;
foreach (SupportBundlePublisher::declaredContents() as $rel) {
	$src = rtrim($real_site, '/') . '/' . $rel;
	$dst = $site . '/' . $rel;
	if (!is_dir(dirname($dst))) { mkdir(dirname($dst), 0755, true); }
	if (!file_exists($src) || !copy($src, $dst)) { $copied = false; }
}
check($copied, 'every declared bundle file exists in this tree',
	'SupportBundlePublisher declares a file the platform does not ship: '
	. implode(', ', SupportBundlePublisher::declaredContents()));

$first = SupportBundlePublisher::publish($site);
check($first['status'] === 'built', 'the bundle builds', $first['message']);
check(!empty($first['version']), 'the bundle carries a version', $first['message']);

$tarball = $site . '/public_html/agent_dist/' . SupportBundlePublisher::BUNDLE_NAME;
check(file_exists($tarball), 'the bundle tarball is written', $tarball);

$info = SupportBundlePublisher::info($site . '/public_html/agent_dist');
check(is_array($info) && !empty($info['sha256']) && $info['sha256'] === hash_file('sha256', $tarball),
	'the offer record describes the tarball that is actually there',
	'the sha256 an agent uses to skip a redundant download must match the bytes it would be served');

// A publish that changed nothing must leave the bundle alone. Otherwise every
// release hands every siteless machine a download whose only difference is a
// version string.
$second = SupportBundlePublisher::publish($site);
check($second['status'] === 'skipped', 'an unchanged tree rebuilds nothing', $second['message']);
check($second['version'] === $first['version'], 'the version is a fact about the content',
	"{$first['version']} then {$second['version']}");

// And a changed script must produce a different bundle, or a machine would keep
// running the old one for ever.
$changed = $site . '/' . SupportBundlePublisher::declaredContents()[0];
file_put_contents($changed, file_get_contents($changed) . "\n# changed\n");
$third = SupportBundlePublisher::publish($site);
check($third['status'] === 'built' && $third['version'] !== $first['version'],
	'a changed script produces a new bundle version',
	"{$first['version']} then {$third['version']} ({$third['message']})");

// The signature is the whole point: this plane serves the bytes and cannot
// vouch for them, so what makes the tree runnable is a signature the agent
// checks against a key compiled into its own binary.
$extract = $site . '/extracted';
mkdir($extract, 0755, true);
exec(sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($tarball), escapeshellarg($extract)), $out, $code);
check($code === 0, 'the bundle extracts', implode(' | ', $out));

$manifest_body = @file_get_contents($extract . '/' . TreeManifestPublisher::MANIFEST_NAME);
$signature_b64 = @file_get_contents($extract . '/' . TreeManifestPublisher::SIGNATURE_NAME);
check($manifest_body !== false && $signature_b64 !== false,
	'the bundle carries its own manifest and signature',
	'without both, the agent refuses every script in it rather than running one unverified');

if ($manifest_body !== false && $signature_b64 !== false) {
	$keys = AgentDistPublisher::ensureKeys($site . '/config');
	check(sodium_crypto_sign_verify_detached(
			base64_decode(trim($signature_b64), true), $manifest_body, $keys['public']),
		'the bundle manifest verifies against the release key',
		'a bundle that does not verify is refused on a machine nobody can log into, '
		. 'with the reason visible only in that machine\'s log');

	// Paths are site-root-relative, which is what lets one primitive declare one
	// ScriptPath and have it resolve whether the machine has a site or a bundle.
	$listed = [];
	foreach (explode("\n", $manifest_body) as $line) {
		if (strlen($line) > 66 && $line[64] === ' ') { $listed[] = substr($line, 66); }
	}
	sort($listed);
	$declared = SupportBundlePublisher::declaredContents();
	sort($declared);
	check($listed === $declared,
		'the manifest lists exactly the declared contents, at site-root-relative paths',
		'listed: ' . implode(', ', $listed) . ' / declared: ' . implode(', ', $declared));
}

exec('rm -rf ' . escapeshellarg($site));

harness_finish();
