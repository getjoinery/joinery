<?php
/**
 * Admin Help Editor - Logic
 *
 * Edits the markdown files behind the admin help viewer and the public
 * /documentation page. Reads and writes through DocsScanner so the editor is
 * held to the same path rules as the viewer.
 *
 * Only the origin deployment offers this. The docs ship inside the release
 * archive, so an edit made on a site that consumes releases is overwritten by
 * its next upgrade with nothing to show for it.
 */

function admin_help_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/DocsScanner.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	$docs_dir = PathHelper::getIncludePath('docs');
	$view_url = '/admin/admin_help';

	// Named by domain rather than a flag, so a clone or a restored backup of
	// this site reads as not-the-origin and loses the editor. An unset
	// root_node reads the same way, which is why a node that has never heard
	// of the setting gets no editor rather than a broken one.
	if (!DeploymentHelper::isOriginNode()) {
		return LogicResult::redirect($view_url);
	}

	$selected_doc = trim((string)($input['doc'] ?? ''));
	if ($selected_doc === '') {
		return LogicResult::redirect($view_url);
	}

	$resolved = DocsScanner::resolve_path($selected_doc, $docs_dir);
	if ($resolved['error'] !== '') {
		$session->save_message(new DisplayMessage(
			$resolved['error'],
			'Error',
			NULL,
			DisplayMessage::MESSAGE_ERROR
		));
		return LogicResult::redirect($view_url);
	}
	$filepath = $resolved['path'];

	$content = (string)file_get_contents($filepath);
	$error = '';

	if (LibraryFunctions::isFormSubmission()) {
		$formwriter = new FormWriterV2HTML5('docs_edit');

		if (!$formwriter->validateCSRF($input)) {
			$error = 'Invalid or expired request token, so nothing was saved. Try again.';
		} else {
			// Whatever happens next, the editor redisplays what was typed
			// rather than the copy on disk -- a refused save must not throw
			// the author's work away.
			$content = (string)($input['doc_content'] ?? '');

			// Docs live in the code tree, which the web user cannot write
			// (specs/read_only_tree.md), so the save is queued for the root
			// actor. The starting hash travels with it: an edit that landed in
			// the checkout while the request waited is still not clobbered,
			// because save_doc() checks the hash at the moment it writes.
			//
			// The request names the document and nothing else. Root derives the
			// directory from the key, so a forged request cannot point the write
			// at a directory of its own choosing.
			$error = '';
			try {
				$request_id = RootRequest::submit('save_doc', array(
					'key'           => $selected_doc,
					'content'       => $content,
					'expected_hash' => (string)($input['content_hash'] ?? ''),
				), (int)$session->get_user_id());
			} catch (\Throwable $e) {
				$error = 'The save could not be queued: ' . $e->getMessage();
			}

			if ($error === '') {
				$queued_note = RootRequest::actorState() === 'present'
					? 'It is being written now; reload in a moment to see it.'
					: RootRequest::actorWarning();
				$session->save_message(new DisplayMessage(
					'Queued ' . basename($filepath) . ' for saving. ' . $queued_note
						. ' Commit it and publish an upgrade to reach the other sites.',
					'Queued',
					NULL,
					DisplayMessage::MESSAGE_ANNOUNCEMENT
				));
				return LogicResult::redirect($view_url . '?doc=' . $selected_doc . '&request=' . urlencode($request_id));
			}
		}
	}

	$basename = pathinfo($filepath, PATHINFO_FILENAME);

	$page_vars = array(
		'session'       => $session,
		'selected_doc'  => $selected_doc,
		'doc_title'     => DocsScanner::extract_title($filepath, $basename),
		'relative_path' => ltrim(str_replace(PathHelper::getBasePath(), '', $filepath), '/'),
		'content'       => $content,
		// Always the hash of what is on disk right now. After a refused save
		// this is the hash of the other author's version, so a deliberate
		// second Save goes through -- the warning is shown once, not forever.
		'content_hash'  => sha1((string)file_get_contents($filepath)),
		// Said at the top of the editor rather than only on a refused save: a
		// git checkout recreates a doc with the developer's umask, and the
		// web server loses write access to it until the mode is restored.
		'writable'      => is_writable($filepath),
		'error'         => $error,
		'view_url'      => $view_url,
	);

	return LogicResult::render($page_vars);
}
?>
