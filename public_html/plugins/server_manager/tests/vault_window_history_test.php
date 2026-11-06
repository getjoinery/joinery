<?php
/** @joinery-test
 * name: vault_window_history
 * tier: safe
 * env: any
 * needs: []
 *
 * VaultWindowHistory pairs the vault's window rows a log_table_tail event
 * read returns (specs/agent_event_log_filter.md): each close with the newest
 * earlier open of the same member, scope and session; an open with no close;
 * a close whose open is older than the rows read; the totals by holder,
 * reason and how each opened; and the one-line summary. The rows are shaped
 * as the node sends them, newest first, with notes in VaultAudit's format.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$h = function (string $session): string { return VaultAudit::handle($session); };
/** One event row as the node sends it. */
function vwh_row(int $id, string $event, int $user, string $time, string $note): array {
	return array('evl_event_log_id' => $id, 'evl_event' => $event, 'evl_usr_user_id' => $user,
		'evl_create_time' => $time, 'evl_was_success' => true, 'evl_note' => $note);
}
$O = VaultAudit::EVENT_OPENED;
$C = VaultAudit::EVENT_CLOSED;
$rows = array(
	// Newest first, as the node orders them.
	vwh_row(9, $C, 7, '2026-11-06T10:00:00Z', 'scope=user reason=daemon_restart session=' . $h('b') . ' open_seconds=600 unwraps=40'),
	vwh_row(8, $O, 8, '2026-11-06T09:55:00Z', 'scope=user via=recovery session=' . $h('c') . ' idle_cap=none absolute_cap=none idle_setting=30m holder=pool fallback=pool_rung'),
	vwh_row(7, $O, 7, '2026-11-06T09:50:00Z', 'scope=user via=passkey session=' . $h('b') . ' idle_cap=none absolute_cap=none idle_setting=30m holder=daemon'),
	vwh_row(6, $C, 7, '2026-11-06T09:40:00Z', 'scope=user reason=explicit_lock session=' . $h('a') . ' open_seconds=300 unwraps=12'),
	// Two sessions of the same member, interleaved: each close finds its own open.
	vwh_row(5, $O, 7, '2026-11-06T09:36:00Z', 'scope=user via=passphrase session=' . $h('x') . ' idle_cap=none absolute_cap=none idle_setting=30m holder=daemon'),
	vwh_row(4, $O, 7, '2026-11-06T09:35:00Z', 'scope=user via=passkey session=' . $h('a') . ' idle_cap=none absolute_cap=none idle_setting=30m holder=daemon'),
	// A close whose open was before these rows, from a release that wrote no holder.
	vwh_row(3, $C, 9, '2026-11-06T09:00:00Z', 'scope=user reason=idle_expired session=' . $h('z') . ' open_seconds=unknown unwraps=3'),
	// Not a vault row: ignored.
	vwh_row(2, 'login', 7, '2026-11-06T08:00:00Z', 'scope=user via=passkey'),
);
$vw = VaultWindowHistory::fromRows($rows);
$w = $vw['windows'];

// ---------------------------------------------------------------------------
section('Pairing');
// ---------------------------------------------------------------------------
check(count($w) === 5, 'five windows from seven vault rows', json_encode($w));
$by_open = array();
foreach ($w as $win) { $by_open[(string)$win['opened_at']] = $win; }
$a = $by_open['2026-11-06T09:35:00Z'] ?? array();
check(($a['reason'] ?? null) === 'explicit_lock' && ($a['unwraps'] ?? null) === 12 && ($a['via'] ?? null) === 'passkey',
	'a close is paired with its own session\'s open', json_encode($a));
$x = $by_open['2026-11-06T09:36:00Z'] ?? array();
check(array_key_exists('closed_at', $x) && $x['closed_at'] === null && $x['via'] === 'passphrase',
	'the other session\'s open, never closed in these rows, stays open', json_encode($x));
$b = $by_open['2026-11-06T09:50:00Z'] ?? array();
check(($b['reason'] ?? null) === 'daemon_restart' && $b['holder'] === 'daemon', 'a daemon window ended by a restart', json_encode($b));
$c = $by_open['2026-11-06T09:55:00Z'] ?? array();
check($c['holder'] === 'pool' && $c['fallback'] === 'pool_rung', 'a pool window the rung sent there says so', json_encode($c));
$z = $by_open[''] ?? array();
check(($z['reason'] ?? null) === 'idle_expired' && $z['holder'] === 'daemon',
	'a close with no open in range is kept, and its unwrap count says the daemon held it', json_encode($z));
check($w[0]['reason'] === 'daemon_restart', 'newest first');

// ---------------------------------------------------------------------------
section('Totals and the summary');
// ---------------------------------------------------------------------------
$t = $vw['totals'];
check($t['windows'] === 5 && $t['by_holder'] === array('daemon' => 4, 'pool' => 1), 'windows by holder', json_encode($t));
ksort($t['by_reason']);
check($t['by_reason'] === array('daemon_restart' => 1, 'explicit_lock' => 1, 'idle_expired' => 1), 'closes by reason', json_encode($t['by_reason']));
check($t['still_open'] === 2 && $t['open_before'] === 1, 'two not closed, one opened before the rows read', json_encode($t));
check($t['by_via']['passkey'] === 2 && $t['by_via']['recovery'] === 1 && $t['by_via']['passphrase'] === 1, 'opens by how', json_encode($t['by_via']));
$line = VaultWindowHistory::summary($t);
check(strpos($line, '5 windows: 4 daemon, 1 pool') === 0 && strpos($line, '2 not closed yet') !== false
	&& strpos($line, '1 opened before the rows read') !== false, 'the summary line', $line);
check(VaultWindowHistory::fromRows(array())['totals']['windows'] === 0 && VaultWindowHistory::summary(VaultWindowHistory::fromRows(array())['totals']) === '0 windows',
	'no rows, no windows');

harness_finish();
