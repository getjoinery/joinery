<?php
/**
 * copy_key_logic - the /copy-key page: a copy made from backups asks its owner
 * to open its backup's key with their recovery key (specs/site_copy.md WP10).
 *
 * Reached on a dormant copy through its look link, before the site has any
 * accounts of its own the owner knows: the quiet state lets only the look
 * cookie's holder through, and the page does nothing unless this machine's
 * agent has staged a request (CopyKeyHandoff). What is posted is the value
 * the browser worked out, never the recovery key.
 *
 * Not an API action: the page has no session to act for, and an answer is
 * useful only to this machine's own agent, which judges it.
 *
 * @version 1.1 - the decline is checked against its own form's token; it always read as expired
 * @version 1.0
 */

function copy_key_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	$page_vars = array('message' => '', 'error' => '');

	// A POST only: a page view never writes.
	if (!empty($_POST) && !empty($input['copy_key_action'])) {
		// Each form's token is held under its own name: the decline form's
		// under copy_key_decline, the answer's under copy_key_form.
		$formwriter = new FormWriterV2HTML5($input['copy_key_action'] === 'decline' ? 'copy_key_decline' : 'copy_key_form');
		if (!$formwriter->validateCSRF($input)) {
			return LogicResult::error('The form expired. Reload the page and try again.');
		}
		try {
			if ($input['copy_key_action'] === 'decline') {
				CopyKeyHandoff::decline($input['job_id'] ?? 0);
				$page_vars['message'] = 'Declined. The copy takes no key, and its run stops.';
			} else {
				CopyKeyHandoff::answer($input['job_id'] ?? 0, $input['shared'] ?? '', $input['public_key'] ?? '');
				$page_vars['message'] = 'Sent to this machine\'s agent. It checks the key and opens the backup; this page shows the '
					. 'outcome in a few seconds.';
			}
		} catch (CopyKeyHandoffException $e) {
			$page_vars['error'] = $e->getMessage();
		}
	}

	$page_vars['pending'] = CopyKeyHandoff::pending();
	return LogicResult::render($page_vars);
}
