<?php
/**
 * The seeded registry of sealed-secret categories — the reconciler's durable
 * memory.
 *
 * Every `sealed_secrets` declaration on disk (§ SealedSecretsDeclarations) is
 * mirrored into a row here on each update_database, the same way plugin settings
 * seed into stg_settings. The row carries only the CODE-FREE part of the
 * declaration (locator, kind, label, feature, source), which is the whole point:
 * a plugin deleted from disk takes its plugin.json with it, but its sealed rows
 * are still in the database. A registry row outlives the plugin's files, so the
 * reconciler can still count those orphans and the import scrub can still clear
 * them. A row whose locator matches no on-disk manifest IS the orphan signal.
 *
 * The row also carries the reconciler's last verdict for the category, so the
 * setup-wizard pill and the management-node stats blob can read a cached health
 * verdict instead of walking a live decrypt of every row on each admin request.
 * The verdict is kept current by the reconciler and by the dead->alive /
 * alive->dead transitions the alert dedup already tracks.
 *
 * @version 1.0
 */
class SealedSecretRegistry extends SystemBase {
	public static $prefix = 'ssr';
	public static $tablename = 'ssr_sealed_secret_registry';
	public static $pkey_column = 'ssr_id';

	// The category's aggregate health from the last reconcile.
	const STATE_UNKNOWN   = 'unknown';   // never reconciled
	const STATE_OK        = 'ok';        // every secret of this kind opens (or is absent/plaintext)
	const STATE_DEAD      = 'dead';      // at least one secret is stored but will not decrypt
	const STATE_ABSENT    = 'absent';    // configured nowhere — not set up

	public static $field_specifications = array(
		'ssr_id'            => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ssr_locator'       => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
		'ssr_source'        => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
		'ssr_kind'          => array('type'=>'varchar(40)', 'required'=>true, 'is_nullable'=>false,
			'allowed_values'=>array('operator', 'regenerable', 'regenerable-breaks-things', 'ephemeral')),
		'ssr_label'         => array('type'=>'varchar(255)'),
		'ssr_feature'       => array('type'=>'varchar(255)'),
		'ssr_reprovision'   => array('type'=>'varchar(255)'),
		'ssr_enumerator'    => array('type'=>'varchar(255)'),
		'ssr_last_state'    => array('type'=>'varchar(20)', 'default'=>'unknown', 'is_nullable'=>false),
		'ssr_dead_count'    => array('type'=>'int4', 'default'=>0, 'is_nullable'=>false),
		'ssr_checked_time'  => array('type'=>'timestamp(6)'),
		'ssr_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'ssr_update_time'   => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('ssr_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * Seed/refresh the registry table from the on-disk manifests.
	 *
	 * Upsert by locator, so a changed label or kind is picked up. Deliberately
	 * does NOT prune rows absent from the manifests — that absence is the orphan
	 * signal the reconciler and scrub rely on. Runs from update_database's
	 * post-deploy step chain (never upgrade.php's pre-deploy pass).
	 *
	 * @return array{seeded:int} count of categories mirrored
	 */
	public static function seed_from_manifests(): array {
		require_once(PathHelper::getIncludePath('includes/SealedSecretsDeclarations.php'));
		$dblink = DbConnector::get_instance()->get_db_link();

		$sql = "INSERT INTO ssr_sealed_secret_registry
					(ssr_locator, ssr_source, ssr_kind, ssr_label, ssr_feature,
					 ssr_reprovision, ssr_enumerator, ssr_create_time, ssr_update_time)
				VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
				ON CONFLICT (ssr_locator) DO UPDATE SET
					ssr_source      = EXCLUDED.ssr_source,
					ssr_kind        = EXCLUDED.ssr_kind,
					ssr_label       = EXCLUDED.ssr_label,
					ssr_feature     = EXCLUDED.ssr_feature,
					ssr_reprovision = EXCLUDED.ssr_reprovision,
					ssr_enumerator  = EXCLUDED.ssr_enumerator,
					ssr_update_time = NOW()";
		$stmt = $dblink->prepare($sql);

		$n = 0;
		foreach (SealedSecretsDeclarations::all() as $locator => $d) {
			$stmt->execute(array(
				$locator,
				$d['_source'],
				$d['kind'],
				$d['label'] ?? null,
				$d['feature'] ?? null,
				$d['reprovision'] ?? null,
				$d['enumerator'] ?? null,
			));
			$n++;
		}

		return array('seeded' => $n);
	}

	/** True when this registry row has no matching on-disk manifest (its plugin was deleted). */
	function is_orphan(): bool {
		require_once(PathHelper::getIncludePath('includes/SealedSecretsDeclarations.php'));
		return !SealedSecretsDeclarations::isDeclared((string)$this->get('ssr_locator'));
	}
}

class MultiSealedSecretRegistry extends SystemMultiBase {
	protected static $model_class = 'SealedSecretRegistry';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['kind'])) {
			$filters['ssr_kind'] = array($this->options['kind'], PDO::PARAM_STR);
		}
		if (isset($this->options['last_state'])) {
			$filters['ssr_last_state'] = array($this->options['last_state'], PDO::PARAM_STR);
		}
		if (isset($this->options['source'])) {
			$filters['ssr_source'] = array($this->options['source'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('ssr_sealed_secret_registry', $filters, $this->order_by, $only_count, $debug);
	}
}
