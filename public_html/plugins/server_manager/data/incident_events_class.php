<?php
/**
 * IncidentEvent - one thing that happened to an incident, for its timeline
 * (incident_triage.md, Data).
 *
 * An event records a change, never a tick: the incident opened, cleared or
 * reopened (written by its source), a person changed its triage or wrote a
 * note, or an analysis came back. An agent case's repeated failing ticks stay
 * folded on the incident (inc_note_count, inc_last_note), so a node failing
 * every ten minutes for a week adds nothing here.
 *
 * WHO IS HOSTILE: a node, for the text of an event its case carried (a close
 * reason). Every value is stored as text and escaped where it is shown.
 *
 * @version 1.0
 */

class IncidentEventException extends SystemBaseException {}

class IncidentEvent extends SystemBase {
	public static $prefix = 'ine';
	public static $tablename = 'ine_incident_events';
	public static $pkey_column = 'ine_incident_event_id';

	public static $json_vars = array('ine_data');

	const KIND_OPENED   = 'opened';
	const KIND_CLEARED  = 'cleared';
	const KIND_REOPENED = 'reopened';
	const KIND_TRIAGE   = 'triage';
	const KIND_NOTE     = 'note';
	const KIND_ANALYSIS = 'analysis';
	const KINDS = array(self::KIND_OPENED, self::KIND_CLEARED, self::KIND_REOPENED,
		self::KIND_TRIAGE, self::KIND_NOTE, self::KIND_ANALYSIS);

	public static $field_specifications = array(
		'ine_incident_event_id'      => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ine_inc_incident_record_id' => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false),
		// When it happened: the source's own time for opened and cleared, the
		// moment of the click for a person's.
		'ine_time'                   => array('type'=>'timestamp(6)', 'required'=>true, 'is_nullable'=>false),
		'ine_kind'                   => array('type'=>'varchar(16)', 'required'=>true, 'is_nullable'=>false),
		// The person who acted; empty when a source did.
		'ine_usr_user_id'            => array('type'=>'int8'),
		'ine_text'                   => array('type'=>'text'),
		// Kind-specific: a triage change's from and to, a snooze's end.
		'ine_data'                   => array('type'=>'jsonb'),
		'ine_create_time'            => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $index_specifications = array(
		array('columns' => array('ine_inc_incident_record_id')),
	);

	protected static $foreign_key_actions = [
		'ine_inc_incident_record_id' => ['action' => 'cascade'],
		'ine_usr_user_id'            => ['action' => 'null', 'source_table' => 'usr_users'],
	];

	/** The stored data as an array (empty when none). */
	public function data(): array {
		$d = $this->get('ine_data');
		if (is_string($d)) {
			$d = json_decode($d, true);
		}
		return is_array($d) ? $d : array();
	}

	/**
	 * Record one event. The kind must be one of KINDS; anything else is a
	 * programming error, not a value to store.
	 */
	public static function record(int $incident_id, string $kind, string $time, ?int $user_id = null,
		string $text = '', array $data = array()): IncidentEvent {
		if (!in_array($kind, self::KINDS, true)) {
			throw new IncidentEventException('Unknown incident event kind: ' . $kind);
		}
		$e = new IncidentEvent(NULL);
		$e->set('ine_inc_incident_record_id', $incident_id);
		$e->set('ine_time', $time);
		$e->set('ine_kind', $kind);
		$e->set('ine_usr_user_id', $user_id);
		$e->set('ine_text', $text !== '' ? $text : null);
		$e->set('ine_data', $data ? $data : null);
		$e->save();
		return $e;
	}

	/** One incident's events, oldest first. */
	public static function for_incident(int $incident_id): array {
		$out = array();
		foreach (new MultiIncidentEvent(array('incident_id' => $incident_id),
			array('ine_time' => 'ASC', 'ine_incident_event_id' => 'ASC')) as $e) {
			$out[] = $e;
		}
		return $out;
	}
}

class MultiIncidentEvent extends SystemMultiBase {
	protected static $model_class = 'IncidentEvent';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['incident_id'])) {
			$filters['ine_inc_incident_record_id'] = [$this->options['incident_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['kind'])) {
			$filters['ine_kind'] = [$this->options['kind'], PDO::PARAM_STR];
		}
		return $this->_get_resultsv2('ine_incident_events', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
