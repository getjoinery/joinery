<?php
/** @joinery-test
 * name: fortress_search
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * The server's half of search over Fortress mail (specs/client_custody_mail.md
 * § R5, WP3): what a browser's own index is built from and how its hits come
 * back.
 *
 *  - writing a row's search text stamps iem_search_written_time in the same
 *    UPDATE (sealedWriteMarks), and a rewrite stamps it again;
 *  - search_entries pages the owner's Fortress rows both ways by (time, id):
 *    no row twice, none skipped across a page edge, drafts and another
 *    person's rows never; a row whose text is written after the cursor passed
 *    its id comes back on the next catch-up; the overlap reaches back;
 *  - the search key: create-only, refuses another scope or a key the vault
 *    does not have, takes the generation from the key it was sealed to, and
 *    the mail rotation walks it;
 *  - thread_list's device_hits: exactly those messages' threads within the
 *    scope, nothing outside it; the packed ids decode as the browser wrote
 *    them (fixtures/device_hits_vector.json, also read by search_index.mjs);
 *    malformed or oversized lists are refused; device_search carries ids and
 *    no term (every mailbox in view is end-to-end), still bounded by scope;
 *  - MailboxSearchKey refuses a write on anyone's behalf (authenticate_write):
 *    acceptBrowserKey() is the only door.
 *
 * Run: php tests/run.php test-db --filter=fortress_search
 *
 * @version 1.1 - device_search without a term; the search key refuses writes through the model
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(__DIR__ . '/lib/fortress_fixture.php');

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

try {
	$db = DbConnector::get_instance()->get_db_link();
	$fx = fortress_fixture('Search');
	$other = fortress_fixture('SearchOther');
	harness_defer(function () use ($db, $fx, $other) {
		$db->prepare('DELETE FROM msk_mailbox_search_keys WHERE msk_usr_user_id IN (?, ?)')
			->execute(array($fx['owner_id'], $other['owner_id']));
	});
	$owner = $fx['owner_id'];

	$ids = array();
	foreach (array('alphaword', 'bravoword', 'charlieword', 'deltaword') as $w) {
		$ids[] = fortress_ingest($fx, 'Search ' . $w, $w);
	}
	$foreign = fortress_ingest($other, 'Not yours', 'echoword');
	check(count(array_filter($ids)) === 4 && $foreign > 0, 'five Fortress messages arrived, one of them another person\'s');

	// ---------------------------------------------------------------- the stamp
	section('writing the search text stamps when');

	$stamped = true;
	foreach ($ids as $id) {
		if (fortress_row($id)['iem_search_written_time'] === null) { $stamped = false; }
	}
	check($stamped, 'every ingested row carries iem_search_written_time');
	$row = fortress_row($ids[0]);
	$dek = fortress_open_dek($fx, (string)$row['iem_sealed_key']);
	$text = fortress_open_field($fx, (string)$row['iem_search_text'], $dek, 'mail:' . $ids[0] . ':iem_search_text');
	if (strncmp($text, 'gz:', 3) === 0) { $text = gzdecode(base64_decode(substr($text, 3))); }
	check(stripos($text, 'alphaword') !== false, 'and the search text opens under the owner\'s key');
	check(InboundEmailMessage::SEARCH_TEXT_MAX_CHARS === 32768, 'the search text cap is 32768 characters');

	// ---------------------------------------------------------------- paging
	section('search_entries pages the owner\'s rows both ways');

	$walk = function (string $order, int $limit) use ($owner): array {
		$seen = array();
		$time = ''; $id = 0;
		for ($guard = 0; $guard < 20; $guard++) {
			$page = MailboxDeviceSearch::entries($owner, $order, $time, $id, false, false, $limit);
			foreach ($page['entries'] as $e) { $seen[] = $e['id']; }
			if ($page['next'] === null) { break; }
			$time = $page['next']['time']; $id = $page['next']['id'];
		}
		return $seen;
	};
	$old = $walk('old', 1);
	$new = $walk('new', 3);
	$sorted = $ids; sort($sorted);
	$old_sorted = $old; sort($old_sorted);
	check($old_sorted === $sorted, 'walking back one row a page returns each of the owner\'s rows once', implode(',', $old));
	check($old === array_reverse($new), 'walking forward three a page returns them once, in the reverse order', implode(',', $new));
	check(!in_array($foreign, $old, true), 'another person\'s row is never returned');

	$first = MailboxDeviceSearch::entries($owner, 'old', '', 0, false, true);
	check(($first['total'] ?? null) === 4, 'with_total counts the owner\'s rows', (string)($first['total'] ?? 'none'));
	$e = $first['entries'][0];
	check(($e['sealed']['sealed_scope'] ?? '') === 'mail' && strncmp((string)($e['sealed']['iem_search_text'] ?? ''), 'v1.edge.', 8) === 0
		&& ($e['sealed']['sealed_ad_prefix'] ?? '') === 'mail:', 'an entry carries the sealed search text in the shape the browser opens');
	check(!isset($e['sealed']['iem_body_plain']) && !isset($e['sealed']['iem_subject']), 'and nothing else of the message');
	check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', (string)$first['server_time']) === 1, 'the page says the server\'s time');

	$db->prepare("UPDATE iem_inbound_email_messages SET iem_direction = 'draft' WHERE iem_inbound_email_message_id = ?")
		->execute(array($ids[3]));
	$without_draft = $walk('old', 200);
	check(!in_array($ids[3], $without_draft, true) && count($without_draft) === 3, 'a draft is never returned');
	$db->prepare("UPDATE iem_inbound_email_messages SET iem_direction = 'inbound' WHERE iem_inbound_email_message_id = ?")
		->execute(array($ids[3]));

	// ---------------------------------------------------------------- catching up
	section('a row written after the cursor passed it comes back');

	$caught = MailboxDeviceSearch::entries($owner, 'new', '', 0);
	$cursor = $caught['last'];
	check($cursor !== null && $cursor['id'] === $ids[3], 'a caught-up walk ends at the newest row', json_encode($cursor));
	$empty = MailboxDeviceSearch::entries($owner, 'new', $cursor['time'], $cursor['id']);
	check(count($empty['entries']) === 0, 'nothing is newer than the cursor');

	$vault = VaultClientCustody::loadVault($owner, 'mail');
	InboundEmailMessage::sealColumns($ids[0], $vault, array('iem_search_text' => 'Search alphaword rewritten'), $dek);
	$after = MailboxDeviceSearch::entries($owner, 'new', $cursor['time'], $cursor['id']);
	check(count($after['entries']) === 1 && $after['entries'][0]['id'] === $ids[0],
		'a rewrite of the oldest row\'s search text stamps it again, and the next catch-up returns it');
	$other_col = fortress_row($ids[1])['iem_search_written_time'];
	InboundEmailMessage::sealColumns($ids[1], $vault, array('iem_snippet' => 'just the snippet'), fortress_open_dek($fx, (string)fortress_row($ids[1])['iem_sealed_key']));
	check(fortress_row($ids[1])['iem_search_written_time'] === $other_col, 'writing another sealed column leaves the stamp alone');

	$overlap = MailboxDeviceSearch::entries($owner, 'new', $after['last']['time'], $after['last']['id'], true);
	check(count($overlap['entries']) === 4, 'a catch-up\'s first page reaches back ten minutes', (string)count($overlap['entries']));

	$bad = null;
	try { MailboxDeviceSearch::entries($owner, 'new', "2026-01-01'; --"); } catch (MailboxDeviceSearchException $ex) { $bad = $ex->getMessage(); }
	check($bad !== null, 'a malformed cursor time is refused');

	// ---------------------------------------------------------------- the key
	section('the search key: create-only, the mail vault\'s');

	$raw = random_bytes(32);
	$blob = 'v1.edgeseal.mail.' . $fx['box']->sealEdge($raw, $fx['pub']);
	$refused = function (callable $fn): ?string {
		try { $fn(); } catch (MailboxSearchKeyException $e) { return $e->getMessage(); }
		return null;
	};
	check($refused(function () use ($owner, $fx) {
		MailboxSearchKey::acceptBrowserKey($owner, 'v1.edgeseal.drive.' . $fx['box']->sealEdge(random_bytes(32), $fx['pub']), $fx['pub']);
	}) !== null, 'a key sealed to another scope is refused');
	check($refused(function () use ($owner, $blob) {
		MailboxSearchKey::acceptBrowserKey($owner, $blob, base64_encode(random_bytes(32)));
	}) !== null, 'a key sealed to a key the vault does not have is refused');
	check(MailboxSearchKey::sealedKeyFor($owner) === null, 'neither was stored');
	MailboxSearchKey::acceptBrowserKey($owner, $blob, $fx['pub']);
	check(MailboxSearchKey::sealedKeyFor($owner) === $blob, 'the owner\'s browser stores its key once');
	check($refused(function () use ($owner, $fx) {
		MailboxSearchKey::acceptBrowserKey($owner, 'v1.edgeseal.mail.' . $fx['box']->sealEdge(random_bytes(32), $fx['pub']), $fx['pub']);
	}) !== null && MailboxSearchKey::sealedKeyFor($owner) === $blob, 'a second key is refused and the first stays');
	check(fortress_open_dek($fx, (string)MailboxSearchKey::sealedKeyFor($owner)) === $raw, 'the owner\'s key opens it');
	$krow = MailboxSearchKey::loadForUser($owner);
	check(intval($krow->get('msk_key_generation')) === intval($vault->get('uev_key_generation'))
		&& intval($krow->get('msk_sealed_owner_user_id')) === $owner, 'it carries the vault\'s generation and its owner');
	$walked = MailboxSearchKey::browserResealPage($owner, 'mail', intval($vault->get('uev_key_generation')), 0, 10);
	check(count($walked['rows']) === 1 && $walked['rows'][0]['sealed_dek'] === $blob, 'a mail rotation walks it');
	check(in_array('MailboxSearchKey', VaultUnlock::clientResealsFor('mail')['classes'], true),
		'and the mailbox registers it for the rotation');
	check(MailboxSearchKey::sealedKeyFor($other['owner_id']) === null, 'another person has no key from this');

	// ---------------------------------------------------------------- device hits
	section('thread_list: device_hits joins the search');

	$vector = json_decode((string)file_get_contents(__DIR__ . '/fixtures/device_hits_vector.json'), true);
	check(MailboxDeviceSearch::decodeHits($vector['packed']) === $vector['ids'], 'the browser\'s packed ids decode to the same list');
	check(MailboxDeviceSearch::decodeHits('') === array(), 'an empty list is no hits');
	foreach (array('not base64!' => 'malformed', base64_encode("\x05\x00") => 'a repeated id', base64_encode("\x85") => 'a cut-short number',
			base64_encode(str_repeat("\x01", MailboxDeviceSearch::MAX_HITS + 1)) => 'more than the most ids a search may name') as $packed => $why) {
		$threw = false;
		try { MailboxDeviceSearch::decodeHits($packed); } catch (MailboxDeviceSearchException $ex) { $threw = true; }
		check($threw, 'refused: ' . $why);
	}

	$service = new MailboxService(MailboxViewer::forUser($owner, 0));
	$alias_id = intval($fx['alias']->key);
	$latest = function (array $list): array {
		return array_map(function ($t) { return intval($t['latest_id']); }, $list['threads']);
	};
	$plain = $service->listThreads($alias_id, array('q' => 'bravoword'));
	check(count($plain['threads']) === 0, 'the server alone finds nothing in Fortress mail');
	$hit = $service->listThreads($alias_id, array('q' => 'bravoword', 'device_hits' => array($ids[1])));
	check($latest($hit) === array($ids[1]), 'the device\'s hit comes back as its thread', implode(',', $latest($hit)));
	$two = $service->listThreads(null, array('q' => 'word', 'device_hits' => array($ids[0], $ids[2])));
	$got = $latest($two); sort($got);
	check($got === array($ids[0], $ids[2]), 'several hits across all mailboxes', implode(',', $got));
	$outside = $service->listThreads(null, array('q' => 'echoword', 'device_hits' => array($foreign)));
	check(count($outside['threads']) === 0, 'an id outside the viewer\'s mailboxes matches nothing');
	$none = $service->listThreads($alias_id, array('q' => 'zzz', 'device_hits' => array()));
	check(count($none['threads']) === 0, 'no hits and no server match is an empty list');

	section('a search over end-to-end mail alone sends no term');

	$only = $service->listThreads($alias_id, array('q' => '', 'device_search' => true, 'device_hits' => array($ids[1], $foreign)));
	check($latest($only) === array($ids[1]), 'the ids alone make the search, still bounded by the scope', implode(',', $latest($only)));
	$empty_only = $service->listThreads($alias_id, array('q' => '', 'device_search' => true, 'device_hits' => array()));
	check(count($empty_only['threads']) === 0, 'no ids is an empty result, not the whole mailbox');
	$inbox_only = $service->listThreads($alias_id, array('q' => '', 'inbox' => true, 'device_search' => true, 'device_hits' => array($ids[2])));
	check(($inbox_only['search_scope'] ?? '') === 'all_mail', 'it widens from the Inbox to all mail like any search');
	$logic_src = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/logic/thread_list_logic.php'));
	check(strpos($logic_src, "\$filters['q'] = '';") !== false, 'thread_list drops any term that comes with device_only');

	section('the search key is written only through its own door');

	$refused_save = null;
	// Even a superadmin acting through the model's permission check is refused.
	try { $k = new MailboxSearchKey(NULL); $k->authenticate_write(array('current_user_id' => $owner, 'current_user_permission' => 10)); }
	catch (\Throwable $ex) { $refused_save = get_class($ex); }
	check($refused_save !== null && MailboxSearchKey::sealedKeyFor($other['owner_id']) === null, 'a write on anyone\'s behalf through the model is refused', (string)$refused_save);

} catch (\Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
