<?php
/** @joinery-test
 * name: break_glass_root_access
 * tier: test-db
 * env: dev-only
 * needs: []
 * timeout: 180
 */
/**
 * The root password a fresh install keeps for the beta
 * (specs/implemented/beta_break_glass_root_access.md section 3, WP2):
 *
 *   - kept when every condition holds, retired when any one fails (each
 *     condition, and copy and bare installs, which are never offered it)
 *   - the password seals to the holder, never the buyer; a holder with no
 *     vault, a seal that throws, and a seal that lands but does not read
 *     back sealed all fall back to held with no copy left in the vault column
 *     and the SecretBox copy intact
 *   - the sweep's saves of a kept row succeed with no vault open; a kept row
 *     whose node is gone stays kept
 *   - Reveal and Retire are refused for a non-holder, a locked vault, or no
 *     second factor, and ask for a recent confirmation
 *   - Retire returns the row to held under a SecretBox copy the install
 *     executor's own reader opens, records a reveal and a retire, and the
 *     existing retire completes it; a box retired by its holder is never
 *     kept again
 *   - a key rotation of the holder's vault re-wraps a kept row
 *   - the dashboard's summary, the dismiss rule and the open list read a
 *     kept row with no vault open; the incident's reasons
 *   - Turn off root login (5.3, WP4): refused on a container node and while
 *     the install password is held or retiring; a completed run whose sshd
 *     reads no retires a kept and a retire_failed row with no vault open,
 *     clearing cvp_error, and records who asked; a run not in force retires
 *     nothing
 *
 * Rows go to the test database and are deleted.
 *
 * @version 1.2 - a Docker host's own node is the machine, not a container (live check 2026-10-25)
 * @version 1.1 - disable_root_login's builder and result (WP4)
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
// The unlock window is keyed by session: start one before the test-database
// banner is printed, after which no session can start.
vault_ensure_session();
harness_test_mode();

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
if (!vault_apcu_usable() || !vault_ensure_session()) {
	harness_skip('APCu/session unavailable, so no unlock window can be held in CLI');
	harness_finish();
}

/** The sweep's install-password step, reachable from a test, with no mail. */
class BreakGlassProbeProvision extends ProvisionCustomerCloud {
	public function probe($p) { return $this->handle_install_password($p); }
	protected function notify_ops(string $subject, string $body): void {}
}

/** A session that answers only what holder_action_refusal() asks. */
class BreakGlassFakeSession {
	public $uid; public $factor; public $recent;
	function __construct(int $uid, bool $factor = true, bool $recent = true) {
		$this->uid = $uid; $this->factor = $factor; $this->recent = $recent;
	}
	function get_user_id() { return $this->uid; }
	function user_has_second_factor($user): bool { return $this->factor; }
	function has_recent_second_factor(int $ttl = 300): bool { return $this->recent; }
}

$db = DbConnector::get_instance()->get_db_link();
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$probe = new BreakGlassProbeProvision();

/** A fixture account; with_factor gives it an authenticator app. */
function bg_user(string $label, bool $with_factor) {
	$user = make_user('Bg' . $label, 10);
	if ($with_factor) {
		$user->set('usr_totp_enabled_time', gmdate('Y-m-d H:i:s'));
		$user->save();
	}
	return $user;
}

$holder = bg_user('holder' . $suffix, true);
$holder_vault = vault_fixture_server_vault((int)$holder->key);
$buyer = bg_user('buyer' . $suffix, true);
$buyer_vault = vault_fixture_server_vault((int)$buyer->key);
$other = bg_user('other' . $suffix, true);
$novault = bg_user('novault' . $suffix, true);
$nofactor = bg_user('nofactor' . $suffix, false);
vault_fixture_server_vault((int)$nofactor->key);

harness_set_setting_mem(BreakGlassRootAccess::SETTING, '1');
harness_set_setting_mem(BreakGlassRootAccess::HOLDER_SETTING, (string)$holder->key);

/** A bare-metal machine whose one agent is admitted, and its done provision at held. */
function bg_box(string $tag, array $over = array()) {
	global $buyer, $suffix;
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest break-glass ' . $tag . ' ' . $suffix);
	$node->set('mgn_slug', 'bg-' . $tag . '-' . $suffix);
	$node->set('mgn_host', '198.51.100.' . random_int(20, 240));
	$node->set('mgn_agent_public_key', base64_encode(random_bytes(32)));
	$node->save();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', (int)$node->key);

	$prov = new CustomerCloudProvision(NULL);
	$prov->set('cvp_origin', 'admin');
	$prov->set('cvp_usr_user_id', (int)$buyer->key);
	$prov->set('cvp_domain', 'bg-' . $tag . '-' . $suffix . '.example.com');
	$prov->set('cvp_slug', 'bg-' . $tag . '-' . $suffix);
	$prov->set('cvp_docker_mode', 'bare-metal');
	$prov->set('cvp_install_mode', 'fresh');
	$prov->set('cvp_status', 'done');
	$prov->set('cvp_mgn_managed_node_id', (int)$node->key);
	$prov->set('cvp_install_password', 'held');
	$prov->set('cvp_root_access_offered', true);
	$prov->set('cvp_root_pass_sealed', (new SecretBox())->seal(BreakGlassRootAccess::SECRETBOX_LOCATOR, 'RootPass-' . $tag . '-' . $suffix));
	foreach ($over as $k => $v) {
		$prov->set($k, $v);
	}
	$prov->save();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', (int)$prov->key);
	return array($node, new CustomerCloudProvision((int)$prov->key, TRUE));
}

/** The provision's columns as the database holds them, nothing opened. */
function bg_raw(int $id): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT cvp_install_password, cvp_root_pass_sealed,
		cvp_root_pass_vault_sealed, cvp_content_sealed, cvp_sealed_key, cvp_sealed_owner_user_id, cvp_key_generation,
		cvp_root_access_offered, cvp_error FROM cvp_customer_cloud_provisions WHERE cvp_customer_cloud_provision_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}

function bg_flag($v): bool {
	return $v === true || in_array(strtolower((string)$v), array('t', 'true', '1'), true);
}

/** The SecretBox copy, opened the way the install executor opens it. */
function bg_executor_password($node): ?string {
	$m = new ReflectionMethod('InstallJobExecutor', 'resolve_password');
	return $m->invoke((new ReflectionClass('InstallJobExecutor'))->newInstanceWithoutConstructor(), $node);
}

/** Register every job made for this node for teardown. */
function bg_register_jobs($node): void {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT mjb_management_job_id FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ?');
	$q->execute(array((int)$node->key));
	foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $jid) {
		harness_register_row('mjb_management_jobs', 'mjb_management_job_id', (int)$jid);
	}
}

/** Register this run's event rows for one provision for teardown. */
function bg_register_events(int $provision_id): void {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		"SELECT evl_event_log_id FROM evl_event_logs WHERE evl_event IN ('break_glass_reveal', 'break_glass_retire')
		 AND (evl_note = ? OR evl_note LIKE ?)");
	$q->execute(array('provision #' . $provision_id, 'provision #' . $provision_id . ',%'));
	foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $eid) {
		harness_register_row('evl_event_logs', 'evl_event_log_id', (int)$eid);
	}
}

// ======================================================================
section('Kept when every condition holds');
// ======================================================================
VaultUnlock::close((int)$holder->key);
list($node, $prov) = bg_box('kept');
check($probe->probe($prov) === 1, 'the sweep acts on a held box whose agents are admitted');
$raw = bg_raw((int)$prov->key);
check($raw['cvp_install_password'] === 'kept', 'the box is kept', (string)$raw['cvp_install_password']);
check($raw['cvp_root_pass_sealed'] === null, 'and the SecretBox copy is gone');
check(strpos((string)$raw['cvp_root_pass_vault_sealed'], 'v1.aead.') === 0, 'the password is ciphertext in the vault column');
check(bg_flag($raw['cvp_content_sealed']) && (string)$raw['cvp_sealed_key'] !== '', 'the row is sealed, with a wrapped key');
check((int)$raw['cvp_sealed_owner_user_id'] === (int)$holder->key, 'sealed to the holder');
check((int)$raw['cvp_sealed_owner_user_id'] !== (int)$buyer->key, 'never to the buyer, who has a vault of their own');
check(strpos(ProvisionCustomerCloud::install_password_summary(new CustomerCloudProvision((int)$prov->key, TRUE)), 'Kept for the beta (holder: ') === 0,
	'the dashboard line says kept, and names the holder');

// ======================================================================
section('A kept row: the sweep and the board read it with no vault open');
// ======================================================================
$kept = new CustomerCloudProvision((int)$prov->key, TRUE);
$before = bg_raw((int)$prov->key);
$saved = true;
try {
	$kept->set('cvp_error', 'a sweep note');
	$kept->save();
} catch (\Throwable $e) {
	$saved = false;
	check(false, 'a sweep save of a kept row succeeds with no vault open', $e->getMessage());
}
if ($saved) { check(true, 'a sweep save of a kept row succeeds with no vault open'); }
$after = bg_raw((int)$prov->key);
check($after['cvp_root_pass_vault_sealed'] === $before['cvp_root_pass_vault_sealed'] && $after['cvp_sealed_key'] === $before['cvp_sealed_key'],
	'and leaves the ciphertext and its key as they were');
check($probe->probe(new CustomerCloudProvision((int)$prov->key, TRUE)) === 0 && bg_raw((int)$prov->key)['cvp_install_password'] === 'kept',
	'the sweep leaves a kept row alone');
check(in_array('this plane still holds an install password the machine accepts', $kept->dismiss_blockers(), true),
	'a kept box cannot be dismissed');
$open_ids = array();
foreach (new MultiCustomerCloudProvision(array('open' => true, 'deleted' => false)) as $op) { $open_ids[] = (int)$op->key; }
check(in_array((int)$prov->key, $open_ids, true), 'it stays on the board\'s open list');
$locked_read = null;
try { $kept->get('cvp_root_pass_vault_sealed'); } catch (VaultLockedException $e) { $locked_read = 'locked'; }
check($locked_read === 'locked', 'reading the password itself with no vault open is refused as locked');

// ======================================================================
section('A kept row whose node is gone stays kept');
// ======================================================================
list($gone_node, $gone) = bg_box('gone');
$probe->probe($gone);
$gone_node->set('mgn_delete_time', gmdate('Y-m-d H:i:s'));
$gone_node->save();
check($probe->probe(new CustomerCloudProvision((int)$gone->key, TRUE)) === 0, 'the sweep does nothing');
check(bg_raw((int)$gone->key)['cvp_install_password'] === 'kept', 'the row stays kept, not retire_failed');
check(strpos(ProvisionCustomerCloud::install_password_summary(new CustomerCloudProvision((int)$gone->key, TRUE)), 'the node record is gone') !== false,
	'and the dashboard line says the node is gone');
check(in_array('the node record is gone', BreakGlassRootAccess::attention_reasons(new CustomerCloudProvision((int)$gone->key, TRUE), (int)$holder->key), true),
	'the incident lists it for its holder');

// ======================================================================
section('Retired, not kept, when any condition fails');
// ======================================================================
$retired_when = function (string $tag, string $what, array $over = array(), ?callable $arrange = null) use ($probe) {
	list($n, $p) = bg_box($tag, $over);
	if ($arrange) { $arrange($n); }
	$p = new CustomerCloudProvision((int)$p->key, TRUE);
	$refusal = BreakGlassRootAccess::keep_refusal($p, $n);
	$probe->probe($p);
	bg_register_jobs($n);
	$raw = bg_raw((int)$p->key);
	check($raw['cvp_install_password'] === 'retiring' && $raw['cvp_root_pass_vault_sealed'] === null
		&& ManagementJob::latestForNode((int)$n->key, 'retire_install_password') !== null,
		'retired when ' . $what, (string)$refusal . ' / ' . $raw['cvp_install_password']);
};
harness_set_setting_mem(BreakGlassRootAccess::SETTING, '0');
$retired_when('off', 'emergency root access is off here');
harness_set_setting_mem(BreakGlassRootAccess::SETTING, '1');
$retired_when('notoffered', 'the install did not offer it', array('cvp_root_access_offered' => false));
$retired_when('keys', 'root keys were supplied', array('cvp_root_ssh_keys' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBreakGlassFixtureKeyOnly bg@test'));
$retired_when('ownoff', 'the site\'s owner turned it off', array(), function ($n) {
	$n->set('mgn_agent_root_access', 'off'); $n->save();
});
harness_set_setting_mem(BreakGlassRootAccess::HOLDER_SETTING, '0');
$retired_when('noholder', 'no holder is named');
harness_set_setting_mem(BreakGlassRootAccess::HOLDER_SETTING, (string)$novault->key);
$retired_when('novault', 'the holder has no vault');
harness_set_setting_mem(BreakGlassRootAccess::HOLDER_SETTING, (string)$nofactor->key);
$retired_when('nofactor', 'the holder has no second factor');
harness_set_setting_mem(BreakGlassRootAccess::HOLDER_SETTING, (string)$holder->key);

foreach (array('copy', 'bare') as $mode) {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_install_mode', $mode);
	check(!BreakGlassRootAccess::offer_on_install($p), "a {$mode} install is not offered it, so it is never kept");
}
$p = new CustomerCloudProvision(NULL);
$p->set('cvp_install_mode', 'fresh');
check(BreakGlassRootAccess::offer_on_install($p), 'a fresh install with no root keys is offered it');
$p->set('cvp_root_ssh_keys', 'ssh-ed25519 AAAA x');
check(!BreakGlassRootAccess::offer_on_install($p), 'one whose operator supplied root keys is not');

// ======================================================================
section('A keep that does not hold falls back to held, with nothing left in the clear');
// ======================================================================
$fallback_ok = function ($p, string $what) {
	$raw = bg_raw((int)$p->key);
	check($raw['cvp_install_password'] === 'held' && $raw['cvp_root_pass_vault_sealed'] === null
		&& !bg_flag($raw['cvp_content_sealed']) && $raw['cvp_sealed_key'] === null && $raw['cvp_sealed_owner_user_id'] === null,
		$what . ': held, the vault column and its key cleared', json_encode($raw));
	check(!bg_flag($raw['cvp_root_access_offered']) && strpos((string)$raw['cvp_error'], 'Not kept for the beta') === 0,
		'  offered cleared so the next pass retires it, and the reason recorded');
	$opened = (new SecretBox())->open((string)$raw['cvp_root_pass_sealed']);
	check(($opened['state'] ?? '') === 'ok' && strpos((string)$opened['value'], 'RootPass-') === 0, '  the SecretBox copy is intact');
};

// A holder with no vault: the default save writes a sealed field in the clear.
harness_set_setting_mem(BreakGlassRootAccess::HOLDER_SETTING, (string)$novault->key);
list($n, $p) = bg_box('fallnovault');
check(!BreakGlassRootAccess::keep($p)['kept'], 'keep() refuses a holder with no vault');
$fallback_ok($p, 'a holder with no vault');

// No holder at all: the write would fall back to the buyer, who has a vault.
harness_set_setting_mem(BreakGlassRootAccess::HOLDER_SETTING, '0');
list($n, $p) = bg_box('fallbuyer');
check(!BreakGlassRootAccess::keep($p)['kept'], 'a seal with no holder set throws rather than sealing to the buyer');
$fallback_ok($p, 'a seal that throws');

// A seal that lands but does not read back sealed for the server: a holder
// whose user vault the browser holds gets the browser's format.
$browser_holder = bg_user('browser' . $suffix, true);
vault_fixture_client_vault((int)$browser_holder->key, (new SealedBox())->generateKeypair()['public'], UserEncryptionVault::SCOPE_USER);
harness_set_setting_mem(BreakGlassRootAccess::HOLDER_SETTING, (string)$browser_holder->key);
list($n, $p) = bg_box('fallcheck');
check(!BreakGlassRootAccess::keep($p)['kept'], 'a seal that landed but fails the read-back is not kept');
$fallback_ok($p, 'a seal that landed, then failed its check');
harness_set_setting_mem(BreakGlassRootAccess::HOLDER_SETTING, (string)$holder->key);
// The next pass retires it, as today.
$probe->probe(new CustomerCloudProvision((int)$p->key, TRUE));
bg_register_jobs($n);
check(bg_raw((int)$p->key)['cvp_install_password'] === 'retiring', 'and the next pass retires it');

// ======================================================================
section('Reveal and Retire: only the holder, vault open, second factor used recently');
// ======================================================================
$kept = new CustomerCloudProvision((int)$prov->key, TRUE);
VaultUnlock::close((int)$holder->key);
$v = BreakGlassRootAccess::holder_action_refusal($kept, new BreakGlassFakeSession((int)$other->key));
check($v['refusal'] !== null && !$v['stepup'], 'refused for someone who is not its holder', (string)$v['refusal']);
$v = BreakGlassRootAccess::holder_action_refusal($kept, new BreakGlassFakeSession((int)$holder->key, false));
check($v['refusal'] !== null && strpos($v['refusal'], 'second factor') !== false, 'refused for a holder with no second factor', (string)$v['refusal']);
$v = BreakGlassRootAccess::holder_action_refusal($kept, new BreakGlassFakeSession((int)$holder->key));
check($v['refusal'] !== null && strpos($v['refusal'], 'vault') !== false, 'refused while the holder\'s vault is locked', (string)$v['refusal']);
vault_fixture_open_window((int)$holder->key, $holder_vault['secret']);
$v = BreakGlassRootAccess::holder_action_refusal($kept, new BreakGlassFakeSession((int)$holder->key, true, false));
check($v['stepup'], 'with the vault open, a holder who has not confirmed recently is sent to confirm');
vault_fixture_open_window((int)$other->key, vault_fixture_server_vault((int)$other->key)['secret']);
$v = BreakGlassRootAccess::holder_action_refusal($kept, new BreakGlassFakeSession((int)$other->key));
check($v['refusal'] !== null, 'another admin\'s open vault gives them nothing');
$v = BreakGlassRootAccess::holder_action_refusal($kept, new BreakGlassFakeSession((int)$holder->key));
check($v['refusal'] === null && !$v['stepup'], 'the holder, vault open, confirmed recently: allowed', (string)$v['refusal']);

$session = new BreakGlassFakeSession((int)$holder->key);
check(BreakGlassRootAccess::reveal($kept, $session) === 'RootPass-kept-' . $suffix, 'Reveal shows the kept password');

// ======================================================================
section('Retire: back under SecretBox at held, and the existing retire completes it');
// ======================================================================
// The reveal opened sealed content, so the rest of this process writes
// under the sealed-content rule, as the dashboard's Retire does.
$kept = new CustomerCloudProvision((int)$prov->key, TRUE);
BreakGlassRootAccess::retire($kept, $session);
bg_register_events((int)$prov->key);
$raw = bg_raw((int)$prov->key);
check($raw['cvp_install_password'] === 'held' && $raw['cvp_root_pass_vault_sealed'] === null, 'Retire returns the row to held and clears the vault column');
check(!bg_flag($raw['cvp_root_access_offered']), 'and clears offered, so it is never kept again');
check(bg_executor_password($node) === 'RootPass-kept-' . $suffix, 'the SecretBox copy opens with the install executor\'s own reader');
$events = BreakGlassRootAccess::events_for_provision((int)$prov->key);
$kinds = array_map(function ($e) { return $e['event']; }, $events);
check(count(array_filter($kinds, function ($k) { return $k === 'retire'; })) === 1
	&& count(array_filter($kinds, function ($k) { return $k === 'reveal'; })) === 2,
	'the reveal, and the retire with its own reveal, are recorded', implode(',', $kinds));
SealedEgressGuard::reset();

check(BreakGlassRootAccess::keep_refusal(new CustomerCloudProvision((int)$prov->key, TRUE), $node) !== null, 'a box retired by its holder is not kept again');
$probe->probe(new CustomerCloudProvision((int)$prov->key, TRUE));
bg_register_jobs($node);
check(bg_raw((int)$prov->key)['cvp_install_password'] === 'retiring', 'the next pass queues the retire job');
$job = ManagementJob::latestForNode((int)$node->key, 'retire_install_password');
$job->set('mjb_status', 'completed');
$job->save();
$probe->probe(new CustomerCloudProvision((int)$prov->key, TRUE));
$raw = bg_raw((int)$prov->key);
check($raw['cvp_install_password'] === 'retired' && $raw['cvp_root_pass_sealed'] === null, 'and its answer retires the password, as any install password is retired');

// ======================================================================
section('A key rotation of the holder\'s vault re-wraps a kept row');
// ======================================================================
VaultUnlock::close((int)$holder->key);
list($rn, $rp) = bg_box('rotate');
check(BreakGlassRootAccess::keep($rp)['kept'], 'a box is kept');
$old_gen = (int)bg_raw((int)$rp->key)['cvp_key_generation'];
$new = (new SealedBox())->generateKeypair();
$reseal = VaultUnlock::modelReseal(array(CustomerCloudProvision::class));
$threw = '';
try {
	$reseal((int)$holder->key, vault_fixture_key($holder_vault['secret']), $old_gen, $new['public'], $old_gen + 1);
} catch (\Throwable $e) {
	$threw = $e->getMessage();
}
check($threw === '', 'the re-seal runs over the kept row', $threw);
check((int)bg_raw((int)$rp->key)['cvp_key_generation'] === $old_gen + 1, 'the row is on the new generation');
vault_fixture_open_window((int)$holder->key, $new['secret']);
check((new CustomerCloudProvision((int)$rp->key, TRUE))->get('cvp_root_pass_vault_sealed') === 'RootPass-rotate-' . $suffix,
	'and opens with the new key');
SealedEgressGuard::reset();
VaultUnlock::close((int)$holder->key);

// ======================================================================
section('What the incidents read');
// ======================================================================
$rotated = new CustomerCloudProvision((int)$rp->key, TRUE);
check(BreakGlassRootAccess::attention_reasons($rotated, (int)$holder->key) === array(), 'a kept box with its node, its owner\'s leave and the current holder needs nothing');
$reasons = BreakGlassRootAccess::attention_reasons($rotated, (int)$other->key);
check(count($reasons) === 1 && strpos($reasons[0], 'not the current holder') !== false, 'one sealed to someone other than the current holder is listed');
$rn->set('mgn_agent_root_access', 'off');
$rn->save();
check(in_array('the owner asked to remove it', BreakGlassRootAccess::attention_reasons($rotated, (int)$holder->key), true), 'and one whose owner asked');
check(strpos(ProvisionCustomerCloud::install_password_summary($rotated), 'owner asked to remove it') !== false, 'the dashboard line says the owner asked');
check(BreakGlassRootAccess::holder_unfit_reason((int)$holder->key) === null, 'the holder is fit');
check(strpos((string)BreakGlassRootAccess::holder_unfit_reason(0), 'no holder') !== false, 'nobody named is unfit');
check(strpos((string)BreakGlassRootAccess::holder_unfit_reason((int)$novault->key), 'no vault') !== false, 'a holder with no vault is unfit');
check(strpos((string)BreakGlassRootAccess::holder_unfit_reason((int)$nofactor->key), 'second factor') !== false, 'a holder with no second factor is unfit');
check((new IncidentSourceBreakGlassAttention())->evaluate($rn) === null && (new IncidentSourceBreakGlassNoHolder())->evaluate($rn) === null,
	'both incidents are raised only on this management node\'s own node');

// ======================================================================
section('Turn off root login (5.3): who may be asked, and what a done run retires');
// ======================================================================
/** A box whose machine speaks disable_root_login, its provision found by the machine's address. */
$drl_box = function (string $tag, array $over = array()) {
	list($n, $p) = bg_box($tag, $over);
	$n->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$n->set('mgn_agent_primitives', 'host_report,disable_root_login');
	$n->save();
	$p->set('cvp_instance_id', 'bg-' . $tag);
	$p->set('cvp_instance_ip', (string)$n->get('mgn_host'));
	$p->save();
	return array(new ManagedNode((int)$n->key, TRUE), new CustomerCloudProvision((int)$p->key, TRUE));
};
$drl_refused = function ($n) {
	try { JobCommandBuilder::build_disable_root_login($n); return ''; } catch (Exception $e) { return $e->getMessage(); }
};
list($hn, $hp) = $drl_box('drlheld');
check(strpos($drl_refused($hn), 'install password') !== false, 'refused while the install password is held', $drl_refused($hn));
$hp->set('cvp_install_password', 'retiring');
$hp->save();
check(strpos($drl_refused($hn), 'cleanup is running') !== false, 'refused while it is retiring', $drl_refused($hn));
$cn = new ManagedNode((int)$hn->key, TRUE);
$cn->set('mgn_container_name', 'bgsite');
check(strpos($drl_refused($cn), 'container') !== false, 'refused on a container node, which is not the machine', $drl_refused($cn));
$sn = new ManagedNode(NULL);
$sn->set('mgn_name', 'HarnessTest break-glass selfhosted ' . $suffix);
$sn->set('mgn_slug', 'bg-selfhosted-' . $suffix);
$sn->set('mgn_host', '203.0.113.' . random_int(20, 240));
$sn->set('mgn_agent_public_key', base64_encode(random_bytes(32)));
$sn->set('mgn_agent_version', AgentVocabulary::FLOOR);
$sn->set('mgn_agent_primitives', 'host_report,disable_root_login');
$sn->save();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', (int)$sn->key);
check(JobCommandBuilder::build_disable_root_login($sn) === array('primitive' => 'disable_root_login', 'params' => array()),
	'a machine with no provision (self-hosted) is asked, with nothing in the request');
$old = new ManagedNode((int)$sn->key, TRUE);
$old->set('mgn_agent_primitives', 'host_report');
check(strpos($drl_refused($old), 'cannot turn root login off from here') !== false, 'a node whose agent lacks the word is refused', $drl_refused($old));

// A Docker host: its own node carries the placement record too, and is the
// machine; a site in a container on it is not (live check 2026-10-25).
$mh = new ManagedHost(NULL);
$mh->set('mgh_slug', 'bg-host-' . $suffix);
$mh->set('mgh_name', 'HarnessTest break-glass host ' . $suffix);
$mh->set('mgh_host', '198.51.100.250');
$mh->save();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', (int)$mh->key);
list($host_node, $host_prov) = $drl_box('drlhost', array('cvp_install_password' => 'kept'));
$host_node->set('mgn_mgh_managed_host_id', (int)$mh->key);
$host_node->save();
$mh->set('mgh_mgn_managed_node_id', (int)$host_node->key);
$mh->save();
$site_node = new ManagedNode(NULL);
$site_node->set('mgn_name', 'HarnessTest break-glass container ' . $suffix);
$site_node->set('mgn_slug', 'bg-container-' . $suffix);
$site_node->set('mgn_host', (string)$host_node->get('mgn_host'));
$site_node->set('mgn_mgh_managed_host_id', (int)$mh->key);
$site_node->save();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', (int)$site_node->key);
$host_node = new ManagedNode((int)$host_node->key, TRUE);
check(JobCommandBuilder::container_node_host($host_node) === null && JobCommandBuilder::disable_root_login_refusal($host_node) === null,
	'a Docker host\'s own node, which its placement record names, is the machine: asked, not refused');
$cv = JobCommandBuilder::container_node_host($site_node);
check($cv !== null && $cv['host_node'] && (int)$cv['host_node']->key === (int)$host_node->key,
	'a node on the same placement record that is not its host is a container, and is sent to the host\'s node');

$drl_env = function (array $obj) {
	return "=== [Step 1/1] disable_root_login ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
		'output' => json_encode($obj) . "\n", 'output_bytes' => 300))) . "\n[Step 1/1 OK]";
};
$drl_done = array('changed' => true, 'written' => 'dropin', 'permitrootlogin_before' => 'yes', 'permitrootlogin_after' => 'no',
	'reload' => 'reloaded', 'match_blocks' => false, 'refused' => '', 'reason' => '');
$drl_job = function ($n, array $obj, string $status = 'completed') use ($drl_env, $holder) {
	$job = new ManagementJob(NULL);
	$job->set('mjb_mgn_managed_node_id', (int)$n->key);
	$job->set('mjb_job_type', 'disable_root_login');
	$job->set('mjb_status', $status);
	$job->set('mjb_commands', array());
	$job->set('mjb_created_by', (int)$holder->key);
	$job->set('mjb_output', $drl_env($obj));
	$job->save();
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', (int)$job->key);
	JobResultProcessor::process(new ManagementJob((int)$job->key, TRUE));
	return new ManagementJob((int)$job->key, TRUE);
};

VaultUnlock::close((int)$holder->key);
list($kn, $kp) = $drl_box('drlkept');
check(BreakGlassRootAccess::keep($kp)['kept'], 'a box is kept');
$job = $drl_job($kn, $drl_done);
bg_register_events((int)$kp->key);
$raw = bg_raw((int)$kp->key);
check($raw['cvp_install_password'] === 'retired' && $raw['cvp_root_pass_vault_sealed'] === null && $raw['cvp_root_pass_sealed'] === null
	&& !bg_flag($raw['cvp_content_sealed']) && $raw['cvp_sealed_key'] === null,
	'a done run retires the kept row with no vault open, and clears the sealed copy and its key', json_encode($raw));
$ev = BreakGlassRootAccess::events_for_provision((int)$kp->key);
check(isset($ev[0]) && $ev[0]['event'] === 'root_login_off' && strpos($ev[0]['label'], 'Root login turned off by the agent by ') === 0,
	'and records it as turned off by the agent, naming who asked', json_encode($ev[0] ?? null));
$res = json_decode((string)$job->get('mjb_result'), true);
check(($res['permitrootlogin_after'] ?? '') === 'no' && ($res['reload'] ?? '') === 'reloaded', 'the job keeps what sshd read');

list($fn, $fp) = $drl_box('drlfailed', array('cvp_install_password' => 'retire_failed',
	'cvp_error' => 'Install password NOT retired - kept so the machine stays reachable. Retire job #1 failed.'));
$drl_job($fn, $drl_done);
bg_register_events((int)$fp->key);
$raw = bg_raw((int)$fp->key);
check($raw['cvp_install_password'] === 'retired' && $raw['cvp_error'] === null && $raw['cvp_root_pass_sealed'] === null,
	'a done run retires a retire_failed row and clears its NOT retired error', json_encode($raw));

list($nn, $np) = $drl_box('drlnot', array('cvp_install_password' => 'retire_failed'));
$drl_job($nn, array('permitrootlogin_after' => 'yes', 'refused' => 'not_effective', 'reason' => 'another setting wins') + $drl_done, 'failed');
check(bg_raw((int)$np->key)['cvp_install_password'] === 'retire_failed', 'a run not in force retires nothing');
check(JobResultProcessor::sanitise_disable_root_login(array('changed' => true, 'refused' => 'rm -rf'))['refused'] === ''
	&& JobResultProcessor::sanitise_disable_root_login(array('changed' => true, 'permitrootlogin_after' => 'no; x'))['permitrootlogin_after'] === 'nox',
	'the result is held to the script\'s shape');

harness_finish();
