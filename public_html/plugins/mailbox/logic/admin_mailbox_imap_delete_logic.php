<?php
/**
 * Logic for the IMAP-feed delete choice (specs/mailbox_data_loss_fixes.md, Fix 8).
 *
 * An IMAP-mirrored ('remote') message stores its body text locally but fetches
 * attachments/raw on demand from the source account. Deleting the account (or an
 * alias that owns one) would strand those messages — their attachments would no
 * longer load. So when a to-be-deleted account has reference-backed messages,
 * this page asks the operator to choose, and never leaves a silently-orphaned row:
 *
 *   - Keep    → materialize each mirrored message into a self-contained local
 *               copy (while the account is still connected), then delete. Requires
 *               the account be connectable; if it is not, the delete is refused.
 *   - Remove  → permanent-delete the mirrored message rows, then delete. The mail
 *               stays safe on the source server.
 *
 * The same page serves the alias permanent-delete cascade (also_permadelete_alias_id):
 * the choice runs first, then the alias is permanently deleted.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_imap_delete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

	$session = SessionControl::get_instance();
	// Deleting IMAP feeds handles full-mailbox credentials — superadmin only.
	$session->check_permission(10);

	$accounts_url = '/plugins/mailbox/admin/admin_mailbox_accounts';

	$account_id = intval($input['iia_inbound_imap_account_id'] ?? 0);
	$cascade_alias_id = intval($input['also_permadelete_alias_id'] ?? 0);

	$account = $account_id > 0 ? new InboundImapAccount($account_id, TRUE) : null;
	if (!$account || !$account->key) {
		return _imap_del_redirect($session, 'That IMAP feed no longer exists.', $accounts_url);
	}

	$ref_ids = _imap_del_reference_backed_ids($account_id);
	$ref_count = count($ref_ids);

	// Nothing reference-backed → no choice needed; fall through to the plain delete
	// (and the alias cascade if requested).
	if ($ref_count === 0) {
		return _imap_del_finish_plain($session, $account, $cascade_alias_id, $accounts_url);
	}

	// --- confirm submitted ---
	if (!empty($input['_submitted'])) {
		$mode = ($input['delete_mode'] ?? 'keep') === 'remove' ? 'remove' : 'keep';

		if ($mode === 'keep') {
			return _imap_del_keep($session, $account, $ref_ids, $cascade_alias_id, $accounts_url);
		}
		return _imap_del_remove($session, $account, $ref_ids, $cascade_alias_id, $accounts_url);
	}

	// --- render the choice ---
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
	$connectable = false;
	try { $connectable = $account->isConnectable(); } catch (\Throwable $e) { $connectable = false; }

	return LogicResult::render(array(
		'session'          => $session,
		'account'          => $account,
		'ref_count'        => $ref_count,
		'connectable'      => $connectable,
		'cascade_alias_id' => $cascade_alias_id,
		'accounts_url'     => $accounts_url,
	));
}

/** Message ids for this account that are reference-backed (attachments live on IMAP). */
function _imap_del_reference_backed_ids(int $account_id): array {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare(
		"SELECT iem_inbound_email_message_id
		 FROM iem_inbound_email_messages
		 WHERE iem_iia_inbound_imap_account_id = ?
		 AND iem_raw_storage_driver = 'remote'"
	);
	$stmt->execute(array($account_id));
	return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
}

/** Keep: materialize every mirrored message, then delete the feed (and cascade). */
function _imap_del_keep($session, InboundImapAccount $account, array $ref_ids, int $cascade_alias_id, string $accounts_url): LogicResult {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));

	if (!$account->isConnectable()) {
		// Materialize needs a live connection to fetch the full messages. Refuse
		// rather than proceed and strand them.
		return _imap_del_redirect($session,
			'This account is not connected, so its mail cannot be copied local. Connect / authorize it '
			. 'first, or choose Remove to delete the mirrored messages (the mail stays on the source server).',
			$accounts_url);
	}

	$ingestor = new ImapIngestor($account);
	$router = new InboundEmailRouter();
	$done = 0; $failed = 0; $first_error = '';
	foreach ($ref_ids as $id) {
		$m = new InboundEmailMessage($id, TRUE);
		if (!$m->key) { continue; }
		$res = $router->materializeRemoteMessage($m, $ingestor);
		if (empty($res['ok'])) {
			$failed++;
			if ($first_error === '') { $first_error = (string)($res['message'] ?? 'unknown error'); }
		} else {
			$done++;
		}
	}
	$ingestor->close();

	if ($failed > 0) {
		// A partial materialize means some messages would be stranded — abort the
		// whole delete so nothing is lost, and report.
		return _imap_del_redirect($session,
			'Kept ' . $done . ' message(s), but ' . $failed . ' could not be copied local ('
			. $first_error . '). The feed was NOT deleted so no mail is stranded — retry, or choose Remove.',
			$accounts_url);
	}

	$account->soft_delete();
	$msg = 'Copied ' . $done . ' mirrored message(s) local and removed the IMAP feed. Their attachments still load.';
	$msg .= _imap_del_cascade_alias($cascade_alias_id);
	return _imap_del_redirect($session, $msg, $accounts_url);
}

/** Remove: permanent-delete the mirrored rows, then delete the feed (and cascade). */
function _imap_del_remove($session, InboundImapAccount $account, array $ref_ids, int $cascade_alias_id, string $accounts_url): LogicResult {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

	$removed = 0;
	foreach ($ref_ids as $id) {
		$m = new InboundEmailMessage($id, TRUE);
		if ($m->key) { $m->permanent_delete(); $removed++; }
	}
	$account->soft_delete();
	$msg = 'Removed ' . $removed . ' mirrored message(s) and the IMAP feed. The mail remains on the source server.';
	$msg .= _imap_del_cascade_alias($cascade_alias_id);
	return _imap_del_redirect($session, $msg, $accounts_url);
}

/** No reference-backed mail: just delete the feed (and cascade if asked). */
function _imap_del_finish_plain($session, InboundImapAccount $account, int $cascade_alias_id, string $accounts_url): LogicResult {
	$account->soft_delete();
	$msg = 'IMAP feed removed.' . _imap_del_cascade_alias($cascade_alias_id);
	return _imap_del_redirect($session, $msg, $accounts_url);
}

/** Permanently delete the owning alias when the delete originated as its cascade. */
function _imap_del_cascade_alias(int $cascade_alias_id): string {
	if ($cascade_alias_id <= 0) { return ''; }
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	$alias = new InboundEmailAlias($cascade_alias_id, TRUE);
	if ($alias->key) {
		$alias->permanent_delete();
		return ' The mailbox was also permanently deleted.';
	}
	return '';
}

function _imap_del_redirect($session, string $message, string $url): LogicResult {
	$session->save_message(new DisplayMessage(
		$message,
		'IMAP Feed',
		'~/plugins/mailbox/admin/~',
		DisplayMessage::MESSAGE_ANNOUNCEMENT,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	return LogicResult::redirect($url);
}
?>
