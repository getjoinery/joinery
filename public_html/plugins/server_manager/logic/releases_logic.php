<?php
/**
 * The public releases page (/server_manager/releases; spec release_transparency, D7, O5).
 *
 * Served by the site that publishes releases, which holds both things it
 * shows: the record of every release statement logged under our key
 * (rle_release_log_entries), and how far ReleaseLogTail has read the public
 * logs looking for any it does not account for. Every other site answers that
 * it does not publish releases. Read-only, no login.
 *
 * Each release lists the keys it installs on nodes; each log key carries the
 * last daily check against Sigstore's trusted root (ReleaseLogTail).
 *
 * @version 1.1 - the keys each release installs, and Sigstore's answer for each log key (D7)
 * @version 1.0
 */
function releases_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	if (!ReleaseLogWatch::watches()) {
		return LogicResult::render(array('is_valid_page' => false));
	}

	$state = ReleaseLogTail::state();
	$root = $state['root'] ?? array();
	$unlisted = array();
	foreach ($root['unknown'] ?? array() as $k) {
		$unlisted[$k['origin'] . '#' . $k['fingerprint']] = true;
	}
	$checked = isset($root['checked_at']);

	$db = DbConnector::get_instance()->get_db_link();
	$shipped = $db->prepare('SELECT 1 FROM upg_upgrades WHERE upg_release_statement = ? LIMIT 1');
	$releases = array();
	foreach (new MultiReleaseLogEntry(array(), array('rle_log_index' => 'DESC')) as $row) {
		$statement = (string)$row->get('rle_statement');
		$read = ReleaseProvenance::read($statement) ?? array();
		$shipped->execute(array($statement));
		$keys = array('statement' => array(), 'log' => array());
		try {
			$installed = ReleaseStatementPublisher::payloadOf(json_decode($statement, true) ?: array())['keys_installed'];
			foreach ($installed['statement_keys'] ?? array() as $b64) {
				$keys['statement'][] = ReleaseLogTail::fingerprint((string)base64_decode((string)$b64, true));
			}
			foreach ($installed['log_keys'] ?? array() as $pair) {
				$fp = ReleaseLogTail::fingerprint((string)base64_decode((string)($pair['key'] ?? ''), true));
				$origin = (string)($pair['origin'] ?? '');
				$keys['log'][] = array('origin' => $origin, 'fingerprint' => $fp,
					'sigstore' => !$checked ? 'not checked yet' : (isset($unlisted[$origin . '#' . $fp]) ? 'not listed' : 'listed'));
			}
		} catch (Throwable $e) {
			// An unreadable payload installs nothing the page can show.
		}
		$releases[] = array(
			'version'      => (string)$row->get('rle_version'),
			'published_at' => (string)($read['published_at'] ?? ''),
			'core_commit'  => (string)($read['core_commit'] ?? ''),
			'agent_commit' => (string)($read['agent_commit'] ?? ''),
			'log_origin'   => (string)$row->get('rle_log_origin'),
			'log_index'    => (int)$row->get('rle_log_index'),
			'seen_in_log'  => (string)$row->get('rle_seen_in_log_time'),
			'shipped'      => $shipped->fetchColumn() !== false,
			'keys'         => $keys,
		);
	}

	$logs = array();
	foreach (ReleaseLogTail::logOrigins() as $origin) {
		$log = $state['logs'][$origin] ?? null;
		$logs[] = array(
			'origin'       => $origin,
			'from'         => $log ? (int)$log['from'] : null,
			'read_to'      => $log ? (int)$log['next'] : null,
			'caught_up_at' => ($log['caught_up_at'] ?? null) ? gmdate('Y-m-d H:i', (int)$log['caught_up_at']) . ' UTC' : '',
		);
	}

	return LogicResult::render(array(
		'is_valid_page' => true,
		'releases'      => $releases,
		'logs'          => $logs,
		'unaccounted'   => ReleaseLogTail::unaccounted($state, ReleaseLogTail::ledger()),
		'unlisted'      => $root['unknown'] ?? array(),
		'root_checked'  => $checked ? gmdate('Y-m-d H:i', (int)$root['checked_at']) . ' UTC' : '',
		'source'        => rtrim(LibraryFunctions::get_absolute_url(''), '/'),
	));
}

function releases_logic_descriptor(): array {
	return [
		'description' => 'Every release statement logged under the Joinery release key, and how far the public log has been read for any it does not account for.',
		'mutates'     => false,
		'auth'        => [
			'capability'       => null,
			'requires_session' => false,
			'allow_guest'      => true,
		],
		'input'       => [],
	];
}
