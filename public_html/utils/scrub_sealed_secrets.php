<?php
/**
 * Scrub sealed secrets from a freshly imported database.
 *
 * Run on IMPORT — after a `clone_export` dump is restored into a new
 * environment, or when a database is seeded from another site. Every value
 * SecretBox sealed was sealed to the SOURCE install's key, which this
 * environment does not have, so every one of them is dead here. This nulls them
 * at their declared locators so the copy lands CLEAN — each sealed value now
 * reads as "absent" (not configured) rather than "dead" (stored but unreadable),
 * which is the difference between a fresh setup and a pile of broken features.
 *
 * It is driven entirely by the seeded registry table `ssr_sealed_secret_registry`,
 * which travels inside the dump itself — so it needs NO plugin code present and
 * NO SecretBox key. The locator is the code-free part of each declaration for
 * exactly this reason (see includes/SealedSecretsDeclarations.php).
 *
 * Why import and not export: `clone_export` streams a passthru
 * `pg_dump | gzip | openssl` with no seam to edit mid-stream, and the export
 * must never UPDATE the source. The dump is already encrypted in transit under
 * the clone key, so the source ciphertext it briefly carries is never exposed —
 * which is why scrubbing at the destination is safe. A genuine MOVE is
 * restore-from-backup, which carries config/ and the matching key, so nothing is
 * scrubbed there.
 *
 * Usage:  php utils/scrub_sealed_secrets.php [--verbose]
 *
 * @version 1.1 - logic in scrub_sealed_secrets() so tests can drive it in-process
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

/**
 * Null every sealed value named by the registry table, plus the key canary.
 * Pure SQL; loads no plugin and needs no secret_box_key.
 *
 * @return array{cleared:int, skipped:int, notes:string[]}
 */
function scrub_sealed_secrets(bool $verbose = false): array {
	$dblink = DbConnector::get_instance()->get_db_link();
	$out = array('cleared' => 0, 'skipped' => 0, 'notes' => array());

	// The registry table is what carries the locators across the copy. If it is
	// not present, this is a database older than the registry — nothing to scrub.
	$has_table = $dblink->query("SELECT to_regclass('ssr_sealed_secret_registry') IS NOT NULL")->fetchColumn();
	if (!$has_table) {
		$out['notes'][] = 'No sealed-secret registry table present; nothing to scrub.';
		return $out;
	}

	foreach ($dblink->query("SELECT ssr_locator FROM ssr_sealed_secret_registry ORDER BY ssr_locator")
			->fetchAll(PDO::FETCH_COLUMN) as $locator) {
		$locator = (string)$locator;

		// A session-scoped locator (an in-flight wizard stash) never lands in the
		// database at all, so there is nothing here to clear.
		if (strpos($locator, 'session:') === 0) { $out['skipped']++; continue; }

		try {
			// Clear ONLY values that are actually SecretBox-sealed (a v1.sodium. /
			// v1.aesgcm. blob, bare or inside a {"enc":"…"} envelope). A readable
			// plaintext value — a zero-config credential, a legacy plaintext OAuth
			// secret, or an operator-entered registrant contact stored as raw JSON
			// where no key exists — decrypts fine anywhere and must NOT be nulled;
			// doing so would be data loss, not a scrub of dead ciphertext.
			if (strpos($locator, '.') === false) {
				$q = $dblink->prepare("UPDATE stg_settings SET stg_value = ''
					WHERE stg_name = ? AND (stg_value LIKE 'v1.sodium.%' OR stg_value LIKE 'v1.aesgcm.%')");
				$q->execute(array($locator));
				$n = $q->rowCount();
			} else {
				list($table, $column) = explode('.', $locator, 2);
				if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
					$out['skipped']++; continue;
				}
				$exists = $dblink->query("SELECT to_regclass('" . $table . "') IS NOT NULL")->fetchColumn();
				if (!$exists) { $out['skipped']++; continue; }
				$n = $dblink->exec("UPDATE \"{$table}\" SET \"{$column}\" = NULL
					WHERE \"{$column}\" IS NOT NULL
					  AND (\"{$column}\"::text LIKE '%v1.sodium.%' OR \"{$column}\"::text LIKE '%v1.aesgcm.%')");
			}
			if ($n > 0) { $out['cleared']++; if ($verbose) $out['notes'][] = "scrubbed {$locator} ({$n})"; }
		} catch (\Throwable $e) {
			$out['skipped']++; $out['notes'][] = "! {$locator}: " . $e->getMessage();
		}
	}

	// Drop the key canary too — it was sealed to the source key and is meaningless
	// here. The next update_database re-mints it against this environment's key.
	$dblink->prepare("DELETE FROM stg_settings WHERE stg_name = 'secret_box_canary'")->execute();

	return $out;
}

// CLI entry point (skipped when this file is include()d by a test).
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
	$r = scrub_sealed_secrets(in_array('--verbose', $argv, true));
	echo "Sealed-secret scrub complete: {$r['cleared']} locator(s) cleared, {$r['skipped']} skipped.\n";
	foreach ($r['notes'] as $note) echo "  {$note}\n";
}
