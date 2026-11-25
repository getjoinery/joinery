<?php
/**
 * The sample's API action, written as a first-party plugin writes one:
 *
 *   POST /api/v1/action/notes_sample/add   {"text": "Bring snacks"}
 *
 * The site reads the descriptor without running it (a single return of a
 * plain array) and checks the caller and the input against it before this
 * file runs, in the fence, as the caller.
 */

function add_logic_descriptor(): array {
	return array(
		'description' => 'Add a note, written by the caller.',
		'mutates'     => true,
		'ai_agent'    => 'confirm',
		'auth'        => array('capability' => 'write', 'min_user_permission' => 5),
		'input'       => array(
			'text' => array('type' => 'string', 'required' => true, 'max_length' => 200),
		),
	);
}

function add_logic($input) {
	$note = new Note();
	$note->set('nte_text', $input['text']);
	$note->set('nte_usr_user_id', SessionControl::get_instance()->get_user_id());
	try {
		$note->save();
	} catch (RuntimeException $e) {
		// Its own rule (Note::prepare()), said back to the caller.
		$result = LogicResult::error($e->getMessage());
		$result->addValidationError('text', $e->getMessage());
		return $result;
	}
	return LogicResult::render(array('note_id' => (int)$note->key));
}
