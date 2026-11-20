<?php
/**
 * The sample's handlers (docs/sandboxed_plugins.md, "Writing one").
 * Each runs in the fence; its models read and write through core.
 */

/** A member signed up: a note saying so. */
function on_signal(array $input) {
	$note = new Note();
	$note->set('text', 'Someone new signed up.');
	$note->save();
	sandbox_log('signal ' . $input['signal'] . ' noted');
	return array('note' => $note->key);
}

/**
 * Daily: unpin notes, twenty at a time. Each save is an ask to core, and a
 * call has its time limit and 200 asks, so a run takes a bounded page and the
 * next run takes the rest.
 */
function on_schedule(array $input) {
	$unpinned = 0;
	foreach (new MultiNote(array('pinned' => true, 'deleted' => false), array('id' => 'ASC'), 20) as $note) {
		$note->set('pinned', false);
		$note->save();
		$unpinned++;
	}
	return array('unpinned' => $unpinned);
}

/**
 * The framed board's one handler: pin a note. The board shows every note to
 * every member, on purpose; pinning is the note's author's alone. A handler
 * checks who is asking (input.user) against the row it acts on: core checks
 * only what the plugin may reach, not what each member may do with it.
 */
function on_ui(array $input) {
	if (($input['source'] ?? '') !== 'frame' || ($input['handler'] ?? '') !== 'pin') {
		return array('status' => 'error', 'message' => 'Unknown call.');
	}
	$note = new Note((int)($input['values']['id'] ?? 0), TRUE);
	if (!$note->key) {
		return array('status' => 'error', 'message' => 'No such note.');
	}
	if ((int)$note->get('author') !== (int)($input['user']['id'] ?? 0)) {
		return array('status' => 'error', 'message' => 'Only its author may pin a note.');
	}
	$note->set('pinned', true);
	$note->save();
	return array('pinned' => $note->key);
}
