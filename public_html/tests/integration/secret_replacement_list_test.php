<?php
/** @joinery-test
 * name: secret_replacement_list
 * tier: db
 * env: dev-only
 * needs: []
 * covers: [includes/SecretReplacementList.php, includes/SecretReconciler.php, adm/logic/admin_sealed_secrets_logic.php]
 */
/**
 * The replace-after-a-break-in list (spec security_weak_spots_2026_11 WP6).
 *
 * Code running as the web user can open every stored secret, so after a
 * break-in each has to be replaced. The list is built from the sealed-secrets
 * registry, groups each kind by what replacing it takes, and stays open until
 * every kind a person has to act on is marked done.
 *
 * Run: php tests/run.php db --only=tests/integration/secret_replacement_list_test.php
 *
 * @version 1.2
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$saved = (string)Globalvars::get_instance()->get_setting(SecretReplacementList::SETTING, false, true);
Setting::put(SecretReplacementList::SETTING, '');

$h = function (string $v) { return hash('sha256', $v); };
$before = array(
	'file_signed_url_key'                  => array($h('url-key-1')),                       // regenerable, one setting
	'jdi_direct_identities.jdi_secret_key' => array($h('d1'), $h('d2'), $h('d3')),           // regenerable-breaks-things, rows
	'smtp_password'                        => array($h('smtp-1')),                          // an implicit secret:true setting
	'dns_install_credential'               => array($h('dns-1')),                           // ephemeral
	'smtp2go_api_key'                      => array(),                                      // declared, not set
);

try {
	// -----------------------------------------------------------------------
	section('The list is built from the registry');
	// -----------------------------------------------------------------------
	$items = SecretReplacementList::items($before, null);
	$by = array_column($items, null, 'locator');
	check(isset($by['file_signed_url_key']) && $by['file_signed_url_key']['group'] === 'remint',
		'a regenerable secret that is set is listed, to make new here');
	check($by['file_signed_url_key']['can_remint'] ?? false, 'one held in a setting can be re-minted in place');
	$jdi = $by['jdi_direct_identities.jdi_secret_key'] ?? array();
	check(($jdi['group'] ?? '') === 'remint' && ($jdi['breaks_things'] ?? false) && ($jdi['count'] ?? 0) === 3,
		'one whose replacement cuts things off says so, with how many are stored');
	check(empty($jdi['can_remint']), 'a kind held in rows is never re-minted in place: its recipe only fills what is missing');
	check(($by['smtp_password']['group'] ?? '') === 'reenter' && ($by['smtp_password']['label'] ?? '') !== '',
		'an implicit secret setting appears under its setting name, to re-enter from the provider');
	check(($by['dns_install_credential']['group'] ?? '') === 'nothing', 'a per-run value is listed with nothing to do');
	check(!isset($by['smtp2go_api_key']), 'a declared secret that is not set is not listed');
	$groups = array_column($items, 'group');
	check($groups === array_values(array_merge(
			array_filter($groups, function ($g) { return $g === 'remint'; }),
			array_filter($groups, function ($g) { return $g === 'reenter'; }),
			array_filter($groups, function ($g) { return $g === 'nothing'; }))),
		'make-new first, then re-enter, then nothing');

	$live = SecretReconciler::present_hashes();
	check(is_array($live) && array_key_exists('file_signed_url_key', $live), 'the live hashes cover the registry');

	// -----------------------------------------------------------------------
	section('A kind is done only once what it held is replaced');
	// -----------------------------------------------------------------------
	check(SecretReplacementList::current() === null, 'no list is open to begin with');
	check(SecretReplacementList::mark('file_signed_url_key', true, 1, $items) !== '', 'nothing can be marked with no list open');
	SecretReplacementList::start(1, $before);
	$list = SecretReplacementList::current();
	$snap = $list !== null ? SecretReplacementList::snapshot($list) : array();
	check(($snap['jdi_direct_identities.jdi_secret_key'] ?? null) === array_map(function ($x) { return substr($x, 0, 16); },
			$before['jdi_direct_identities.jdi_secret_key']),
		'opening one records a short hash of every stored value, in its own table');
	check(!array_key_exists('snapshot', $list), 'and not in the setting every request reads');
	$items = SecretReplacementList::items($before, $list);
	check(($items[0]['unchanged'] ?? -1) === ($items[0]['count'] ?? -2), 'right after opening, nothing is replaced yet');
	$p = SecretReplacementList::progress($list, $items);
	check($p === array('done' => 0, 'total' => 3), 'three kinds to act on; the per-run value is not counted', json_encode($p));

	$why = SecretReplacementList::mark('smtp_password', true, 1, $items);
	check(strpos($why, 'still unchanged') !== false, 'a kind whose value was not replaced cannot be marked done', $why);

	// Two of the three Direct keys replaced, one left: still not done.
	$after = $before;
	$after['jdi_direct_identities.jdi_secret_key'] = array($h('d1'), $h('new-2'), $h('new-3'));
	$after['file_signed_url_key'] = array($h('url-key-2'));
	$items = SecretReplacementList::items($after, $list);
	$by = array_column($items, null, 'locator');
	check($by['jdi_direct_identities.jdi_secret_key']['unchanged'] === 1, 'a partly replaced kind counts what is left');
	check(SecretReplacementList::mark('jdi_direct_identities.jdi_secret_key', true, 1, $items) !== '', 'and cannot be marked done');
	check(SecretReplacementList::mark('file_signed_url_key', true, 1, $items) === '', 'a replaced kind is marked done');
	check(SecretReplacementList::mark('dns_install_credential', true, 1, $items) !== '', 'a per-run value cannot be marked');
	check(SecretReplacementList::mark('no_such_locator', true, 1, $items) !== '', 'nor a kind not on the list');
	check(!SecretReplacementList::finish($items), 'the list cannot be closed while kinds are left');

	$after['jdi_direct_identities.jdi_secret_key'] = array($h('new-1'), $h('new-2'), $h('new-3'));
	$after['smtp_password'] = array($h('smtp-2'));
	$items = SecretReplacementList::items($after, $list);
	SecretReplacementList::mark('jdi_direct_identities.jdi_secret_key', true, 1, $items);
	SecretReplacementList::mark('smtp_password', true, 1, $items);
	SecretReplacementList::mark('smtp_password', false, 1, $items);
	check(SecretReplacementList::progress(SecretReplacementList::current(), $items)['done'] === 2, 'a mark can be undone');
	SecretReplacementList::start(1, $after);
	check(SecretReplacementList::current()['started'] === $list['started'], 'opening again keeps the list already open');

	// -----------------------------------------------------------------------
	section('A kind this admin cannot replace is closed with a reason');
	// -----------------------------------------------------------------------
	$after['smtp_password'] = array($h('smtp-1'));   // left as it was
	$items = SecretReplacementList::items($after, $list);
	check(strpos(SecretReplacementList::mark('smtp_password', true, 1, $items), 'say why') !== false,
		'without a reason it is refused, and the refusal says a reason is the way past');
	check(SecretReplacementList::mark('smtp_password', true, 1, $items, 'Provider account is closed; nothing to rotate') === '',
		'with a reason it closes');
	$entry = SecretReplacementList::current()['done']['smtp_password'] ?? array();
	check(($entry['reason'] ?? '') !== '' && ($entry['unchanged'] ?? 0) === 1,
		'the reason is recorded, with how many values were left unchanged');
	check(SecretReplacementList::finish($items), 'with everything replaced and marked, the list closes');
	check(SecretReplacementList::current() === null, 'and nothing is left open');
	$left = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT count(*) FROM srs_secret_replacement_snapshots WHERE srs_list_started = ?');
	$left->execute(array($list['started']));
	check((int)$left->fetchColumn() === 0, 'closing removes its snapshot');

	// -----------------------------------------------------------------------
	section('A re-mint must store a new value');
	// -----------------------------------------------------------------------
	check(!SecretReconciler::acknowledge_remint('sbi_sandbox_installs.sbi_api_secret', 1),
		'a row-scoped kind is refused: its recipe would report success and replace nothing');
	$db = DbConnector::get_instance()->get_db_link();
	$read = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$read->execute(array('file_signed_url_key'));
	$old = (string)$read->fetchColumn();
	if ($old !== '' && SecretReconciler::can_write_now()) {
		File::provisionSigningKey();   // this request now remembers the key, as a page that served a file would
		$ok = SecretReconciler::acknowledge_remint('file_signed_url_key', 1);
		$read->execute(array('file_signed_url_key'));
		$new = (string)$read->fetchColumn();
		check($ok && $new !== '' && $new !== $old, 'a setting with a recipe gets a new value, and success says so');
		check($new !== '', 'and the setting is never left empty');
		// Put the site's own key back: links it signed before the test keep working.
		$db->prepare('UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?')->execute(array($old, 'file_signed_url_key'));
		Globalvars::get_instance()->forget_setting('file_signed_url_key');
	} else {
		harness_skip('no file URL signing key on this database to re-mint');
	}
} finally {
	if (($open = SecretReplacementList::current()) !== null) {
		DbConnector::get_instance()->get_db_link()
			->prepare('DELETE FROM srs_secret_replacement_snapshots WHERE srs_list_started = ?')->execute(array($open['started']));
	}
	Setting::put(SecretReplacementList::SETTING, $saved);
}

harness_finish();
