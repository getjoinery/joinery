<?php
/**
 * ManagedHost - A server that hosts one or more auto-provisioned Joinery sites.
 *
 * @version 1.2 - link_host_node(): agent-join approval names a machine-posture node as its
 *                host's own agent (mgh_mgn_host_node_id), so host-scope routing has a target
 * @version 1.1 - ensure_for_node(): the placement record is minted (or linked) the moment a
 *                container node needs one, so mgn_mgh_host_id is the only sibling identity
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ManagedHostException extends SystemBaseException {}

class ManagedHost extends SystemBase {
	public static $prefix = 'mgh';
	public static $tablename = 'mgh_managed_hosts';
	public static $pkey_column = 'mgh_id';

	protected static $foreign_key_actions = [
		'mgh_mgn_host_node_id' => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'mgh_id'                   => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mgh_slug'                 => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
		'mgh_name'                 => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
		'mgh_host'                 => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'mgh_ssh_user'             => array('type'=>'varchar(50)', 'is_nullable'=>false, 'default'=>'root'),
		'mgh_ssh_key_path'         => array('type'=>'varchar(500)'),
		'mgh_ssh_port'             => array('type'=>'int4', 'default'=>'22'),
		'mgh_max_sites'            => array('type'=>'int4', 'default'=>'50'),
		'mgh_provisioning_enabled' => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// The host's own agent identity: the paired ManagedNode that host-scope
		// primitives (decommission_site, later certs and container install) are
		// addressed to. The host IS a node — a plain machine-posture one — so
		// the job table needs no second subject; this link is only the routing
		// step from a container victim's placement record to that node.
		'mgh_mgn_host_node_id'     => array('type'=>'int8'),
		'mgh_notes'                => array('type'=>'text'),
		'mgh_create_time'          => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mgh_update_time'          => array('type'=>'timestamp(6)'),
		'mgh_delete_time'          => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$slug = strtolower(trim($this->get('mgh_slug')));
		$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
		$slug = preg_replace('/-+/', '-', $slug);
		$slug = trim($slug, '-');
		$this->set('mgh_slug', $slug);

		if (empty($slug)) {
			throw new ManagedHostException('Host slug is required.');
		}
		if (empty($this->get('mgh_name'))) {
			throw new ManagedHostException('Host name is required.');
		}
		if (empty($this->get('mgh_host'))) {
			throw new ManagedHostException('Host IP/hostname is required.');
		}

		$existing = new MultiManagedHost(array('slug' => $slug, 'deleted' => false));
		$existing->load();
		foreach ($existing as $ex) {
			if ($ex->key != $this->key) {
				throw new ManagedHostException('A host with this slug already exists.');
			}
		}

		$this->set('mgh_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * The host's own paired node record, or null if none is linked or the
	 * linked record is deleted.
	 */
	public function host_node() {
		$node_id = (int)$this->get('mgh_mgn_host_node_id');
		if (!$node_id) return null;
		$node = new ManagedNode($node_id, TRUE);
		if (!$node->key || $node->get('mgn_delete_time')) return null;
		return $node;
	}

	/**
	 * Count active (non-deleted) nodes assigned to this host.
	 */
	public function count_sites() {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT COUNT(*) FROM mgn_managed_nodes WHERE mgn_mgh_host_id = ? AND mgn_delete_time IS NULL");
		$q->execute([$this->key]);
		return (int) $q->fetchColumn();
	}

	/**
	 * Link a node to its placement record, creating the record if none exists.
	 *
	 * A container node names its host by mgn_mgh_host_id and nothing else, so
	 * any path that is about to treat a node as a container (allocating it a
	 * port, addressing its host) calls this first. Matching is by the host
	 * address string once, here, at write time — never again at read time.
	 */
	public static function ensure_for_node($node) {
		$addr = trim((string)$node->get('mgn_host'));
		if ($addr === '') {
			throw new ManagedHostException('Cannot assign a host record: the node has no host address.');
		}

		// Oldest matching row wins, deterministically. Duplicate rows for one
		// address exist (the backfill grouped by SSH tuple, and a concurrent
		// create can race — there is no unique constraint on mgh_host), and
		// the port allocator unions siblings across same-address rows, so a
		// duplicate splits nothing that matters; picking by lowest id just
		// keeps every caller converging on the same record.
		$existing = new MultiManagedHost(['deleted' => false], ['mgh_id' => 'ASC']);
		$existing->load();
		$host = null;
		foreach ($existing as $ex) {
			if ($ex->get('mgh_host') === $addr) {
				$host = $ex;
				break;
			}
		}

		if (!$host) {
			// mgh_slug is DB-unique across deleted rows too, so probe with a suffix
			// loop the way the backfill migration did. The column is varchar(50);
			// the base is truncated to leave room for the loop's suffix, so a long
			// hostname mints a row instead of a database error.
			$db = DbConnector::get_instance()->get_db_link();
			$base_slug = substr('host-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($addr)), 0, 42);
			$base_slug = rtrim($base_slug, '-');
			$slug = $base_slug;
			$i = 1;
			$sq = $db->prepare("SELECT COUNT(*) FROM mgh_managed_hosts WHERE mgh_slug = ?");
			while (true) {
				$sq->execute([$slug]);
				if ((int)$sq->fetchColumn() === 0) break;
				$slug = $base_slug . '-' . $i++;
			}
			$host = new ManagedHost(NULL);
			$host->set('mgh_slug', $slug);
			$host->set('mgh_name', substr($addr, 0, 100));
			$host->set('mgh_host', $addr);
			$host->set('mgh_ssh_user', $node->get('mgn_ssh_user') ?: 'root');
			$host->set('mgh_ssh_key_path', $node->get('mgn_ssh_key_path') ?: null);
			$host->set('mgh_ssh_port', (int)($node->get('mgn_ssh_port') ?: 22));
			$host->set('mgh_provisioning_enabled', false);
			$host->prepare();
			$host->save();
			$host->load();
		}

		if ((int)$node->get('mgn_mgh_host_id') !== (int)$host->key) {
			$node->set('mgn_mgh_host_id', $host->key);
			if ($node->key) {
				$node->save();
			}
		}
		return $host;
	}

	/**
	 * At agent-join approval, name the joining machine as a host's own agent.
	 *
	 * A host-scope primitive (decommission_site today; certificates and
	 * container install through the same door) is routed from a container
	 * victim's placement record to the host's paired node via
	 * mgh_mgn_host_node_id. A host agent that pairs but is never named there is
	 * routed to by nothing, so this closes the gap the moment the human
	 * approves — no separate manual step to forget.
	 *
	 * Conservative by design: it links only a machine-posture node (no
	 * container name, no web root — a container node is a site on a host, not a
	 * host) to a ManagedHost that ALREADY EXISTS for its address and has no
	 * host node yet. A container provisioned on this host already minted that
	 * record (ensure_for_node); an arbitrary bare node with no host record mints
	 * nothing here. Returns the linked host, or null when nothing matched.
	 */
	public static function link_host_node($node) {
		// A host is a machine, not a site on one. A node carrying a container
		// name or a web root is the site; it is never its own host.
		if (trim((string)$node->get('mgn_container_name')) !== ''
				|| trim((string)$node->get('mgn_web_root')) !== '') {
			return null;
		}
		$addr = trim((string)$node->get('mgn_host'));
		if ($addr === '') {
			return null;
		}

		$candidates = new MultiManagedHost(['host' => $addr, 'deleted' => false], ['mgh_id' => 'ASC']);
		foreach ($candidates as $host) {
			// Already linked to a live node? Leave it — first host node wins,
			// and re-pointing it on a second join is exactly the takeover the
			// approval ceremony exists to prevent.
			if ($host->host_node()) {
				return null;
			}
			$host->set('mgh_mgn_host_node_id', (int)$node->key);
			$host->save();
			return $host;
		}
		return null;
	}

	/**
	 * Select the least-loaded provisioning-enabled host with available capacity.
	 * Returns a ManagedHost or null if no capacity is available.
	 */
	public static function pick_for_provisioning() {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->query(
			"SELECT mgh.mgh_id " .
			"FROM mgh_managed_hosts mgh " .
			"LEFT JOIN mgn_managed_nodes mgn ON mgn.mgn_mgh_host_id = mgh.mgh_id AND mgn.mgn_delete_time IS NULL " .
			"WHERE mgh.mgh_provisioning_enabled = true AND mgh.mgh_delete_time IS NULL " .
			"GROUP BY mgh.mgh_id " .
			"HAVING COUNT(mgn.mgn_id) < mgh.mgh_max_sites " .
			"ORDER BY COUNT(mgn.mgn_id) ASC, mgh.mgh_id ASC " .
			"LIMIT 1"
		);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if (!$row) return null;
		return new ManagedHost($row['mgh_id'], true);
	}
}

class MultiManagedHost extends SystemMultiBase {
	protected static $model_class = 'ManagedHost';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['slug'])) {
			$filters['mgh_slug'] = [$this->options['slug'], PDO::PARAM_STR];
		}

		if (isset($this->options['host'])) {
			$filters['mgh_host'] = [$this->options['host'], PDO::PARAM_STR];
		}

		if (isset($this->options['provisioning_enabled'])) {
			$filters['mgh_provisioning_enabled'] = $this->options['provisioning_enabled'] ? "= true" : "= false";
		}


		return $this->_get_resultsv2('mgh_managed_hosts', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
