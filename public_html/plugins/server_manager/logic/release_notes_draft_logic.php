<?php
/**
 * A short, plain-language draft of the next release's notes, for the
 * Auto-notes button on the publish form (ReleaseNotesDrafter). It returns the
 * draft to the page; nothing is saved.
 *
 * @version 1.0.0
 */

function release_notes_draft_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	if ($session->get_permission() < 10) {
		return LogicResult::error('Only a superadmin can draft release notes.');
	}
	try {
		$draft = ReleaseNotesDrafter::draft();
	} catch (ReleaseNotesDrafterException $e) {
		return LogicResult::render(array('ok' => false, 'message' => $e->getMessage()));
	} catch (Throwable $e) {
		error_log('release_notes_draft_logic: ' . get_class($e) . ': ' . $e->getMessage());
		return LogicResult::render(array('ok' => false, 'message' => 'The model could not write a draft: ' . $e->getMessage()));
	}
	return LogicResult::render(array(
		'ok'     => true,
		'notes'  => $draft['notes'],
		'source' => $draft['source'],
		'model'  => $draft['model'],
	));
}

function release_notes_draft_logic_descriptor(): array {
	return array(
		'description'      => 'Draft short, plain-language release notes from the commits (or, on a republishing site, the upstream release notes) since the last published release.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('min_user_permission' => 10),
		'input'            => array(),
	);
}
?>
