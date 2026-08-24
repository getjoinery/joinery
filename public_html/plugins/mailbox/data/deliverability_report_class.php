<?php
/**
 * DeliverabilityReport - One row per machine-generated deliverability report
 * received for a hosted domain (specs/deliverability_report_ingest.md).
 *
 * A report is DMARC aggregate mail, a TLS-RPT summary, or an ARF feedback-loop
 * message: providers send them to an ordinary address because the domain
 * published a policy asking for them. Detection and parsing happen during
 * ingest (DeliverabilityReportIngest) while plaintext is in hand; what this
 * table keeps afterwards is the report's envelope facts and parse status. The
 * per-source lines a parsed report contained live in
 * dvs_deliverability_report_sources — that table is the product.
 *
 * The raw report is held in dvr_raw_report ONLY while unparsed (a parse
 * failure, or a kind with no parser yet) so the dialect can be diagnosed; a
 * successful parse clears it, because the source rows carry everything it
 * said. Report rows themselves never expire — they are small, and they are
 * the only historical record of who has sent as a domain.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class DeliverabilityReportException extends SystemBaseException {}

class DeliverabilityReport extends SystemBase {
	public static $prefix = 'dvr';
	public static $tablename = 'dvr_deliverability_reports';
	public static $pkey_column = 'dvr_deliverability_report_id';

	// Report kinds (dvr_kind)
	const KIND_DMARC_AGGREGATE = 'dmarc_aggregate';
	const KIND_TLSRPT          = 'tlsrpt';
	const KIND_ARF             = 'arf';       // feedback loops; DMARC forensic (ruf) arrives in the same ARF shape
	const KIND_UNKNOWN         = 'unknown';   // detected as report mail, kind not identified

	// Parse status (dvr_parse_status)
	const PARSE_PARSED   = 'parsed';    // source rows written, raw discarded
	const PARSE_FAILED   = 'failed';    // a parser ran and could not read it; raw kept
	const PARSE_UNPARSED = 'unparsed';  // no parser for this kind yet; raw kept, counted

	protected static $foreign_key_actions = [
		'dvr_ied_inbound_email_domain_id' => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'dvr_deliverability_report_id'    => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// The HOSTED domain the report concerns (the reported domain when hosted;
		// the arriving domain for a report that failed to parse far enough to say).
		'dvr_ied_inbound_email_domain_id' => array('type'=>'int4'),
		'dvr_kind'          => array('type'=>'varchar(24)', 'is_nullable'=>false),
		// Reporting organisation as the report named itself (google.com, Outlook.com, …)
		'dvr_org_name'      => array('type'=>'varchar(255)', 'is_nullable'=>false, 'default'=>''),
		'dvr_org_email'     => array('type'=>'varchar(255)'),
		// The reporter's own id for this report. Falls back to sha256 of the raw
		// bytes when none could be extracted, so the dedup key below always holds.
		'dvr_report_id'     => array('type'=>'varchar(255)', 'is_nullable'=>false,
			'unique_with'=>array('dvr_ied_inbound_email_domain_id', 'dvr_kind', 'dvr_org_name')),
		// The reported domain as a string (survives domain-row deletion).
		'dvr_domain'        => array('type'=>'varchar(255)'),
		// The address the report arrived at.
		'dvr_recipient'     => array('type'=>'varchar(500)'),
		'dvr_begin_time'    => array('type'=>'timestamp(6)'),
		'dvr_end_time'      => array('type'=>'timestamp(6)'),
		'dvr_parse_status'  => array('type'=>'varchar(16)', 'is_nullable'=>false),
		'dvr_parse_error'   => array('type'=>'text'),
		// Raw carrier message, held only while dvr_parse_status != parsed.
		'dvr_raw_report'    => array('type'=>'text'),
		// The policy block the reporter said it evaluated against (JSON).
		'dvr_policy_published' => array('type'=>'text'),
		'dvr_source_count'  => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'dvr_message_count' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'dvr_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'dvr_delete_time'   => array('type'=>'timestamp(6)'),
	);
}

class MultiDeliverabilityReport extends SystemMultiBase {
	protected static $model_class = 'DeliverabilityReport';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['domain_id'])) {
			$filters['dvr_ied_inbound_email_domain_id'] = [$this->options['domain_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['kind'])) {
			$filters['dvr_kind'] = [$this->options['kind'], PDO::PARAM_STR];
		}
		if (isset($this->options['parse_status'])) {
			$filters['dvr_parse_status'] = [$this->options['parse_status'], PDO::PARAM_STR];
		}
		if (isset($this->options['received_since'])) {
			$filters['dvr_create_time'] = ">= '" . gmdate('Y-m-d H:i:s', strtotime($this->options['received_since'])) . "'";
		}

		return $this->_get_resultsv2('dvr_deliverability_reports', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
