<?php
/**
 * InboundForwardDestination - a forwarding destination and whether the person
 * at it has agreed to receive the forwards.
 *
 * (specs/relay_receive_only_forwarding.md, rule 4.) Forwarding mail into an
 * inbox whose owner never asked for it is the complaint that hurts a sending
 * reputation, so a new destination gets one confirmation message and nothing
 * is forwarded to it until the link in it is followed and the page's button
 * pressed. Ignoring the message is how a destination declines.
 *
 * One row per (domain, mailbox, destination). A NULL mailbox is the domain's
 * catch-all, and mail a catch-all stored with no mailbox. The alias forward,
 * the catch-all forward and a filter's "Forward to" all ask the same row, so
 * agreeing once covers every way that mailbox's mail reaches the address.
 * Destinations that forwarded before confirmation existed were recorded
 * confirmed by migration ifd_001_confirm_existing_destinations.
 *
 * The link carries a random token; only its sha256 is stored, and a new
 * request replaces it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundForwardDestinationException extends SystemBaseException {}

class InboundForwardDestination extends SystemBase {
	public static $prefix = 'ifd';
	public static $tablename = 'ifd_inbound_forward_destinations';
	public static $pkey_column = 'ifd_inbound_forward_destination_id';

	const STATUS_PENDING   = 'pending';
	const STATUS_CONFIRMED = 'confirmed';

	/** A request is not sent again for the same destination inside this window. */
	const RESEND_INTERVAL_SECONDS = 3600;

	protected static $foreign_key_actions = [
		'ifd_ied_inbound_email_domain_id' => ['action' => 'cascade'],
		'ifd_iea_inbound_email_alias_id'  => ['action' => 'cascade'],
	];

	public static $field_specifications = array(
		'ifd_inbound_forward_destination_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ifd_ied_inbound_email_domain_id'    => array('type'=>'int4', 'is_nullable'=>false),
		// NULL = the domain's catch-all (mail stored or forwarded with no mailbox).
		'ifd_iea_inbound_email_alias_id'     => array('type'=>'int8'),
		'ifd_destination'        => array('type'=>'varchar(320)', 'is_nullable'=>false),
		'ifd_status'             => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'pending'),
		'ifd_token_hash'         => array('type'=>'varchar(64)', 'index'=>true),
		'ifd_request_sent_time'  => array('type'=>'timestamp(6)'),
		'ifd_request_count'      => array('type'=>'int4', 'default'=>'0', 'is_nullable'=>false),
		'ifd_confirmed_time'     => array('type'=>'timestamp(6)'),
		'ifd_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'ifd_update_time'        => array('type'=>'timestamp(6)'),
		'ifd_delete_time'        => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('ifd_destination', strtolower(trim((string)$this->get('ifd_destination'))));
		$this->set('ifd_update_time', gmdate('Y-m-d H:i:s'));
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	function is_confirmed(): bool {
		return $this->get('ifd_status') === self::STATUS_CONFIRMED;
	}

	/** The row for one (domain, mailbox, destination), or null. */
	static function find(int $domain_id, ?int $alias_id, string $destination): ?self {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare('SELECT ifd_inbound_forward_destination_id FROM ifd_inbound_forward_destinations
			WHERE ifd_ied_inbound_email_domain_id = ? AND ifd_destination = ?
			  AND ifd_iea_inbound_email_alias_id IS NOT DISTINCT FROM ?::int8
			  AND ifd_delete_time IS NULL
			ORDER BY ifd_inbound_forward_destination_id LIMIT 1');
		$q->execute(array($domain_id, strtolower(trim($destination)), $alias_id ?: null));
		$id = $q->fetchColumn();
		return $id ? new self(intval($id), TRUE) : null;
	}

	/** The row a confirmation link's token names, or null. */
	static function GetByToken(string $token): ?self {
		$token = trim($token);
		if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
			return null;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare('SELECT ifd_inbound_forward_destination_id FROM ifd_inbound_forward_destinations
			WHERE ifd_token_hash = ? AND ifd_delete_time IS NULL LIMIT 1');
		$q->execute(array(hash('sha256', $token)));
		$id = $q->fetchColumn();
		return $id ? new self(intval($id), TRUE) : null;
	}

	/** The address mail arrives for, as the confirmation names it: the mailbox's, or "anything@domain". */
	function source_label(): string {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
		$domain = new InboundEmailDomain(intval($this->get('ifd_ied_inbound_email_domain_id')), TRUE);
		$domain_name = $domain->key ? (string)$domain->get('ied_domain') : '';
		$alias_id = intval($this->get('ifd_iea_inbound_email_alias_id'));
		if ($alias_id > 0) {
			$alias = new InboundEmailAlias($alias_id, TRUE);
			if ($alias->key) {
				return $alias->get('iea_alias') . '@' . $domain_name;
			}
		}
		return 'addresses at ' . $domain_name;
	}
}

class MultiInboundForwardDestination extends SystemMultiBase {
	protected static $model_class = 'InboundForwardDestination';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['domain_id'])) {
			$filters['ifd_ied_inbound_email_domain_id'] = [intval($this->options['domain_id']), PDO::PARAM_INT];
		}
		if (array_key_exists('alias_id', $this->options)) {
			$filters['ifd_iea_inbound_email_alias_id'] = $this->options['alias_id'] === null
				? 'IS NULL'
				: [intval($this->options['alias_id']), PDO::PARAM_INT];
		}
		if (isset($this->options['status'])) {
			$filters['ifd_status'] = [(string)$this->options['status'], PDO::PARAM_STR];
		}

		return $this->_get_resultsv2('ifd_inbound_forward_destinations', $filters, $this->order_by, $only_count, $debug);
	}
}
