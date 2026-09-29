<?php
/** @joinery-test
 * name: protection_level_change
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * ProtectionLevelChange — the one level-change sequence every consumer (Drive,
 * mail domain, mail mailbox, chat) runs through. Pinned here against a fake
 * scope so each rule is tested once, whatever the consumer:
 *   - the target must be a rung the scope offers, from a rung it can move;
 *   - a change needs a recent second factor, for an account that has one;
 *   - prerequisites are asked at call time;
 *   - ending sealing needs the actor's window only when the actor holds a vault;
 *   - the promise flips first, and the backlog is reported;
 *   - a converge pass is bounded, skips browser-sealed items, logs and keeps a
 *     failing item, and stops cleanly on a closed window.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
// The session starts before harness_boot(), which may print (a stale-mail sweep)
// and so make a later session_start() impossible on the CLI.
$has_session = vault_ensure_session();
harness_boot();

class PlcFakeScope implements ProtectionLevelScope {
	public $levels = array(ProtectionLevel::STANDARD, ProtectionLevel::PRIVATE_);
	public $current = ProtectionLevel::STANDARD;
	public $blocker = null;          // callable(string $target, int $actor): ?string
	public $flips = array();
	public $items = array();         // id => 'ok' | 'fail' | 'locked' | 'browser' | 'gone', or an int byte count
	public $done = array();
	public $asked_rows = null;
	public $rows = 10;
	public $bytes = null;

	public function label(): string { return 'this test scope'; }
	public function levels(): array { return $this->levels; }
	public function currentLevel(): string { return $this->current; }
	public function blockers(string $target, int $actor_id): ?string {
		return $this->blocker ? call_user_func($this->blocker, $target, $actor_id) : null;
	}
	public function flip(string $target): void { $this->flips[] = array($target, $this->remaining()); $this->current = $target; }
	public function pending(int $limit): array {
		$this->asked_rows = $limit;
		return array_slice(array_keys(array_diff_key($this->items, $this->done)), 0, $limit);
	}
	public function browserSealed($item): bool { return $this->items[$item] === 'browser'; }
	public function convertOne($item): ?int {
		$how = $this->items[$item];
		if ($how === 'fail') throw new RuntimeException('bad item');
		if ($how === 'locked') throw new VaultLockedException();
		if ($how === 'gone') { $this->done[$item] = true; return null; }
		$this->done[$item] = true;
		return is_int($how) ? $how : 1;
	}
	public function remaining(): int { return count(array_diff_key($this->items, $this->done)); }
	public function budget(): array { return array_filter(array('rows' => $this->rows, 'bytes' => $this->bytes)); }
}

$fresh = function () { return new PlcFakeScope(); };

// ---------------------------------------------------------------------------
section('The target must be a rung this scope offers');

$s = $fresh();
$r = ProtectionLevelChange::change($s, 'privat', 0);
check($r['status'] === ProtectionLevelChange::REFUSED && empty($s->flips), 'a mistyped level is refused and nothing flips');
$r = ProtectionLevelChange::change($s, ProtectionLevel::FORTRESS, 0);
check($r['status'] === ProtectionLevelChange::REFUSED && stripos($r['error'], 'browser') !== false,
	'Fortress on a scope that does not offer it is refused as the browser\'s, not the server\'s', $r['error']);
$s->current = ProtectionLevel::FORTRESS;
$r = ProtectionLevelChange::change($s, ProtectionLevel::STANDARD, 0);
check($r['status'] === ProtectionLevelChange::REFUSED && empty($s->flips),
	'a scope sitting at a rung it cannot move from here is refused', $r['error']);
$s = $fresh();
$r = ProtectionLevelChange::change($s, ProtectionLevel::STANDARD, 0);
check($r['status'] === ProtectionLevelChange::UNCHANGED && empty($s->flips), 'the level it already has is unchanged, no flip');

// ---------------------------------------------------------------------------
section('Prerequisites are asked at call time');

$s = $fresh();
$asked = array();
$s->blocker = function ($target, $actor) use (&$asked) { $asked[] = array($target, $actor); return 'Not yet: no vault.'; };
$r = ProtectionLevelChange::change($s, ProtectionLevel::PRIVATE_, 77);
check($r['status'] === ProtectionLevelChange::REFUSED && $r['error'] === 'Not yet: no vault.' && empty($s->flips),
	'a blocker refuses with the scope\'s own words and nothing flips');
check($asked === array(array(ProtectionLevel::PRIVATE_, 77)), 'the blocker is asked with the target and the actor');
$gate = ProtectionLevelChange::gate($s, ProtectionLevel::PRIVATE_);
check($gate['status'] === ProtectionLevelChange::OK && count($asked) === 1,
	'gate() alone never asks the prerequisites (a mailbox asks them after its grants sync)');

// ---------------------------------------------------------------------------
section('The promise flips first, and the backlog is reported');

$s = $fresh();
$s->items = array(1 => 'ok', 2 => 'ok', 3 => 'ok');
$r = ProtectionLevelChange::change($s, ProtectionLevel::PRIVATE_, 0);
check($r['status'] === ProtectionLevelChange::OK && $s->flips === array(array(ProtectionLevel::PRIVATE_, 3)),
	'the flip happens before any item converts');
check($r['remaining'] === 3 && $r['from'] === ProtectionLevel::STANDARD && $r['level'] === ProtectionLevel::PRIVATE_,
	'and the result reports the backlog and both levels');
check(empty($s->done), 'change() converts nothing itself');

// ---------------------------------------------------------------------------
section('Ending sealing needs the actor\'s window only when the actor holds a vault');

$no_vault = make_user('PlcNoVault');
$s = $fresh();
$s->current = ProtectionLevel::PRIVATE_;
$r = ProtectionLevelChange::change($s, ProtectionLevel::STANDARD, (int)$no_vault->key);
check($r['status'] === ProtectionLevelChange::OK, 'an actor with no vault lowers without a window (other holders converge in theirs)');

$holder = make_user('PlcHolder');
$kp = vault_fixture_server_vault((int)$holder->key);

$s = $fresh();
$s->current = ProtectionLevel::PRIVATE_;
$r = ProtectionLevelChange::change($s, ProtectionLevel::STANDARD, (int)$holder->key);
check($r['status'] === ProtectionLevelChange::REFUSED && stripos($r['error'], 'Unlock your vault') !== false && empty($s->flips),
	'an actor who holds a vault must have its window open to lower, and nothing flips', $r['error']);
$s = $fresh();
$r = ProtectionLevelChange::change($s, ProtectionLevel::PRIVATE_, (int)$holder->key);
check($r['status'] === ProtectionLevelChange::OK, 'raising needs no window (sealing takes only the public key)');
if (vault_apcu_usable() && $has_session && session_id() !== '') {
	vault_fixture_open_window((int)$holder->key, $kp['secret'], UserEncryptionVault::SCOPE_USER);
	$s = $fresh();
	$s->current = ProtectionLevel::PRIVATE_;
	$r = ProtectionLevelChange::change($s, ProtectionLevel::STANDARD, (int)$holder->key);
	check($r['status'] === ProtectionLevelChange::OK, 'with the window open the lowering goes through');
	VaultUnlock::close((int)$holder->key, UserEncryptionVault::SCOPE_USER);
} else {
	harness_skip('window-open lowering', 'APCu/session unavailable (run with -d apc.enable_cli=1)');
}

// ---------------------------------------------------------------------------
section('A change needs a recent second factor, for an account that has one');

if (!$has_session || session_id() === '') {
	harness_skip('step-up', 'no session could be started on the CLI');
} else {
	$sid = session_id();
	$clear_markers = function () use ($sid) {
		DbConnector::get_instance()->get_db_link()
			->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?")->execute(array($sid));
	};
	harness_defer($clear_markers);
	$saved = array('usr_user_id' => $_SESSION['usr_user_id'] ?? null, 'loggedin' => $_SESSION['loggedin'] ?? null);
	harness_defer(function () use ($saved) {
		foreach ($saved as $k => $v) { if ($v === null) { unset($_SESSION[$k]); } else { $_SESSION[$k] = $v; } }
	});

	$_SESSION['usr_user_id'] = (int)$no_vault->key;
	$_SESSION['loggedin'] = true;
	$clear_markers();
	$s = $fresh();
	$r = ProtectionLevelChange::change($s, ProtectionLevel::PRIVATE_, (int)$no_vault->key);
	check($r['status'] === ProtectionLevelChange::OK, 'an account with no second factor has nothing to step up with: the change goes through');

	$twofa = make_user('PlcTwoFactor');
	$twofa->enable_totp('JBSWY3DPEHPK3PXP');
	$twofa->save();
	$_SESSION['usr_user_id'] = (int)$twofa->key;
	$s = $fresh();
	$r = ProtectionLevelChange::change($s, ProtectionLevel::PRIVATE_, (int)$twofa->key);
	check($r['status'] === ProtectionLevelChange::STEPUP && empty($s->flips) && stripos($r['error'], 'Confirm it is you') !== false,
		'an account with a second factor and no recent confirmation is asked to step up; nothing flips');
	$s->blocker = function () { return 'blocked'; };
	check(ProtectionLevelChange::change($s, ProtectionLevel::PRIVATE_, (int)$twofa->key)['status'] === ProtectionLevelChange::STEPUP,
		'the step-up comes before the prerequisites');
	check(ProtectionLevelChange::change($s, ProtectionLevel::STANDARD, (int)$twofa->key)['status'] === ProtectionLevelChange::UNCHANGED,
		'no change asks for nothing');
	$s = $fresh();
	$s->blocker = function () { return 'Needs a holder with a vault.'; };
	$r = ProtectionLevelChange::change($s, ProtectionLevel::PRIVATE_, (int)$twofa->key, false);
	check($r['status'] === ProtectionLevelChange::REFUSED && $r['error'] === 'Needs a holder with a vault.',
		'a first choice on a new scope ($existing false) asks no step-up, but its prerequisites still hold');
	$s = $fresh();
	check(ProtectionLevelChange::change($s, ProtectionLevel::PRIVATE_, (int)$twofa->key, false)['status'] === ProtectionLevelChange::OK
		&& !empty($s->flips), 'and with them met it flips, unconfirmed');
	SessionControl::get_instance()->stamp_second_factor();
	$s = $fresh();
	$r = ProtectionLevelChange::change($s, ProtectionLevel::PRIVATE_, (int)$twofa->key);
	check($r['status'] === ProtectionLevelChange::OK, 'once confirmed, the change goes through');
	$clear_markers();
	unset($_SESSION['usr_user_id'], $_SESSION['loggedin']);
}

// ---------------------------------------------------------------------------
section('Converging: bounded, browser items left alone, a bad item kept, a closed window stops cleanly');

$s = $fresh();
$s->current = ProtectionLevel::PRIVATE_;
$s->items = array(1 => 'ok', 2 => 'browser', 3 => 'fail', 4 => 'ok', 5 => 'ok');
$s->rows = 10;
$pass = ProtectionLevelChange::convergeBatch($s);
check($pass['converted'] === 3 && $pass['failed'] === 1 && !$pass['locked'], 'good items convert, the bad one is counted and skipped');
check(!isset($s->done[2]), 'an item sealed for the browser is never converted server-side');
check($pass['remaining'] === 2, 'the bad item and the browser item stay in the backlog');

$s = $fresh();
$s->items = array(1 => 'ok', 2 => 'locked', 3 => 'ok');
$pass = ProtectionLevelChange::convergeBatch($s);
check($pass['locked'] === true && $pass['converted'] === 1 && !isset($s->done[3]),
	'a closed window stops the pass at once, keeping what already converted');

$s = $fresh();
$s->items = array_fill_keys(range(1, 30), 'ok');
$s->rows = 7;
$pass = ProtectionLevelChange::convergeBatch($s);
check($s->asked_rows === 7 && $pass['converted'] === 7 && $pass['remaining'] === 23, 'the consumer\'s row bound holds');
$pass = ProtectionLevelChange::convergeBatch($s, array('rows' => 3));
check($pass['converted'] === 3, 'a caller can narrow the bound');

$s = $fresh();
$s->items = array(1 => 40, 2 => 40, 3 => 40);
$s->bytes = 50;
$pass = ProtectionLevelChange::convergeBatch($s);
check($pass['converted'] === 2 && $pass['bytes'] === 80,
	'the byte bound stops after the item that crosses it, so one large item still makes progress');

$s = $fresh();
$s->items = array(1 => 'ok', 2 => 'ok');
$pass = ProtectionLevelChange::convergeBatch($s, null, microtime(true) - 1);
check($pass['converted'] === 0 && $pass['remaining'] === 2, 'a passed deadline takes nothing');

$s = $fresh();
$s->items = array(1 => 'gone', 2 => 'ok');
$pass = ProtectionLevelChange::convergeBatch($s);
check($pass['converted'] === 1, 'an item with nothing to do is not counted as converted');
check(ProtectionLevelChange::BYTE_BUDGET === 67108864, 'the one byte budget is 64 MB');

harness_finish();
?>
