<?php
/**
 * IncidentRecord - one incident: a condition on one node that needs a person,
 * from the moment it starts until it is over (incident_triage.md).
 *
 * Two questions are kept apart on every row. Whether the condition is still
 * there (inc_status: open is active, closed is cleared) belongs to its source,
 * and nothing a person does here changes it. What is being done about it
 * (inc_triage) belongs to a person: new, snoozed until a time, resolved or
 * ignored. Resolved while the condition is still there is a fix awaiting its
 * proof (awaiting_proof()); the reconciler puts it back to new when the proof
 * does not come. What happened, in order, is in IncidentEvent.
 *
 * The first source is the agent's case:
 * The case store of specs/agent_tier1_recipes.md (settled Q3: the case IS
 * Sentinel's incident record, §14.B, built here). A node's agent originates a
 * case when a recipe gives up — its escalation, given a body and a delivery —
 * and the poll claim carries it. The plane stores a new one, appends to a
 * known id, records the close the node reports, and holds at most one open
 * case per source per node. A human here writes a note and marks it read; a
 * human here never closes it, because the node's own check is the truth about
 * the fault and the plane stays at "see and ask".
 *
 * Node-scoped. The owner column for tenancy arrives with the Sentinel plugin's
 * tenancy work as a data-class column, not here.
 *
 * WHO IS HOSTILE: the node. Every column that came off the wire was capped and
 * re-validated on intake (AgentChannelEndpoint::normalised_case) and is
 * escaped again wherever it is shown. Nothing stored here is ever a shell
 * argument, a template, a link or a mail subject.
 *
 * @version 1.3 - Looking is gone (one person triages; it read the same as New); awaiting_proof(), and
 *                the waiting view beside resolved, so each incident is in exactly one view
 * @version 1.2 - deleting a node permanently deletes its incidents through the model, so each one's
 *                timeline goes with it (a cascade is one level; site_copy.md B41)
 * @version 1.1 - an incident: title, severity, triage (new, looking, snoozed, resolved, ignored) and a
 *                source's detail; the human note and read stamp moved into triage and IncidentEvent
 *                (incident_triage.md WP1)
 * @version 1.0
 */

class IncidentRecordException extends SystemBaseException {}

class IncidentRecord extends SystemBase {
	public static $prefix = 'inc';
	public static $tablename = 'inc_incident_records';
	public static $pkey_column = 'inc_incident_record_id';

	public static $json_vars = array('inc_body', 'inc_detail');

	/** Whether the condition is still there. Set only by the source. */
	const STATUS_OPEN   = 'open';
	const STATUS_CLOSED = 'closed';

	/** What a person is doing about it. Set only by a person. */
	const TRIAGE_NEW      = 'new';
	const TRIAGE_SNOOZED  = 'snoozed';
	const TRIAGE_RESOLVED = 'resolved';
	const TRIAGE_IGNORED  = 'ignored';
	const TRIAGE_STATES = array(self::TRIAGE_NEW, self::TRIAGE_SNOOZED, self::TRIAGE_RESOLVED, self::TRIAGE_IGNORED);

	/** Critical: visitors or data are at risk now. Warning: everything else. */
	const SEVERITY_CRITICAL = 'critical';
	const SEVERITY_WARNING  = 'warning';

	/**
	 * What needs a person, as SQL over inc_incident_records: new, or snoozed
	 * whose time has come. One definition, read by the header line,
	 * the menu count, the node page and the list's default view.
	 */
	const NEEDS_YOU_SQL = "(inc_triage = 'new' OR (inc_triage = 'snoozed' AND inc_snooze_until <= now() AT TIME ZONE 'UTC'))";

	public static $field_specifications = array(
		'inc_incident_record_id'             => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'inc_mgn_managed_node_id'    => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false),
		// Who opened it: recipe:<name>, or the unexplained-root classifier's
		// own source once it is built (specs/node_unexplained_root.md). A
		// string, not a recipe name, so that case lands in the same store.
		'inc_source'         => array('type'=>'varchar(64)', 'required'=>true, 'is_nullable'=>false),
		'inc_recipe'         => array('type'=>'varchar(40)'),
		// The node-minted id: the escalation's id in the node's own ledger.
		// Unique with the node and the source; a known id is appended to.
		'inc_node_case_id'   => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false,
		                              'unique_with'=>array('inc_mgn_managed_node_id', 'inc_source')),
		'inc_status'         => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'open'),
		'inc_opened_time'    => array('type'=>'timestamp(6)'),
		'inc_reason'         => array('type'=>'text'),
		// Failing ticks the node appended while the case was open, as the node
		// counts them, and the newest one.
		'inc_note_count'     => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0'),
		'inc_last_note'      => array('type'=>'text'),
		'inc_last_note_time' => array('type'=>'timestamp(6)'),
		'inc_closed_time'    => array('type'=>'timestamp(6)'),
		'inc_close_reason'   => array('type'=>'text'),
		// The body: mode, the attempts that spent the budget, a host report,
		// the vocabulary and recipe list, as the node sent it and the intake
		// capped it. Null when the node has reported only the summary.
		'inc_body'           => array('type'=>'jsonb'),
		// The plain-language line, composed on this side by the source (an
		// agent case's from its recipe; IncidentTitles), never node text.
		'inc_title'          => array('type'=>'varchar(200)'),
		'inc_severity'       => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'warning'),
		// Triage: a person's answer to "what are we doing about it".
		'inc_triage'         => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'new'),
		'inc_triage_time'    => array('type'=>'timestamp(6)'),
		'inc_triage_usr_user_id' => array('type'=>'int8'),
		'inc_snooze_until'   => array('type'=>'timestamp(6)'),
		// What a plane source saw. An agent case keeps its body in inc_body.
		'inc_detail'         => array('type'=>'jsonb'),
		'inc_first_seen_time' => array('type'=>'timestamp(6)'),
		'inc_last_seen_time'  => array('type'=>'timestamp(6)'),
		'inc_create_time'    => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'inc_update_time'    => array('type'=>'timestamp(6)'),
		'inc_delete_time'    => array('type'=>'timestamp(6)'),
	);

	protected static $foreign_key_actions = [
		// Not 'cascade': a cascade is one flat delete, and an incident's own
		// timeline (ine_incident_events) would be left behind.
		'inc_mgn_managed_node_id' => ['action' => 'permanent_delete'],
		'inc_triage_usr_user_id' => ['action' => 'null', 'source_table' => 'usr_users'],
	];

	/** The stored body as an array, or null. */
	public function body(): ?array {
		$body = $this->get('inc_body');
		if (is_string($body)) {
			$body = json_decode($body, true);
		}
		return is_array($body) ? $body : null;
	}

	public function is_open(): bool {
		return (string)$this->get('inc_status') === self::STATUS_OPEN;
	}

	/**
	 * The triage as a person should read it now: a snooze whose time has come
	 * is new again. Derived on read, so a page view never writes; the next
	 * triage a person sets replaces it.
	 */
	public function triage(): string {
		$t = (string)$this->get('inc_triage');
		if ($t === self::TRIAGE_SNOOZED) {
			$until = trim((string)$this->get('inc_snooze_until'));
			if ($until === '' || strtotime($until . ' UTC') <= time()) {
				return self::TRIAGE_NEW;
			}
		}
		return in_array($t, self::TRIAGE_STATES, true) ? $t : self::TRIAGE_NEW;
	}

	/** New: a person still owes it something. */
	public function needs_you(): bool {
		return $this->triage() === self::TRIAGE_NEW;
	}

	/**
	 * Resolved while the condition is still there: someone says it is fixed,
	 * and the source has not yet seen it clear. Off the list while it waits;
	 * IncidentReconciler::return_unproven() puts it back if the proof never comes.
	 */
	public function awaiting_proof(): bool {
		return $this->triage() === self::TRIAGE_RESOLVED && $this->is_open();
	}

	public function is_critical(): bool {
		return (string)$this->get('inc_severity') === self::SEVERITY_CRITICAL;
	}

	/** The title to show: the stored one, or one composed from the source. */
	public function title(): string {
		$t = trim((string)$this->get('inc_title'));
		return $t !== '' ? $t : IncidentTitles::for_source((string)$this->get('inc_source'));
	}

	/**
	 * How many incidents need a person, and how many of those are still
	 * active and critical, in one query: ['needs_you', 'active', 'critical'].
	 * Optionally for one node.
	 */
	public static function needs_you_counts(?int $node_id = null): array {
		$sql = "SELECT count(*) AS needs_you,
		               count(*) FILTER (WHERE inc_status = 'open') AS active,
		               count(*) FILTER (WHERE inc_status = 'open' AND inc_severity = 'critical') AS critical
		          FROM inc_incident_records
		         WHERE inc_delete_time IS NULL AND " . self::NEEDS_YOU_SQL
			. ($node_id !== null ? ' AND inc_mgn_managed_node_id = :node' : '');
		$q = DbConnector::get_instance()->get_db_link()->prepare($sql);
		if ($node_id !== null) {
			$q->bindValue(':node', $node_id, PDO::PARAM_INT);
		}
		$q->execute();
		$row = $q->fetch(PDO::FETCH_ASSOC) ?: array();
		return array(
			'needs_you' => (int)($row['needs_you'] ?? 0),
			'active'    => (int)($row['active'] ?? 0),
			'critical'  => (int)($row['critical'] ?? 0),
		);
	}

	/** The open case for one node and source, or null. */
	public static function open_for(int $node_id, string $source): ?IncidentRecord {
		$rows = new MultiIncidentRecord(
			array('node_id' => $node_id, 'source' => $source, 'status' => self::STATUS_OPEN, 'deleted' => false),
			array('inc_incident_record_id' => 'DESC'), 1);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** The case for one node, source and node-minted id, or null. */
	public static function find(int $node_id, string $source, int $node_case_id): ?IncidentRecord {
		$rows = new MultiIncidentRecord(
			array('node_id' => $node_id, 'source' => $source, 'node_case_id' => $node_case_id, 'deleted' => false),
			array('inc_incident_record_id' => 'DESC'), 1);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** How many open cases a node has. */
	public static function open_count(int $node_id): int {
		return count(new MultiIncidentRecord(
			array('node_id' => $node_id, 'status' => self::STATUS_OPEN, 'deleted' => false)));
	}

	public function save($debug = false) {
		$this->set('inc_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}
}

class MultiIncidentRecord extends SystemMultiBase {
	protected static $model_class = 'IncidentRecord';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['node_id'])) {
			$filters['inc_mgn_managed_node_id'] = [$this->options['node_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['source'])) {
			$filters['inc_source'] = [$this->options['source'], PDO::PARAM_STR];
		}
		if (isset($this->options['node_case_id'])) {
			$filters['inc_node_case_id'] = [$this->options['node_case_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['status'])) {
			$filters['inc_status'] = [$this->options['status'], PDO::PARAM_STR];
		}
		if (isset($this->options['severity'])) {
			$filters['inc_severity'] = [$this->options['severity'], PDO::PARAM_STR];
		}
		// A triage view. 'needs_you' is new or a snooze whose time has come;
		// 'new' includes those ended snoozes, and 'snoozed' only the ones still
		// running. 'waiting' is resolved and still happening, 'resolved' is
		// resolved and cleared. So every incident is in exactly one of new,
		// snoozed, waiting, resolved and ignored.
		if (isset($this->options['view'])) {
			switch ($this->options['view']) {
				case 'needs_you':
					$filters['(inc_triage'] = "= 'new' OR (inc_triage = 'snoozed' AND inc_snooze_until <= now() AT TIME ZONE 'UTC'))";
					break;
				case 'new':
					$filters['(inc_triage'] = "= 'new' OR (inc_triage = 'snoozed' AND inc_snooze_until <= now() AT TIME ZONE 'UTC'))";
					break;
				case 'snoozed':
					$filters['(inc_triage'] = "= 'snoozed' AND inc_snooze_until > now() AT TIME ZONE 'UTC')";
					break;
				case 'waiting':
					$filters['(inc_triage'] = "= 'resolved' AND inc_status = 'open')";
					break;
				case 'resolved':
					$filters['(inc_triage'] = "= 'resolved' AND inc_status = 'closed')";
					break;
				case 'ignored':
					$filters['inc_triage'] = [$this->options['view'], PDO::PARAM_STR];
					break;
			}
		}

		return $this->_get_resultsv2('inc_incident_records', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
