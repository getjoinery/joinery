<?php
/**
 * CopySecurityCheck - the security a site's machine promises, read on the
 * management node from what the machine already reports
 * (specs/site_copy_final_test.md, the rig). No login anywhere: the host
 * report, the host's container inventory, the node's own records.
 *
 * Run against a site before its copy (the baseline) and against it after the
 * switch: a row that passed before and fails after fails the run
 * (compare()). The rows that act as the site are the copy_probes agent
 * word's (JobCommandBuilder::build_copy_probes()), whose job prints them.
 *
 * Each row: key, what (in words), applies (false: not on this kind of
 * machine), pass (true, false, or null when the report does not say), and
 * evidence.
 *
 * @version 1.4 - the nightly replacement row is unknown on a host that has not run its check yet (a host
 *                made within the hour), not failed (site_copy_final_test C9)
 * @version 1.3 - a site on its own server is quiet when its host report says PHP-FPM is: Apache answered 'yes' to
 *               the quiet state's 503 too, so a dormant copy always failed its quiet row (B44)
 * @version 1.2 - the rows that act as the site are the copy_probes word's
 * @version 1.1 - layers is UNKNOWN when the provider's layer is not known; the setuid row says it counts the image
 * @version 1.0
 */

class CopySecurityCheck {

	/**
	 * The latest finished container inventory of a host: fn(ManagedNode): ?array.
	 * Null reads the newest completed container_inventory job. A variable only
	 * so a test can stand in for it; nothing in production sets it.
	 *
	 * @var callable|null
	 */
	public static $inventory_reader = null;

	/**
	 * Every row for the site on $node, as its machine reports now.
	 *
	 * @param bool $quiet whether the site is expected quiet now (a dormant copy, or frozen)
	 */
	public static function rows(ManagedNode $node, bool $quiet = false): array {
		$container = trim((string)$node->get('mgn_container_name')) !== '';
		$machine = DataRootLayers::machine_of($node);
		$report = self::report($machine);
		$site_report = self::report($node);
		$rows = array();
		$add = function (string $key, string $what, bool $applies, ?bool $pass, string $evidence) use (&$rows) {
			$rows[$key] = array('key' => $key, 'what' => $what, 'applies' => $applies, 'pass' => $applies ? $pass : null,
				'evidence' => $evidence);
		};

		// ── The host ──
		$h = $report['hardening'] ?? null;
		$add('kernel_settings', 'Kernel settings pinned', true, is_array($h) ? $h['state'] === 'ok' : null,
			is_array($h) ? 'hardening ' . $h['state'] . (($h['drift'] ?? array()) ? ': ' . json_encode($h['drift']) : '') : 'not reported');
		$k = $report['kernel_events_24h'] ?? null;
		$add('kernel_warnings', 'No kernel warning in a day', true, is_array($k) && is_int($k['kernel_warning'] ?? null) ? $k['kernel_warning'] === 0 : null,
			is_array($k) ? 'kernel_warning ' . json_encode($k['kernel_warning'] ?? null) : 'not reported');
		$engine = $report['site_engine'] ?? null;
		$add('site_engine', 'Sites run on the site container engine', $container, $engine === null ? null : $engine === 'podman',
			'site_engine ' . json_encode($engine));
		$security = $report['site_security'] ?? null;
		$want_filter = self::release_filter_sha();
		$add('site_filter', 'The host\'s site filter is this release\'s', $container,
			is_array($security) && $want_filter !== '' ? $security['filter'] === $want_filter : null,
			'host ' . json_encode(is_array($security) ? $security['filter'] : null) . ', release ' . ($want_filter !== '' ? $want_filter : 'unknown'));
		$refresh = $report['site_refresh'] ?? null;
		$held = false;
		foreach (is_array($refresh) ? (array)$refresh['holds'] : array() as $hold) {
			$held = $held || ($hold['site'] ?? '') === (string)$node->get('mgn_container_name');
		}
		// A host made since its last check time has not checked yet: unknown.
		$checked = is_array($refresh) && (string)($refresh['check'] ?? '') !== '';
		$add('site_refresh', 'Nightly replacement on, its last check ok, the site not held', $container,
			is_array($refresh) && ($checked || !$refresh['scheduled'] || $held) ? ($refresh['scheduled'] && $refresh['check'] === 'ok' && !$held) : null,
			is_array($refresh) ? 'scheduled ' . json_encode($refresh['scheduled'])
				. ($checked ? ', check ' . $refresh['check'] : ', not checked yet') . ($held ? ', held' : '')
				: 'site_refresh ' . json_encode($refresh));
		$limits = $report['outbound_limits'] ?? null;
		$add('outbound_limits', 'Outbound limits in force', true, is_array($limits) ? $limits['state'] === 'on' : null,
			is_array($limits) ? 'state ' . $limits['state'] : 'not reported');
		$restrictions = $report['host_restrictions'] ?? null;
		$add('host_restrictions', 'Host restrictions present', $container, $restrictions === null ? null : is_array($restrictions),
			'host_restrictions ' . json_encode($restrictions));
		$layers = DataRootLayers::of($node);
		$escrow = DataRootEscrow::for_node((int)$machine->key);
		$enrolled = $escrow && (string)$escrow->get('dre_status') === 'enrolled';
		// Not known (ours not reported, or the provider's layer unknown) is
		// UNKNOWN: a machine with none of ours may have no layer at all.
		$add('layers', 'One layer of encryption, its key held for an unattended boot', true,
			$layers['count'] === null ? null : ($layers['count'] === 1 && (!$layers['ours'] || $enrolled)),
			$layers['line'] . ($layers['ours'] ? ($enrolled ? '; escrow enrolled' : '; escrow NOT enrolled') : ''));

		// ── The site ──
		$entry = self::container_entry($report, (string)$node->get('mgn_container_name'));
		$inv = $container ? self::inventory_entry($machine, (string)$node->get('mgn_container_name')) : null;
		$sec = is_array($inv) ? ($inv['security'] ?? null) : null;
		$add('site_filter_label', 'The site was made under its host\'s filter', $container,
			is_array($sec) && is_array($security) ? ($sec['filter'] !== null && $sec['filter'] === $security['filter']) : null,
			'site ' . json_encode(is_array($sec) ? $sec['filter'] : null));
		$want_caps = self::release_caps();
		$caps = is_array($sec) ? $sec['caps'] : null;
		if (is_array($caps)) {
			sort($caps);
		}
		$add('capabilities', 'Every capability dropped but the run spec\'s', $container,
			is_array($sec) && $want_caps ? ($sec['drop_all'] && $caps === $want_caps) : null,
			is_array($sec) ? 'adds ' . implode(',', (array)$caps) . ($sec['drop_all'] ? ', drops all else' : ', does NOT drop all else')
				. '; run spec ' . implode(',', $want_caps) : 'not inventoried');
		// Counted in the site's image, once per image: a file made setuid in
		// the running container's own layer, or in a volume, is not counted.
		$add('setuid', 'No setuid file in the site\'s image but the jail\'s launcher', $container,
			is_array($sec) && is_int($sec['setuid']) ? $sec['setuid'] <= 1 : null,
			'setuid ' . json_encode(is_array($sec) ? $sec['setuid'] : null));
		$code = is_array($entry) ? ($entry['code_check'] ?? null) : null;
		$fresh = is_array($code) && ($code['state'] ?? '') === 'ok' && strtotime((string)($code['at'] ?? '')) > time() - 3600;
		$add('code_check', 'Its code is what was shipped, checked within the hour', $container,
			is_array($code) ? $fresh : null, 'code_check ' . json_encode($code));
		$add('backup', 'Its scheduled backup runs', true, $node->get('mgn_last_backup_outcome') === null ? null
			: (string)$node->get('mgn_last_backup_outcome') === 'success',
			'last backup ' . json_encode($node->get('mgn_last_backup_outcome')) . ' at ' . json_encode($node->get('mgn_last_backup_time')));
		// A quiet site's own pages are the quiet state's 503, so Apache answers
		// either way; the host report marks the quiet state on PHP-FPM, which the
		// quiet state keeps every request from.
		$answers = $container ? (is_array($entry) ? ($entry['answers'] ?? null) : null) : ($site_report['answers']['php-fpm'] ?? null);
		$add('quiet', $quiet ? 'Quiet, as it should be' : 'Live: no longer quiet', true,
			$answers === null || $answers === 'unknown' ? null : (($answers === 'quiet') === $quiet), 'answers ' . json_encode($answers));
		return $rows;
	}

	/**
	 * The rows that passed on the source and do not on the copy: what makes
	 * the run fail. A row that does not apply to the copy's kind of machine is
	 * not compared.
	 */
	public static function weaker(array $baseline, array $copy): array {
		$out = array();
		foreach ($baseline as $key => $row) {
			$c = $copy[$key] ?? null;
			if ($row['pass'] === true && $c && $c['applies'] && $c['pass'] !== true) {
				$out[$key] = $c;
			}
		}
		return $out;
	}

	/** The rows as lines: PASS, FAIL, UNKNOWN or N/A, what, and the evidence. */
	public static function lines(array $rows): array {
		$out = array();
		foreach ($rows as $row) {
			$word = !$row['applies'] ? 'N/A' : ($row['pass'] === true ? 'PASS' : ($row['pass'] === false ? 'FAIL' : 'UNKNOWN'));
			$out[] = str_pad($word, 8) . $row['what'] . ' — ' . $row['evidence'];
		}
		return $out;
	}

	/** The sha256 of the site filter this release ships, from its record, or ''. */
	public static function release_filter_sha(): string {
		$f = PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools/site_engine/joinery_site_seccomp.source';
		$text = (string)@file_get_contents($f);
		return preg_match('/^SHA256="([0-9a-f]{64})"$/m', $text, $m) ? $m[1] : '';
	}

	/** The capabilities a site adds in this release's run spec, sorted. */
	public static function release_caps(): array {
		$f = PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools/_site_run_spec.sh';
		$text = (string)@file_get_contents($f);
		if (!preg_match('/^RUN_SPEC_CAPS=\(([A-Z_ ]+)\)$/m', $text, $m)) {
			return array();
		}
		$caps = preg_split('/\s+/', trim($m[1]));
		sort($caps);
		return $caps;
	}

	private static function report(ManagedNode $node): array {
		$r = json_decode((string)$node->get('mgn_last_host_report'), true);
		return is_array($r) ? $r : array();
	}

	private static function container_entry(array $report, string $name): ?array {
		foreach (is_array($report['containers'] ?? null) ? $report['containers'] : array() as $c) {
			if (is_array($c) && ($c['name'] ?? '') === $name) {
				return $c;
			}
		}
		return null;
	}

	private static function inventory_entry(ManagedNode $host, string $name): ?array {
		$inv = self::$inventory_reader ? (self::$inventory_reader)($host) : self::latest_inventory($host);
		foreach (is_array($inv['containers'] ?? null) ? $inv['containers'] : array() as $c) {
			if (is_array($c) && ($c['name'] ?? '') === $name) {
				return $c;
			}
		}
		return null;
	}

	/** The newest completed container_inventory result of a host, or null. */
	public static function latest_inventory(ManagedNode $host): ?array {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_result FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'container_inventory'
			AND mjb_status = 'completed' ORDER BY mjb_management_job_id DESC LIMIT 1");
		$q->execute(array((int)$host->key));
		$r = json_decode((string)$q->fetchColumn(), true);
		return is_array($r) ? $r : null;
	}

	/**
	 * Ask the machine for what the rows read: a host report from it, and from
	 * a container's host its inventory. The rows are read once the jobs have
	 * finished.
	 *
	 * @return int[] the jobs queued
	 */
	public static function refresh(ManagedNode $node, ?int $user_id = null): array {
		$machine = DataRootLayers::machine_of($node);
		$jobs = array();
		$jobs[] = (int)ManagementJob::createFromBuild($machine->key, 'host_report', JobCommandBuilder::build_host_report($machine), null, $user_id)->key;
		if (trim((string)$node->get('mgn_container_name')) !== '') {
			$jobs[] = (int)ManagementJob::createFromBuild($machine->key, 'container_inventory',
				JobCommandBuilder::build_container_inventory($machine), null, $user_id)->key;
		}
		return $jobs;
	}
}
