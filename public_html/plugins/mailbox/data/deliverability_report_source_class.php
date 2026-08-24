<?php
/**
 * DeliverabilityReportSource - One row per source line inside a parsed
 * deliverability report (specs/deliverability_report_ingest.md § D4).
 *
 * This table is the product. Its natural query — group by source IP for a
 * domain across a time window, split by whether alignment passed — is the
 * sender inventory: everything that is sending as your domain, and whether it
 * is authorised. Rows never expire (D6); they are small, and they are the only
 * historical record of that answer.
 *
 * The domain id is denormalised from the parent report so the inventory query
 * and the D7 first-sighting check need no join.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class DeliverabilityReportSourceException extends SystemBaseException {}

class DeliverabilityReportSource extends SystemBase {
	public static $prefix = 'dvs';
	public static $tablename = 'dvs_deliverability_report_sources';
	public static $pkey_column = 'dvs_deliverability_report_source_id';

	protected static $foreign_key_actions = [
		'dvs_dvr_deliverability_report_id' => ['action' => 'cascade'],
		'dvs_ied_inbound_email_domain_id'  => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'dvs_deliverability_report_source_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'dvs_dvr_deliverability_report_id'    => array('type'=>'int8', 'is_nullable'=>false,
			'foreign_key'=>array('table'=>'dvr_deliverability_reports', 'column'=>'dvr_deliverability_report_id', 'on_delete'=>'CASCADE')),
		'dvs_ied_inbound_email_domain_id'     => array('type'=>'int4'),
		'dvs_source_ip'     => array('type'=>'varchar(45)', 'is_nullable'=>false),
		'dvs_count'         => array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		// DMARC: none | quarantine | reject. TLS-RPT: the failure result-type.
		'dvs_disposition'   => array('type'=>'varchar(40)'),
		// policy_evaluated verdicts (already alignment-aware in the report)
		'dvs_dkim_result'   => array('type'=>'varchar(16)'),
		'dvs_spf_result'    => array('type'=>'varchar(16)'),
		// Overall: did this mail count as authorised for the domain?
		'dvs_aligned'       => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'dvs_header_from'   => array('type'=>'varchar(255)'),
		'dvs_envelope_from' => array('type'=>'varchar(255)'),
		// Raw auth_results block (JSON) — which DKIM domains/selectors actually signed
		'dvs_auth_detail'   => array('type'=>'text'),
		// Denormalised end of the report window, so windowed queries hit one table
		'dvs_end_time'      => array('type'=>'timestamp(6)'),
		'dvs_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'dvs_delete_time'   => array('type'=>'timestamp(6)'),
	);

	public static $index_specifications = array(
		array('columns' => array('dvs_ied_inbound_email_domain_id', 'dvs_source_ip')),
	);
}

class MultiDeliverabilityReportSource extends SystemMultiBase {
	protected static $model_class = 'DeliverabilityReportSource';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['report_id'])) {
			$filters['dvs_dvr_deliverability_report_id'] = [$this->options['report_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['domain_id'])) {
			$filters['dvs_ied_inbound_email_domain_id'] = [$this->options['domain_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['source_ip'])) {
			$filters['dvs_source_ip'] = [$this->options['source_ip'], PDO::PARAM_STR];
		}
		if (isset($this->options['aligned'])) {
			$filters['dvs_aligned'] = $this->options['aligned'] ? 'IS TRUE' : 'IS FALSE';
		}

		return $this->_get_resultsv2('dvs_deliverability_report_sources', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
