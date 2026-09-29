<?php
/**
 * API action: mailbox/draft_save — create or update a compose draft.
 *
 * POST /api/v1/action/mailbox/draft_save (session credential). Params: alias_id (From),
 * draft_id (optional; present on update), mode, source_id, to, cc, bcc, subject,
 * body_html / body, plus an optional multipart `attachments[]` (new files persist onto
 * the draft immediately). Same multipart handling as mailbox/send. Scope is enforced in
 * MailboxDrafts (the alias_id must be a grant the viewer holds). Returns {draft_id}, or
 * {locked:true} when a sealed draft with attachments needs an unlock window it lacks —
 * the client prompts a one-tap unlock, then resaves.
 *
 * An end-to-end (Fortress) mailbox's draft is sealed in the browser and saved in two
 * calls (`fortress`=1; specs/client_custody_mail.md § R6): the first, with no sealed_dek,
 * creates the row and answers {draft_id, id, sealed_ad_prefix}; the second posts
 * sealed_dek, public_key, `fields` (JSON: column => v1.edge. ciphertext), `parts` (JSON:
 * [{mime_part, size, inline}] for the sealed uploads, each file named by its part) and
 * `keep` (JSON: every part the draft still has). No plaintext reaches the server.
 *
 * @version 1.2 - the Fortress two-call save
 * @version 1.1.1
 */

function draft_save_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDrafts.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canCompose()) {
		return LogicResult::error('You do not have a mailbox to draft from.');
	}

	$params = array(
		'alias_id'  => $input['alias_id'] ?? 0,
		'draft_id'  => $input['draft_id'] ?? 0,
		'mode'      => $input['mode'] ?? 'new',
		'source_id' => $input['source_id'] ?? 0,
		'to'        => $input['to'] ?? '',
		'cc'        => $input['cc'] ?? '',
		'bcc'       => $input['bcc'] ?? '',
		'subject'   => $input['subject'] ?? '',
		'body_html' => $input['body_html'] ?? '',
		'body'      => $input['body'] ?? '',
		// Local-id => filename map for pasted inline images (Fix 7): a matched upload
		// persists as an inline part carrying its local id as Content-ID.
		'inline_manifest' => $input['inline_manifest'] ?? '',
	);

	$drafts = new MailboxDrafts($viewer);
	$files = MailboxSender::collectUploads();

	if (!empty($input['fortress'])) {
		$decode = function ($raw) {
			if (is_array($raw)) {
				return $raw;
			}
			$v = json_decode((string)$raw, true);
			return is_array($v) ? $v : array();
		};
		try {
			$result = $drafts->saveFortressDraft(array(
				'alias_id'   => $params['alias_id'],
				'draft_id'   => $params['draft_id'],
				'mode'       => $params['mode'],
				'source_id'  => $params['source_id'],
				'sealed_dek' => (string)($input['sealed_dek'] ?? ''),
				'public_key' => (string)($input['public_key'] ?? ''),
				'fields'     => $decode($input['fields'] ?? ''),
				'parts'      => $decode($input['parts'] ?? ''),
				'keep'       => $decode($input['keep'] ?? ''),
			), $files);
		} catch (MailboxDraftsException $e) {
			return LogicResult::error($e->getMessage());
		} catch (Throwable $e) {
			error_log('mailbox/draft_save (Fortress): ' . $e->getMessage());
			return LogicResult::error('The draft could not be saved.');
		}
		return LogicResult::render($result);
	}

	try {
		$result = $drafts->saveDraft($params, $files);
	} catch (MailboxDraftsException $e) {
		return LogicResult::error($e->getMessage());
	} catch (Throwable $e) {
		error_log('mailbox/draft_save: ' . $e->getMessage());
		return LogicResult::error('The draft could not be saved.');
	}

	if (!empty($result['locked'])) {
		return LogicResult::render(array('locked' => true,
			'message' => 'Unlock your vault to save this draft with attachments.'));
	}
	// Echo the authoritative persisted attachment set (Fix 3) so the client can drop
	// the files it just sent from its resend queue, plus the saved inline parts (Fix 7).
	return LogicResult::render(array(
		'draft_id'    => intval($result['draft_id']),
		'attachments' => $result['attachments'] ?? array(),
		'inline'      => $result['inline'] ?? array(),
	));
}

function draft_save_logic_descriptor() {
	return array(
		'requires_session' => true,
		'description' => 'Create or update a compose draft (multipart attachments supported)',
		'input' => [
			'alias_id' => ['type' => 'int', 'required' => false, 'label' => 'Mailbox alias ID'],
			'draft_id' => ['type' => 'int', 'required' => false, 'label' => 'Draft ID'],
			'mode' => ['type' => 'string', 'required' => false, 'label' => 'new, reply, reply_all or forward'],
			'source_id' => ['type' => 'int', 'required' => false, 'label' => 'Message being replied to or forwarded'],
			'to' => ['type' => 'string', 'required' => false, 'label' => 'To addresses'],
			'cc' => ['type' => 'string', 'required' => false, 'label' => 'Cc addresses'],
			'bcc' => ['type' => 'string', 'required' => false, 'label' => 'Bcc addresses'],
			'subject' => ['type' => 'string', 'required' => false, 'label' => 'Subject'],
			'body' => ['type' => 'text', 'required' => false, 'label' => 'Plain-text body'],
			'body_html' => ['type' => 'text', 'required' => false, 'label' => 'HTML body'],
			'inline_manifest' => ['type' => 'string', 'required' => false, 'label' => 'Inline image manifest (local-id => filename)'],
			'fortress' => ['type' => 'bool', 'required' => false, 'label' => 'An end-to-end mailbox\'s draft, sealed in the browser'],
			'sealed_dek' => ['type' => 'string', 'required' => false, 'max_length' => 4096, 'label' => 'The draft DEK sealed to the owner\'s mail key (v1.edgeseal.mail.)'],
			'public_key' => ['type' => 'string', 'required' => false, 'max_length' => 512, 'label' => 'The mail key the DEK is sealed to'],
			'fields' => ['type' => 'text', 'required' => false, 'label' => 'JSON: sealed column => v1.edge. ciphertext'],
			'parts' => ['type' => 'text', 'required' => false, 'max_length' => 20000, 'label' => 'JSON: [{mime_part, size, inline}] for the sealed uploads'],
			'keep' => ['type' => 'text', 'required' => false, 'max_length' => 20000, 'label' => 'JSON: every part the draft still has'],
		],
	);
}
?>
