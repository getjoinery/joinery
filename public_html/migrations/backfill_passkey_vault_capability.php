<?php
/**
 * Classify passkeys enrolled before the platform recorded what an authenticator
 * can do, so a U2F-only security key stops being offered a vault activation it
 * can never complete.
 *
 * Registration now records credProps.rk and authenticatorAttachment, which
 * together say whether the browser fell back to CTAP1. Credentials enrolled
 * earlier have neither, but they do carry the library's serialized
 * CredentialRecord, and that holds `uvInitialized` — the same conclusion from a
 * field we have stored all along. This stamps the two columns on rows that meet
 * the evidence, so Passkey::vault_capability() and the stored signals agree
 * without a second code path reading pkc_source_json at runtime.
 *
 * Three conditions, all required:
 *
 *   1. pkc_prf_capable is false. A FIDO2 authenticator reports PRF at creation
 *      whether or not a PIN is set, so this alone excludes every PIN-less FIDO2
 *      key — the misclassification that would actually hurt, since hiding
 *      activation on a key a PIN would fix is worse than leaving it unknown.
 *   2. uvInitialized is EXPLICITLY false. It is a latch: set at registration
 *      and, while false, raised by the first assertion that verifies the user.
 *      False therefore means this credential has never verified a user in its
 *      life, which a FIDO2 key with a PIN would have done at its first sign-in.
 *      A missing flag is not evidence of anything and leaves the row unknown.
 *   3. Transports are recorded and do not include `internal`. Platform
 *      authenticators are excluded outright: Windows Hello reports no PRF at
 *      creation and evaluates it fine at assertion, and it is the known false
 *      negative the three-state design exists to accommodate.
 *
 * A run that marks a large fraction of the estate means the rule is wrong, not
 * that the estate is bad — the expected result is a handful of rows or none.
 * A row marked in error costs a menu action, never vault access: an incapable
 * credential holds no wrapping by construction, and vault prompts filter by
 * wrapping rather than by capability precisely so a misclassification here can
 * never reach the unlock path.
 *
 * Idempotent — a stamped row is skipped on the next run.
 */
function backfill_passkey_vault_capability() {
	$db = DbConnector::get_instance()->get_db_link();

	$rows = $db->query(
		"SELECT pkc_passkey_credential_id, pkc_label, pkc_transports, pkc_source_json
		 FROM pkc_passkey_credentials
		 WHERE pkc_delete_time IS NULL
		   AND pkc_prf_capable = FALSE
		   AND pkc_discoverable IS NULL"
	)->fetchAll(PDO::FETCH_ASSOC);

	if (!$rows) {
		echo "No unclassified passkeys to examine.\n";
		return;
	}

	$update = $db->prepare(
		"UPDATE pkc_passkey_credentials
		 SET pkc_discoverable = FALSE, pkc_attachment = 'cross-platform'
		 WHERE pkc_passkey_credential_id = ?"
	);

	$marked = 0;
	foreach ($rows as $row) {
		$source = json_decode((string)$row['pkc_source_json'], true);
		$uv_never = is_array($source) && array_key_exists('uvInitialized', $source)
			&& $source['uvInitialized'] === false;
		if (!$uv_never) {
			continue;
		}

		$transports = json_decode((string)$row['pkc_transports'], true);
		if (!is_array($transports) || !$transports || in_array('internal', $transports, true)) {
			continue;
		}

		$update->execute(array($row['pkc_passkey_credential_id']));
		$marked++;
		echo "Marked credential {$row['pkc_passkey_credential_id']} ("
			. ($row['pkc_label'] !== null && $row['pkc_label'] !== '' ? $row['pkc_label'] : 'unlabelled')
			. ", transports " . implode('/', $transports) . ") as sign-in only.\n";
	}

	echo "Examined " . count($rows) . " unclassified passkey(s); marked {$marked} as sign-in only.\n";
}
