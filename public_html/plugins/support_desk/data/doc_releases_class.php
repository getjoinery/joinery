<?php
/**
 * SupportDocRelease — one release whose documents this node has indexed.
 *
 * Written by SupportDocIndex when a release is indexed, once: a release's
 * index is never edited, and a republish of the same release leaves it. It
 * carries the release's docs map (every indexed path and heading chain, the
 * fixed prefix's map block) and how large the fixed prefix is, so the admin
 * page can say what warming a release's cache costs.
 *
 * @version 0.1.0
 */

class SupportDocRelease extends SystemBase {
	public static $prefix = 'sdr';
	public static $tablename = 'sdr_doc_releases';
	public static $access_class = 'content';
	public static $pkey_column = 'sdr_doc_release_id';

	/** Where a release's documents were read from. */
	const SOURCE_ARCHIVE = 'archive';
	const SOURCE_TREE    = 'tree';

	public static $field_specifications = array(
		'sdr_doc_release_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'sdr_release'        => array('type' => 'varchar(64)', 'is_nullable' => false, 'unique' => true),
		'sdr_source'         => array('type' => 'varchar(16)', 'is_nullable' => false),
		'sdr_sections'       => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		// The docs map: one line per indexed path and heading chain.
		'sdr_map'            => array('type' => 'text', 'is_nullable' => true),
		// The shipped FAQ of this release, whole, as the fixed prefix carries it.
		'sdr_faq'            => array('type' => 'text', 'is_nullable' => true),
		'sdr_built_time'     => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	/** The row for a release, or null. */
	public static function forRelease(string $release): ?SupportDocRelease {
		foreach (new MultiSupportDocRelease(array('release' => $release)) as $row) {
			return $row;
		}
		return null;
	}

	/** Every indexed release, newest first by version order. */
	public static function releases(): array {
		$out = array();
		try {
			$q = DbConnector::get_instance()->get_db_link()->query('SELECT sdr_release FROM sdr_doc_releases');
			$out = $q->fetchAll(PDO::FETCH_COLUMN);
		} catch (\Throwable $e) {
			return array();
		}
		usort($out, function ($a, $b) { return version_compare((string)$b, (string)$a); });
		return $out;
	}
}

class MultiSupportDocRelease extends SystemMultiBase {
	protected static $model_class = 'SupportDocRelease';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['release'])) {
			$filters['sdr_release'] = array($this->options['release'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('sdr_doc_releases', $filters, $this->order_by, $only_count, $debug);
	}
}
