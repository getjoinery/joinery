<?php
/**
 * PageProbeGrant - one page_probe's permission to render one page once, as
 * one viewer, from this machine, within a minute; and the report that render
 * leaves behind.
 *
 * specs/agent_recipes_and_vocabulary.md, "page_probe {page, viewer}". The
 * probe script (utils/page_probe.php, run by the agent's page_probe word)
 * mints the row with the hash of a token only it holds, sends one loopback
 * request carrying the token, reads the report the request wrote here, and
 * deletes the row and the throwaway user it made. A row outlives a probe
 * only when the probe died mid-way; PageProbe::sweep() removes those and
 * their users.
 *
 * @version 1.0
 */

class PageProbeGrantException extends SystemBaseException {}

class PageProbeGrant extends SystemBase {
	public static $prefix = 'ppg';
	public static $tablename = 'ppg_page_probe_grants';
	public static $pkey_column = 'ppg_page_probe_grant_id';

	public static $json_vars = array('ppg_report');

	public static $field_specifications = array(
		'ppg_page_probe_grant_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ppg_token_hash'          => array('type'=>'varchar(64)', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
		'ppg_usr_user_id'         => array('type'=>'int8'),
		'ppg_viewer'              => array('type'=>'varchar(16)', 'required'=>true, 'is_nullable'=>false),
		'ppg_page'                => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'ppg_expires_time'        => array('type'=>'timestamp(6)', 'required'=>true, 'is_nullable'=>false),
		'ppg_consumed_time'       => array('type'=>'timestamp(6)'),
		'ppg_report'              => array('type'=>'jsonb'),
		'ppg_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	protected static $foreign_key_actions = [
		'ppg_usr_user_id' => ['action' => 'cascade'],
	];

	/** The grant a token names, or null. */
	public static function for_token_hash(string $hash): ?PageProbeGrant {
		foreach (new MultiPageProbeGrant(array('token_hash' => $hash), array(), 1) as $g) {
			return $g;
		}
		return null;
	}

	/** The stored report as an array, or null. */
	public function report(): ?array {
		$r = $this->get('ppg_report');
		if (is_string($r)) {
			$r = json_decode($r, true);
		}
		return is_array($r) ? $r : null;
	}
}

class MultiPageProbeGrant extends SystemMultiBase {
	protected static $model_class = 'PageProbeGrant';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['token_hash'])) {
			$filters['ppg_token_hash'] = [$this->options['token_hash'], PDO::PARAM_STR];
		}
		// A UTC timestamp the caller computed, checked to be exactly that shape
		// before it becomes part of the condition.
		if (isset($this->options['expired_before'])
				&& preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$this->options['expired_before'])) {
			$filters['ppg_expires_time'] = "< '" . $this->options['expired_before'] . "'";
		}
		return $this->_get_resultsv2('ppg_page_probe_grants', $filters, $this->order_by, $only_count, $debug);
	}
}
