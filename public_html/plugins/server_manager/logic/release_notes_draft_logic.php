<?php
/**
 * A short, plain-language draft of the next release's notes, for the
 * Auto-notes button on the publish form (ReleaseNotesDrafter). It returns the
 * draft to the page; nothing is saved.
 *
 * @version 1.2.0 - the action's level (8) is who may draft
 * @version 1.1.0 - through: a republishing site drafts through the newer version Apply and publish brings in
 * @version 1.0.0
 */

function release_notes_draft_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	// Who may draft is the action's level (8); the publish itself is 8 too.
	try {
		$draft = ReleaseNotesDrafter::draft(null, null, trim((string)($input['through'] ?? '')));
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
		'auth'             => array('min_user_permission' => 8),
		'input'            => array(
			'through' => array('type' => 'string', 'required' => false, 'label' => 'Through version (a newer upstream version the release applies first)'),
		),
	);
}
?>
