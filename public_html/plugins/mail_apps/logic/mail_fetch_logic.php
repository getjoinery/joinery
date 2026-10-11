<?php
/**
 * mail_apps/mail_fetch — messages' bytes, by folder and number.
 *
 *   what=header  each message's header block and size (a program's list)
 *   what=full    each message's whole bytes
 *
 * Bytes travel base64 in the JSON. A batch stops at a time and size budget
 * and names the numbers it did not reach (`more`), which the gateway asks for
 * again. A message that cannot be served says why instead of being served:
 * `locked` (sealed, window shut — answered as NO [UNAVAILABLE]), `unavailable`
 * (no bytes exist to serve, e.g. the source no longer holds it), `gone`.
 * Nothing is ever served in a message's place (specs/mail_apps_plugin.md
 * § Locked behaviour, rule 3).
 *
 * @version 1.0.0
 */

function mail_fetch_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$folder = MailAppAction::folder($ctx, (string)$input['folder']);
		$what = (string)$input['what'];
		if ($what === 'full') {
			// A whole message is held three times on its way out (its bytes, their
			// base64, the JSON) — up to ~30 MB each for the largest mail a mailbox
			// sends or stores.
			@ini_set('memory_limit', '384M');
		}
		$uids = MailAppAction::uidSet($ctx, $folder, (string)$input['uids']);
		$rows = MailAppFolders::liveRows($ctx, $folder, $uids);
		// A number named on its own that is no longer live is said to be gone,
		// rather than left out for the gateway to guess at.
		foreach (explode(',', preg_replace('/\s+/', '', (string)$input['uids'])) as $piece) {
			if (ctype_digit($piece) && !in_array(intval($piece), $uids, true)) {
				$uids[] = intval($piece);
			}
		}
		sort($uids);

		$deadline = microtime(true) + ($what === 'full' ? 20.0 : 10.0);
		$byte_budget = $what === 'full' ? 12 * 1048576 : 4 * 1048576;
		$spent = 0;
		$messages = array();
		$more = array();
		foreach ($uids as $uid) {
			if (!isset($rows[$uid])) {
				$messages[] = array('uid' => $uid, 'error' => 'gone');
				continue;
			}
			if ($messages && (microtime(true) > $deadline || $spent > $byte_budget)) {
				$more[] = $uid;
				continue;
			}
			$row = $rows[$uid];
			if ($row['notice']) {
				$bytes = MailAppRebuild::lockNotice($ctx, $uid, $row['created']);
				$result = $what === 'full'
					? array('ok' => true, 'bytes' => $bytes)
					: array('ok' => true, 'header' => substr($bytes, 0, strpos($bytes, "\r\n\r\n") + 4), 'size' => strlen($bytes));
			} else {
				$result = $what === 'full'
					? MailAppRebuild::bytes($ctx, intval($row['message_id']))
					: MailAppRebuild::header($ctx, intval($row['message_id']));
			}
			if (!$result['ok']) {
				$messages[] = array('uid' => $uid, 'error' => $result['error']);
				continue;
			}
			if ($what === 'full') {
				$spent += strlen($result['bytes']);
				$messages[] = array('uid' => $uid, 'size' => strlen($result['bytes']), 'bytes' => base64_encode($result['bytes']));
			} else {
				$spent += strlen($result['header']);
				$messages[] = array('uid' => $uid, 'size' => $result['size'], 'header' => base64_encode($result['header']));
			}
		}
		$ctx->countCheckAsActivity();
		return LogicResult::render(array(
			'folder'     => $folder['key'],
			'messages'   => $messages,
			'more'       => $more,
			'unlock_url' => $ctx->unlockUrl(),
		));
	});
}

function mail_fetch_logic_descriptor() {
	return MailAppAction::descriptor('Fetch numbered messages from a mail-app folder: header blocks and sizes, or whole bytes', array(
		'folder' => array('type' => 'string', 'required' => true, 'label' => 'Folder key', 'max_length' => 64),
		'uids'   => array('type' => 'string', 'required' => true, 'label' => 'UID set', 'max_length' => 65536),
		'what'   => array('type' => 'string', 'required' => true, 'label' => 'What', 'enum' => array('header', 'full')),
	));
}
?>
