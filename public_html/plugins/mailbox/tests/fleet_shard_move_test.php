<?php
/** @joinery-test
 * name: fleet_shard_move
 * tier: test-db
 * env: dev-only
 * needs: []
 * timeout: 180
 *
 * Moving a shard's tenants to another (FleetService::moveShardTenants), against
 * two REAL relay processes on loopback ports, each a shard with the plane's
 * operator key:
 *
 *   - the move is refused onto the same shard, one not taking tenants, or one
 *     without room
 *   - every live slot moves: active and suspended ones are registered on the
 *     new shard and remembered on the old; one still provisioning just moves
 *   - the shard left takes no new tenant, and cannot be retired while a
 *     moved tenant still collects from it
 *   - the site is given the old shard until the new one has registered it,
 *     then the new one with the old as the shard to collect from as well
 *   - the site keeps that shard on its relay row and collects from it without
 *     touching its own pickup times; it asks for its coordinates once an hour
 *   - the old shard lets the tenant go only after its mail name has pointed
 *     at the new shard for the hold, and only once its mail is collected;
 *     past the keeping time it goes with what is left
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayClient.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySpoolConsumer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayVersion.php'));
require_once(__DIR__ . '/lib/relay_ping_probe.php');

$binary = RelayPingProbe::binary();
if ($binary === null) {
	harness_skip('fleet shard move', 'no relay binary');
	harness_finish();
	return;
}
RelayClientIdentity::useThrowawayForTests(RelayClientIdentity::KIND_CLIENT);
RelayClientIdentity::useThrowawayForTests(RelayClientIdentity::KIND_OPERATOR);
harness_defer(function () { RelayClientIdentity::resetForTests(); });
$suffix = getmypid() . random_int(100, 999);
$client_key = RelayClientIdentity::publicKey(RelayClientIdentity::KIND_CLIENT);

$probes = array();
$make_shard = function (string $label, string $ip, int $capacity) use ($binary, &$probes) {
	$probe = new RelayPingProbe($binary);
	$probe->setOperatorKey(RelayClientIdentity::publicKey(RelayClientIdentity::KIND_OPERATOR));
	if (!$probe->start()) {
		return null;
	}
	$probes[$label] = $probe;
	RelayClient::$base_url_override[$ip] = $probe->baseUrl();
	$shard = new MailboxFleetShard(NULL);
	$shard->set('mfs_name', 'fsm-' . $label);
	$shard->set('mfs_hostname', $label . '.fsm.example');
	$shard->set('mfs_public_ip', $ip);
	$shard->set('mfs_identity_fingerprint', $probe->fingerprint());
	$shard->set('mfs_capacity', $capacity);
	$shard->set('mfs_provisioned_version', RelayVersion::REMOVES_WITH_MAIL_LEFT);
	$shard->save();
	$shard->load();
	harness_register_row('mfs_mailbox_fleet_shards', 'mfs_mailbox_fleet_shard_id', $shard->key);
	return $shard;
};
harness_defer(function () use (&$probes) {
	foreach ($probes as $probe) { $probe->stop(); }
	RelayClient::$base_url_override = array();
	FleetService::$dns_lookup = null;
});

$make_slot = function (MailboxFleetShard $shard, string $slug, string $status) use ($client_key, $suffix) {
	$slot = new MailboxFleetSlot(NULL);
	$slot->set('mft_mfs_mailbox_fleet_shard_id', intval($shard->key));
	$slot->set('mft_slug', $slug);
	$slot->set('mft_status', $status);
	$slot->set('mft_public_key', $client_key);
	$slot->set('mft_mx_hostname', $slug . '.fleet-' . $suffix . '.example');
	$slot->save();
	$slot->load();
	harness_register_row('mft_mailbox_fleet_slots', 'mft_mailbox_fleet_slot_id', $slot->key);
	return $slot;
};
$reload = function (MailboxFleetSlot $slot) { return new MailboxFleetSlot(intval($slot->key), TRUE); };

$old = $make_shard('old', '192.0.2.61', 5);
$new = $make_shard('new', '192.0.2.62', 2);
if ($old === null || $new === null) {
	ok('both relay listeners start', false);
	harness_finish();
	return;
}

$active = $make_slot($old, 'mva' . $suffix, MailboxFleetSlot::STATUS_PROVISIONING);
ok('the active tenant registers on the shard it starts on', FleetService::applyTenant($active, 'add_tenant')
	&& $probes['old']->hasTenant('mva' . $suffix) && (string)$active->get('mft_status') === MailboxFleetSlot::STATUS_ACTIVE);
$suspended = $make_slot($old, 'mvs' . $suffix, MailboxFleetSlot::STATUS_SUSPENDED);
$probes['old']->addTenantWithKey('mvs' . $suffix, $client_key, '');
$fresh = $make_slot($old, 'mvp' . $suffix, MailboxFleetSlot::STATUS_PROVISIONING);

// ---------------------------------------------------------------------------
section('A move that may not start is refused');
$refusal = function (MailboxFleetShard $from, MailboxFleetShard $to): string {
	try { FleetService::moveShardTenants($from, $to); } catch (MailboxFleetShardException $e) { return $e->getMessage(); }
	return '';
};
ok('onto itself', strpos($refusal($old, $old), 'another shard') !== false);
ok('onto a shard without room (2 places, 3 tenants)', strpos($refusal($old, $new), 'room for 2') !== false);
$new->set('mfs_capacity', 5);
$new->set('mfs_is_active', false);
$new->save();
ok('onto a shard not taking tenants', strpos($refusal($old, $new), 'not taking tenants') !== false);
$new->set('mfs_is_active', true);
$new->save();
ok('a refused move changed nothing', intval($reload($active)->get('mft_mfs_mailbox_fleet_shard_id')) === intval($old->key)
	&& (bool)(new MailboxFleetShard(intval($old->key), TRUE))->get('mfs_is_active'));

// ---------------------------------------------------------------------------
section('Every live slot moves, and the new shard registers each');
$result = FleetService::moveShardTenants($old, $new);
ok('three moved, three registered at once', $result === array('moved' => 3, 'registered' => 3), json_encode($result));
$active = $reload($active); $suspended = $reload($suspended); $fresh = $reload($fresh);
ok('each is on the new shard', intval($active->get('mft_mfs_mailbox_fleet_shard_id')) === intval($new->key)
	&& intval($suspended->get('mft_mfs_mailbox_fleet_shard_id')) === intval($new->key)
	&& intval($fresh->get('mft_mfs_mailbox_fleet_shard_id')) === intval($new->key));
ok('the new shard holds all three, the suspended one too', $probes['new']->hasTenant('mva' . $suffix)
	&& $probes['new']->hasTenant('mvs' . $suffix) && $probes['new']->hasTenant('mvp' . $suffix));
ok('the registered ones remember the shard they left', intval($active->get('mft_moved_from_shard_id')) === intval($old->key)
	&& intval($suspended->get('mft_moved_from_shard_id')) === intval($old->key)
	&& !(bool)$active->get('mft_needs_shard_add') && !(bool)$suspended->get('mft_needs_shard_add'));
ok('the one still provisioning left nothing behind and is active', intval($fresh->get('mft_moved_from_shard_id')) === 0
	&& (string)$fresh->get('mft_status') === MailboxFleetSlot::STATUS_ACTIVE);
ok('the old shard still holds its two tenants', $probes['old']->hasTenant('mva' . $suffix) && $probes['old']->hasTenant('mvs' . $suffix));
$old = new MailboxFleetShard(intval($old->key), TRUE);
ok('the shard left takes no new tenant', !(bool)$old->get('mfs_is_active'));
ok('it holds no slot, and two are still leaving it', $old->slotCount() === 0 && $old->leavingCount() === 2);
$refused = '';
try { $old->retire(); } catch (MailboxFleetShardException $e) { $refused = $e->getMessage(); }
ok('it cannot be retired while they collect from it', strpos($refused, 'still collecting mail') !== false, $refused);

// ---------------------------------------------------------------------------
section('What the site is told');
$coords = FleetService::coordinates($active);
ok('the new shard, with the old one to collect from as well', $coords['shard_public_ip'] === '192.0.2.62'
	&& $coords['authserv_id'] === 'new.fsm.example'
	&& ($coords['drain']['shard_public_ip'] ?? '') === '192.0.2.61'
	&& ($coords['drain']['identity_fingerprint'] ?? '') === $probes['old']->fingerprint()
	&& ($coords['drain']['authserv_id'] ?? '') === 'old.fsm.example');
ok('a slot that left nothing behind names no second shard', !isset(FleetService::coordinates($fresh)['drain']));
$active->set('mft_needs_shard_add', true);
$waiting = FleetService::coordinates($active);
ok('until the new shard has registered it: the old shard, and no second one', $waiting['shard_public_ip'] === '192.0.2.61'
	&& $waiting['identity_fingerprint'] === $probes['old']->fingerprint() && !isset($waiting['drain']));
FleetService::noteCoordinatesServed($active);
ok('and that answer is not counted as the site picking up the new shard', trim((string)$active->get('mft_move_seen_time')) === '');
ok('standing: waiting for the new shard', strpos(FleetService::moveStep($active), 'new shard to register') !== false);
$active->set('mft_needs_shard_add', false);
$active->save();
FleetService::noteCoordinatesServed($active);
ok('the pick-up is recorded once it is given the new shard', trim((string)$reload($active)->get('mft_move_seen_time')) !== '');
ok('standing: point the mail name', strpos(FleetService::moveStep($reload($active)), 'Point its mail name') !== false);

// ---------------------------------------------------------------------------
section('The site keeps the shard it left and collects from it');
class FsmFleetClient extends FleetClient {
	public $asked = 0;
	public $answer = array();
	public function configured(): bool { return true; }
	public function status(): array {
		$this->asked++;
		$this->applyCoordinates($this->answer);
		return array('enrolled' => true, 'coordinates' => $this->answer);
	}
}
$site = new FsmFleetClient();
$relay = $site->applyCoordinates($coords);
harness_register_row('mrl_mailbox_relays', 'mrl_mailbox_relay_id', $relay->key);
$relay->set('mrl_is_enabled', false);
$relay->save();
$drain = $relay->drainSource();
ok('the relay row names the shard left', $drain === array('ip' => '192.0.2.61', 'pin' => $probes['old']->fingerprint(), 'authserv_id' => 'old.fsm.example'));
ok('and when it was told', trim((string)$relay->get('mrl_coordinates_check_time')) !== '');

$site->answer = $coords;
ok('asked within the hour: no question goes out', $site->refreshCoordinates() === '' && $site->asked === 0);
ok('an hour on: one question, nothing moved', $site->refreshCoordinates(time() + 3700) === '' && $site->asked === 1);
$elsewhere = $coords;
$elsewhere['shard_public_ip'] = '192.0.2.99';
$site->answer = $elsewhere;
ok('an hour on, on another shard: it says so', strpos($site->refreshCoordinates(time() + 3700), 'another relay shard') !== false);
$relay = $site->applyCoordinates($coords);

$waiting_id = '1700000001-' . bin2hex(random_bytes(4));
$probes['old']->spoolWrite('mva' . $suffix, $waiting_id, 'meta', json_encode(array('spool_id' => $waiting_id, 'recipient' => 'no-at-sign', 'key_kind' => 'transport')));
$probes['old']->spoolWrite('mva' . $suffix, $waiting_id, 'seal', 'v2.seal.WAITING');
$pulled = (new RelaySpoolConsumer($relay, $drain))->pull();
ok('the entry waiting on the shard left is collected', ($pulled['status'] ?? '') === 'success' && ($pulled['seals'] ?? -1) === 1
	&& ($pulled['acked'] ?? -1) === 1, json_encode($pulled));
ok('and is gone from that shard', $probes['old']->spoolIds('mva' . $suffix) === array());
ok('the relay row\'s own pickup times are left alone',
	trim((string)(new MailboxRelay(intval($relay->key), TRUE))->get('mrl_last_pull_time')) === '');

$plain = $coords;
unset($plain['drain']);
$relay = $site->applyCoordinates($plain);
ok('coordinates that stop naming it clear it', $relay->drainSource() === null);

// ---------------------------------------------------------------------------
section('The old shard lets a tenant go only when nothing more can arrive');
$active = $reload($active);
$name = strtolower((string)$active->get('mft_mx_hostname'));
$now = time();
FleetService::$dns_lookup = function (string $host) { return array('192.0.2.61'); };
$step = FleetService::finishMove($active, $now);
ok('its mail name still points at the old shard: nothing happens', !$step['done']
	&& trim((string)$reload($active)->get('mft_move_dns_time')) === '' && $probes['old']->hasTenant('mva' . $suffix));
FleetService::$dns_lookup = function (string $host) { return array('192.0.2.62'); };
$step = FleetService::finishMove($active, $now);
$active = $reload($active);
ok('it points at the new shard: the time is kept, the tenant stays', !$step['done']
	&& trim((string)$active->get('mft_move_dns_time')) !== '' && $probes['old']->hasTenant('mva' . $suffix));
ok('standing: the old shard removes it after the hold', strpos(FleetService::moveStep($active), 'hours after that') !== false);
$step = FleetService::finishMove($active, $now + 3600);
ok('inside the hold: the tenant stays', !$step['done'] && $probes['old']->hasTenant('mva' . $suffix));

$late_id = '1700000002-' . bin2hex(random_bytes(4));
$probes['old']->spoolWrite('mva' . $suffix, $late_id, 'meta', '{}');
$probes['old']->spoolWrite('mva' . $suffix, $late_id, 'seal', 'v2.seal.LATE');
$after_hold = $now + (FleetService::MOVE_HOLD_HOURS + 1) * 3600;
$step = FleetService::finishMove($active, $after_hold);
ok('past the hold with mail still waiting: the old shard keeps it', !$step['done'] && $probes['old']->hasTenant('mva' . $suffix)
	&& intval($reload($active)->get('mft_moved_from_shard_id')) === intval($old->key));
$step = FleetService::finishMove($active, $now + 31 * 86400);
$active = $reload($active);
ok('past the keeping time: it goes with what is left, and says so', $step['done'] && $step['discarded'] !== '', json_encode($step));
clearstatcache(); // PHP remembers the last path it looked at, and that was this tenant's folder
ok('the old shard no longer holds it, and the slot no longer names that shard', !$probes['old']->hasTenant('mva' . $suffix)
	&& intval($active->get('mft_moved_from_shard_id')) === 0,
	'tenant dir: ' . var_export($probes['old']->hasTenant('mva' . $suffix), true) . ', from: ' . var_export($active->get('mft_moved_from_shard_id'), true));
ok('the new shard still holds it', $probes['new']->hasTenant('mva' . $suffix));

$suspended = $reload($suspended);
FleetService::finishMove($suspended, $now);
$step = FleetService::finishMove($suspended, $after_hold);
ok('a tenant with nothing waiting goes once the hold is over', $step['done'] && $step['discarded'] === ''
	&& !$probes['old']->hasTenant('mvs' . $suffix) && intval($reload($suspended)->get('mft_moved_from_shard_id')) === 0, json_encode($step));

$old = new MailboxFleetShard(intval($old->key), TRUE);
ok('nothing is leaving the old shard any more', $old->leavingCount() === 0);
$notes = $old->retire();
ok('and it retires', (string)(new MailboxFleetShard(intval($old->key), TRUE))->get('mfs_delete_time') !== '' && is_array($notes));

section('A move whose old shard is gone is closed');
$gone = $make_slot($new, 'mvg' . $suffix, MailboxFleetSlot::STATUS_ACTIVE);
$gone->set('mft_moved_from_shard_id', intval($old->key));
$gone->save();
$step = FleetService::finishMove($gone, $now);
ok('the slot stops naming the retired shard', $step['done'] && intval($reload($gone)->get('mft_moved_from_shard_id')) === 0
	&& !isset(FleetService::coordinates($reload($gone))['drain']));

harness_finish();
