<?php
/** @joinery-test
 * name: filter_import_delete_disabled
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Gmail filter import: delete-action rules land DISABLED and flagged
 * (specs/mailbox_data_loss_fixes.md, Fix 9).
 *
 * A "delete on arrival" rule must never silently switch on during a migration.
 * At import, a filter carrying a delete/trash action is created with its enabled
 * flag OFF and called out in the summary; every other filter imports enabled.
 *
 * Run: php plugins/mailbox/tests/filter_import_delete_disabled_test.php  (schema synced).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_filter_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_filters_logic.php'));

class FilterImportDeleteDisabledTest {
	private $db;
	private $suffix;
	private $domain_id;

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	function run() {
		section('Gmail import: delete filters land disabled and flagged');
		try {
			$this->setUp();
			$this->testDeleteImportsDisabled();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	private function setUp() {
		$this->suffix = substr(md5(uniqid('fidd', true)), 0, 8);
		$d = new InboundEmailDomain(NULL);
		$d->set('ied_domain', 'fidd-' . $this->suffix . '.example');
		$d->set('ied_is_enabled', true);
		$d->save();
		$this->domain_id = intval($d->key);
	}

	/** A two-entry Gmail feed: one trash rule, one archive rule. */
	private function feed(): string {
		$entry = function ($from, $actionProp) {
			return "<entry><category term='filter'></category><title>Mail Filter</title>"
				. "<apps:property name='from' value='" . $from . "'/>"
				. "<apps:property name='" . $actionProp . "' value='true'/>"
				. "</entry>";
		};
		return "<?xml version='1.0' encoding='UTF-8'?>"
			. "<feed xmlns='http://www.w3.org/2005/Atom' xmlns:apps='http://schemas.google.com/apps/2006'>"
			. $entry('trashme-' . $this->suffix, 'shouldTrash')
			. $entry('archiveme-' . $this->suffix, 'shouldArchive')
			. "</feed>";
	}

	private function testDeleteImportsDisabled() {
		$xml = $this->feed();
		$summary = _filter_import_confirm($xml, array('0', '1'), 'domain:' . $this->domain_id, array());

		check(strpos($summary, 'Imported 2 filter') !== false, 'both filters imported (' . $summary . ')');
		check(stripos($summary, 'imported disabled') !== false,
			'summary flags the delete filter as imported disabled');

		$trash = $this->loadByFrom('trashme-' . $this->suffix);
		$archive = $this->loadByFrom('archiveme-' . $this->suffix);

		check($trash !== null && (bool)$trash->get('fil_action_delete') === true,
			'the trash rule imported with fil_action_delete set');
		check($trash !== null && (bool)$trash->get('fil_is_enabled') === false,
			'the trash rule imported DISABLED (fil_is_enabled = false)');
		check($archive !== null && (bool)$archive->get('fil_is_enabled') === true,
			'the archive rule imported ENABLED as before');
	}

	private function loadByFrom($from) {
		$stmt = $this->db->prepare(
			"SELECT fil_inbound_email_filter_id FROM fil_inbound_email_filters
			 WHERE fil_ied_inbound_email_domain_id = ? AND fil_match_from = ? AND fil_delete_time IS NULL LIMIT 1");
		$stmt->execute(array($this->domain_id, $from));
		$id = $stmt->fetchColumn();
		return $id ? new InboundEmailFilter(intval($id), TRUE) : null;
	}

	private function tearDown() {
		try {
			if ($this->domain_id) {
				$this->db->exec("DELETE FROM fil_inbound_email_filters WHERE fil_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . intval($this->domain_id));
			}
		} catch (\Throwable $e) {}
	}
}

$test = new FilterImportDeleteDisabledTest();
$test->run();
harness_finish();
