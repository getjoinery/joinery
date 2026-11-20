<?php
/** @joinery-test
 * name: relay_moved_shard
 * tier: test-db
 * env: dev-only
 * needs: []
 * timeout: 60
 *
 * A hosted site follows its slot to another shard. A relay call that fails in
 * a way a moved, replaced or updated shard explains re-fetches the slot's
 * coordinates once and retries; other failures, and a self-hosted relay, do
 * not. New coordinates make the site send its address map again.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relays_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));

/** A relay row whose API fails with one class until its coordinates are re-fetched. */
class MovedShardStubRelay extends MailboxRelay {
	public $fails_with = '';
	public $found_elsewhere = true;
	public $refetches = 0;
	public $calls = 0;
	private $moved = false;
	public function client(): RelayClient {
		return (new ReflectionClass('RelayClient'))->newInstanceWithoutConstructor();
	}
	public function recoverCoordinates(): bool {
		$this->refetches++;
		$this->moved = $this->found_elsewhere;
		return $this->found_elsewhere;
	}
	public function call(): string {
		return $this->withApi(function (RelayClient $c) {
			$this->calls++;
			if ($this->fails_with !== '' && !$this->moved) {
				throw new RelayClientException($this->fails_with, 'stub');
			}
			return 'answered';
		});
	}
}

section('A failure a moved shard explains re-fetches the coordinates and retries');
foreach (array(RelayClient::FAIL_PIN_MISMATCH, RelayClient::FAIL_SIGNATURE_REFUSED, RelayClient::FAIL_UNREACHABLE,
		RelayClient::FAIL_REFUSED, RelayClient::FAIL_TIMEOUT) as $class) {
	$relay = new MovedShardStubRelay(NULL);
	$relay->fails_with = $class;
	$answer = $relay->call();
	ok($class . ': the call is answered after one re-fetch', $answer === 'answered' && $relay->refetches === 1 && $relay->calls === 2);
}

section('Nothing new to find: the failure stands');
$relay = new MovedShardStubRelay(NULL);
$relay->fails_with = RelayClient::FAIL_UNREACHABLE;
$relay->found_elsewhere = false;
$thrown = '';
try { $relay->call(); } catch (RelayClientException $e) { $thrown = $e->failure_class; }
ok('the same shard, still down: the original failure is thrown', $thrown === RelayClient::FAIL_UNREACHABLE && $relay->calls === 1);

section('Other failures never re-fetch');
foreach (array(RelayClient::FAIL_HTTP, RelayClient::FAIL_UNREADABLE) as $class) {
	$relay = new MovedShardStubRelay(NULL);
	$relay->fails_with = $class;
	$thrown = '';
	try { $relay->call(); } catch (RelayClientException $e) { $thrown = $e->failure_class; }
	ok($class . ': thrown as it came, no re-fetch', $thrown === $class && $relay->refetches === 0);
}

section('A self-hosted relay has nobody to ask, and a hosted one is asked once a request');
$own = new MailboxRelay(NULL);
$own->set('mrl_is_hosted', false);
ok('self-hosted: no re-fetch', $own->recoverCoordinates() === false);
MailboxRelay::resetCoordinateRefetch();
$hosted = new MailboxRelay(NULL);
$hosted->set('mrl_is_hosted', true);
$first = $hosted->recoverCoordinates();   // no fleet configured or nothing new: false either way
$probe = new ReflectionProperty('MailboxRelay', 'coordinates_refetched');
$asked = $probe->getValue();
ok('hosted: the row is marked as asked', $first === false && !empty($asked[intval($hosted->key)]));
MailboxRelay::resetCoordinateRefetch();
ok('the mark is cleared between passes', $probe->getValue() === array());

section('New coordinates make the site send its map again');
$row = new MailboxRelay(NULL);
$row->set('mrl_is_hosted', true);
$row->set('mrl_name', 'Hosted fleet slot');
$row->set('mrl_is_enabled', false);
$row->set('mrl_public_ip', '192.0.2.10');
$row->set('mrl_identity_fingerprint', str_repeat('a', 43));
$row->set('mrl_map_content_hash', str_repeat('c', 64));
$row->save();
harness_defer(function () use ($row) { try { $row->permanent_delete(); } catch (\Throwable $e) {} });
$coords = array('slug' => 't1', 'mx_hostname' => 't1.fleet.example', 'authserv_id' => 'shard.example', 'slot_id' => 1,
	'shard_public_ip' => '192.0.2.10', 'identity_fingerprint' => str_repeat('a', 43));
$client = new FleetClient();
$after = $client->applyCoordinates($coords);
ok('the same shard: the pushed map is still counted', (int)$after->key === (int)$row->key
	&& (string)$after->get('mrl_map_content_hash') === str_repeat('c', 64));
$coords['shard_public_ip'] = '192.0.2.20';
$after = $client->applyCoordinates($coords);
ok('a new address: the map is owed again', (string)$after->get('mrl_map_content_hash') === '' && (string)$after->get('mrl_public_ip') === '192.0.2.20');
$after->set('mrl_map_content_hash', str_repeat('c', 64));
$after->save();
$coords['identity_fingerprint'] = str_repeat('b', 43);
$after = $client->applyCoordinates($coords);
ok('a new pin: the map is owed again', (string)$after->get('mrl_map_content_hash') === '');

harness_finish();
