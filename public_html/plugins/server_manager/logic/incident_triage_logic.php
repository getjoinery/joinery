<?php
/**
 * server_manager/incident_triage — a person's answer to "what are we doing
 * about it" for one incident, and notes (incident_triage.md WP1).
 *
 * Input: id, and do (resolved, ignored, new, snooze_4, snooze_24,
 * snooze_72, snooze_168) and/or note. The same writes the incident pages make,
 * through IncidentTriage; nothing here changes whether the condition is still
 * there. Superadmin only (floor 10). Not callable by the AI agent: an analysis
 * recommends, and a person acts.
 *
 * @version 1.1.0 - looking is no longer a triage
 * @version 1.0.0
 */

function incident_triage_logic(array $input): LogicResult {
	if (!LibraryFunctions::isFormSubmission()) {
		return LogicResult::error('Incident triage is a POST.');
	}
	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Only a superadmin can triage incidents.');
	}
	$inc = IncidentTriage::load((int)($input['id'] ?? 0));
	if ($inc === null) {
		return LogicResult::error('There is no such incident.');
	}
	$do = trim((string)($input['do'] ?? ''));
	$note = (string)($input['note'] ?? '');
	if ($do === '' && trim($note) === '') {
		return LogicResult::error('Say what to set it to, or write a note.');
	}
	$uid = (int)$session->get_user_id();
	try {
		$changed = false;
		if ($do !== '') {
			$changed = IncidentTriage::apply(array((int)$inc->key), $do, $uid)['changed'] > 0;
			$inc->load();
		}
		if (trim($note) !== '') {
			IncidentTriage::note($inc, $note, $uid);
		}
	} catch (IncidentTriageException $e) {
		return LogicResult::error($e->getMessage());
	}
	return LogicResult::render(array(
		'id'      => (int)$inc->key,
		'triage'  => $inc->triage(),
		'active'  => $inc->is_open(),
		'changed' => $changed,
	));
}

function incident_triage_logic_descriptor(): array {
	return [
		'description' => 'Set an incident\'s triage (resolved, ignored, new to reopen it, or a snooze) and/or add a note.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => ['min_user_permission' => 10],
		'input'       => [
			'id'   => ['type' => 'int', 'required' => true, 'label' => 'Incident'],
			'do'   => ['type' => 'string', 'required' => false, 'enum' => array_keys(IncidentTriage::do_options()), 'label' => 'Set it to'],
			'note' => ['type' => 'string', 'required' => false, 'label' => 'Note'],
		],
	];
}
?>
