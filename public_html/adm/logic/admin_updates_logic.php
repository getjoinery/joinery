<?php
/**
 * Updates page logic — what this site runs, where it came from, and, on a site
 * no management node looks after, the button that updates it.
 *
 * The release facts come from the release's own statement in the code tree
 * (ReleaseProvenance) and the install history from what the upgrade records
 * (ReleaseInstall). Updating is a request: the code tree belongs to root, so
 * this page asks (RootRequest kind 'upgrade') and the host converger runs
 * utils/upgrade.php as root; the page then shows that run's transcript.
 * A managed site is updated by its management node, and the origin publishes
 * releases instead of installing them, so neither is offered the button.
 *
 * @version 1.0
 */
function admin_updates_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	$managed = ManagementNodeStatus::is_managed();
	$origin  = DeploymentHelper::isOriginNode();

	// Only a POST asks for an update: a link is a GET, and a browser follows
	// one from any site with this admin's session cookie attached.
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($input['action'] ?? '') === 'queue_upgrade') {
		if ($managed || $origin) {
			return LogicResult::redirect('/admin/admin_updates');
		}
		try {
			$request_id = RootRequest::submit('upgrade', array(), (int)$session->get_user_id());
		} catch (Throwable $e) {
			return LogicResult::redirect('/admin/admin_updates?error=' . urlencode('The update could not be asked for: ' . $e->getMessage()));
		}
		return LogicResult::redirect('/admin/admin_updates?request=' . urlencode($request_id));
	}

	$settings = Globalvars::get_instance();
	$source = trim((string)$settings->get_setting('upgrade_source'));
	$offered = $origin ? array('version' => '', 'error' => '') : admin_updates_offered($source);

	return LogicResult::render(array(
		'session'      => $session,
		'managed'      => $managed,
		'manager_url'  => ManagementNodeStatus::manager_url(),
		'origin'       => $origin,
		'version'      => trim((string)LibraryFunctions::get_joinery_version()),
		'running'      => ReleaseProvenance::running(),
		'log_required' => PackageSignature::nodeLog()['required'],
		'source'       => $source,
		'offered'      => $offered,
		'request_id'   => (string)($input['request'] ?? ''),
		'error'        => (string)($input['error'] ?? ''),
		'history'      => new MultiReleaseInstall(array(), array('release_install_id' => 'DESC'), 25),
		'sideloaded'   => admin_updates_sideloaded(),
		'forks'        => admin_updates_forks($origin),
	));
}

/**
 * The version the upgrade source offers now, asked live: ['version' => '', 'error' => ''].
 * A source that cannot be reached is shown, not stopped for: the operator may
 * be here to read a transcript.
 */
function admin_updates_offered(string $source): array {
	if ($source === '') {
		return array('version' => '', 'error' => 'No upgrade source is set for this site.');
	}
	$curl = curl_init();
	curl_setopt_array($curl, array(
		CURLOPT_URL => rtrim($source, '/') . '/utils/upgrade?serve-upgrade=1',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT => 15,
		CURLOPT_CONNECTTIMEOUT => 8,
	));
	$body = curl_exec($curl);
	$err  = curl_error($curl);
	curl_close($curl);
	if ($body === false || $err !== '') {
		return array('version' => '', 'error' => 'Could not reach ' . $source . ': ' . $err);
	}
	$decoded = json_decode((string)$body, true);
	$version = is_array($decoded) ? trim((string)($decoded['system_version'] ?? '')) : '';
	return $version === ''
		? array('version' => '', 'error' => 'The upgrade source answered, but named no version.')
		: array('version' => $version, 'error' => '');
}

/**
 * Every plugin and theme installed with Install anyway: one that our release
 * key did not sign ('unsigned'), or did sign but that is not in the public
 * log ('unlogged'). Who approved it and when come from the event log row the
 * installer writes (utils/install_extension.php); a style theme installs
 * without the warning, so it has no approver.
 */
function admin_updates_sideloaded(): array {
	$approvals = array();
	foreach (new MultiEventLog(array('event' => 'unsigned_package_installed'), array('event_log_id' => 'DESC')) as $log) {
		$note = (string)$log->get('evl_note');
		if (!preg_match('/^type=(\w+) kind=\w+ name=(\S+) /', $note, $m) || isset($approvals[$m[1] . '/' . $m[2]])) {
			continue;   // newest first: the first row for a name is its current install
		}
		$approvals[$m[1] . '/' . $m[2]] = array(
			'who'  => preg_match('/ approved_by=(.*?) ip=/', $note, $w) ? $w[1] : 'installed without the warning (a style theme)',
			'when' => $log->get_local('evl_create_time'),
		);
	}

	$out = array();
	foreach (array('unsigned', 'unlogged') as $trust) {
		foreach (new MultiPlugin(array('plg_trust' => $trust)) as $plugin) {
			if (!$plugin->plugin_directory_exists()) {
				continue;
			}
			$name = (string)$plugin->get('plg_name');
			$out[] = array('type' => 'plugin', 'name' => $name, 'version' => (string)$plugin->get_version(),
				'trust' => $trust, 'approval' => $approvals['plugin/' . $name] ?? null);
		}
		foreach (new MultiTheme(array('thm_trust' => $trust)) as $theme) {
			$name = (string)$theme->get('thm_name');
			if (!is_dir(PathHelper::getIncludePath('theme/' . $name))) {
				continue;
			}
			$out[] = array('type' => 'theme', 'name' => $name, 'version' => (string)$theme->get('thm_version'),
				'trust' => $trust, 'approval' => $approvals['theme/' . $name] ?? null);
		}
	}
	return $out;
}

/**
 * Every plugin and theme kept out of updates (a local copy, marked from the
 * Plugins or Themes page), with the version the upgrade source ships when it
 * is known. The origin's catalog is its own tree, so there is nothing to
 * compare a copy with.
 */
function admin_updates_forks(bool $origin): array {
	$out = array();
	$shipped = array('plugin' => null, 'theme' => null);
	foreach (new MultiPlugin(array('plg_receives_upgrades' => false)) as $plugin) {
		if (!$plugin->plugin_directory_exists()) {
			continue;
		}
		$out[] = array('type' => 'plugin', 'name' => (string)$plugin->get('plg_name'), 'version' => (string)$plugin->get_version());
	}
	foreach (new MultiTheme(array('thm_receives_upgrades' => false)) as $theme) {
		if (!is_dir(PathHelper::getIncludePath('theme/' . (string)$theme->get('thm_name')))) {
			continue;
		}
		$out[] = array('type' => 'theme', 'name' => (string)$theme->get('thm_name'), 'version' => (string)$theme->get('thm_version'));
	}
	if ($out !== array() && !$origin) {
		try {
			$shipped['plugin'] = MarketplaceClient::published_versions('plugins');
			$shipped['theme'] = MarketplaceClient::published_versions('themes');
		} catch (Throwable $e) {
			error_log('admin_updates_logic: catalog version lookup failed: ' . $e->getMessage());
		}
	}
	foreach ($out as $i => $fork) {
		$versions = $shipped[$fork['type']];
		$out[$i]['shipped'] = is_array($versions) ? (string)($versions[$fork['name']] ?? '') : '';
	}
	return $out;
}
