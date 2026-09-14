<?php
/**
 * IncidentRecord - one case a node opened, as the plane stores it.
 *
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
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class IncidentRecordException extends SystemBaseException {}

class IncidentRecord extends SystemBase {
	public static $prefix = 'inc';
	public static $tablename = 'inc_incident_records';
	public static $pkey_column = 'inc_id';

	public static $json_vars = array('inc_body');

	/** The two states a case has. Set only from what the node reports. */
	const STATUS_OPEN   = 'open';
	const STATUS_CLOSED = 'closed';

	public static $field_specifications = array(
		'inc_id'             => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'inc_mgn_node_id'    => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false),
		// Who opened it: recipe:<name>, or the unexplained-root classifier's
		// own source once it is built (specs/node_unexplained_root.md). A
		// string, not a recipe name, so that case lands in the same store.
		'inc_source'         => array('type'=>'varchar(64)', 'required'=>true, 'is_nullable'=>false),
		'inc_recipe'         => array('type'=>'varchar(40)'),
		// The node-minted id: the escalation's id in the node's own ledger.
		// Unique with the node and the source; a known id is appended to.
		'inc_node_case_id'   => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false,
		                              'unique_with'=>array('inc_mgn_node_id', 'inc_source')),
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
		// What a human here wrote about it, and when they marked it read.
		'inc_human_note'     => array('type'=>'text'),
		'inc_read_time'      => array('type'=>'timestamp(6)'),
		'inc_read_by'        => array('type'=>'int8'),
		'inc_first_seen_time' => array('type'=>'timestamp(6)'),
		'inc_last_seen_time'  => array('type'=>'timestamp(6)'),
		'inc_create_time'    => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'inc_update_time'    => array('type'=>'timestamp(6)'),
		'inc_delete_time'    => array('type'=>'timestamp(6)'),
	);

	protected static $foreign_key_actions = [
		'inc_mgn_node_id' => ['action' => 'cascade'],
		'inc_read_by'     => ['action' => 'null', 'source_table' => 'usr_users'],
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

	/** The open case for one node and source, or null. */
	public static function open_for(int $node_id, string $source): ?IncidentRecord {
		$rows = new MultiIncidentRecord(
			array('node_id' => $node_id, 'source' => $source, 'status' => self::STATUS_OPEN, 'deleted' => false),
			array('inc_id' => 'DESC'), 1);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** The case for one node, source and node-minted id, or null. */
	public static function find(int $node_id, string $source, int $node_case_id): ?IncidentRecord {
		$rows = new MultiIncidentRecord(
			array('node_id' => $node_id, 'source' => $source, 'node_case_id' => $node_case_id, 'deleted' => false),
			array('inc_id' => 'DESC'), 1);
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
			$filters['inc_mgn_node_id'] = [$this->options['node_id'], PDO::PARAM_INT];
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
		// Open cases nobody has marked read: what the fleet notice counts.
		if (!empty($this->options['unread'])) {
			$filters['inc_read_time'] = 'IS NULL';
		}

		return $this->_get_resultsv2('inc_incident_records', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
