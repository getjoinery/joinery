<?php
/**
 * SandboxConsent — what an admin agreed a sandboxed plugin may do.
 *
 * A sandboxed plugin can do only what its manifest declares, and only once an
 * admin has seen that in plain words and approved it. The approval is kept
 * (SandboxInstall::sbi_consent) as the grants it covered. From then on:
 *
 *   - what the plugin may do is what it asks for AND was approved
 *     (effective()): an upgraded manifest asking for more changes nothing
 *     until it is approved again;
 *   - activation refuses while the manifest asks for anything not approved
 *     (needsApproval()), naming it.
 *
 * Grants (grantsOf()) are what a module can reach beyond itself: API actions
 * and other plugins' or core records (door 1), signals (door 2), scheduled
 * runs (door 3), outbound hosts (door 5), and pages it adds to members' menus
 * (door 4: a member page's form asks members for whatever it likes, and the
 * answers go to the module). Its own models, admin pages and settings are its
 * own, shown but not separately granted.
 *
 * An approval stands while the admin who gave it can: a superadmin, not
 * disabled or deleted. Once they cannot, the plugin's credential is switched
 * off (User::save()) and the plugin waits on another admin's approval.
 *
 * @version 1.1 - member pages are a grant; approve() takes the digest of what the admin was shown;
 *                an approval lapses when its admin is no longer a superadmin
 * @version 1.0
 */

class SandboxConsent {

	/**
	 * What a manifest asks for, in a canonical shape two manifests can be
	 * compared in.
	 *
	 * @return array{actions:string[], records:array<string,string[]>, signals:string[],
	 *               schedules:array<string,string>, outbound_hosts:string[]}
	 */
	public static function grantsOf(SandboxManifest $m): array {
		$actions = $m->permittedActions();
		sort($actions);
		$records = array();
		foreach ($m->permittedRecords() as $model => $verbs) {
			$verbs = array_values(array_unique($verbs));
			sort($verbs);
			$records[$model] = $verbs;
		}
		ksort($records);
		$signals = $m->signals();
		sort($signals);
		$schedules = array();
		foreach ($m->schedules() as $s) {
			$schedules[$s['name']] = self::scheduleWords($s);
		}
		ksort($schedules);
		$hosts = $m->outboundHosts();
		sort($hosts);
		$member_pages = array();
		foreach ($m->profilePages() as $page) {
			$has_form = false;
			foreach ($page['sections'] as $section) {
				$has_form = $has_form || $section['type'] === 'form';
			}
			$member_pages[$page['slug']] = $page['title'] . ($has_form ? ' (with a form members fill in)' : '');
		}
		ksort($member_pages);
		return array(
			'actions'        => $actions,
			'records'        => $records,
			'signals'        => $signals,
			'schedules'      => $schedules,
			'outbound_hosts' => $hosts,
			'member_pages'   => $member_pages,
		);
	}

	/**
	 * A fingerprint of what the approval page shows for this manifest, which
	 * the page posts back: an approval is of what the admin saw, so a
	 * plugin replaced between showing and approving is not approved unseen.
	 */
	public static function reviewDigest(SandboxManifest $m): string {
		return hash('sha256', json_encode(array($m->version(), self::grantsOf($m), $m->adminPages(), $m->settings())));
	}

	/**
	 * What $asked holds that $approved does not, in the same shape, with
	 * empty kinds left out. Empty when the approval covers everything.
	 */
	public static function additions(array $asked, array $approved): array {
		$out = array();
		foreach (array('actions', 'signals', 'outbound_hosts') as $kind) {
			$more = array_values(array_diff($asked[$kind] ?? array(), $approved[$kind] ?? array()));
			if ($more) {
				$out[$kind] = $more;
			}
		}
		foreach ($asked['records'] ?? array() as $model => $verbs) {
			$more = array_values(array_diff($verbs, $approved['records'][$model] ?? array()));
			if ($more) {
				$out['records'][$model] = $more;
			}
		}
		foreach ($asked['schedules'] ?? array() as $name => $words) {
			// A schedule that runs at another time is the same reach; a new
			// schedule is more.
			if (!isset($approved['schedules'][$name])) {
				$out['schedules'][$name] = $words;
			}
		}
		foreach ($asked['member_pages'] ?? array() as $slug => $title) {
			// A page that gains a form asks members for more than it did.
			if (($approved['member_pages'][$slug] ?? null) !== $title) {
				$out['member_pages'][$slug] = $title;
			}
		}
		return $out;
	}

	/**
	 * What the plugin may do now: what its manifest asks for that the
	 * approval covers. Nothing at all with no approval.
	 */
	public static function effective(string $plugin): array {
		$none = array('actions' => array(), 'records' => array(), 'signals' => array(), 'schedules' => array(), 'outbound_hosts' => array(), 'member_pages' => array());
		try {
			$asked = self::grantsOf(SandboxPlugins::manifest($plugin));
		} catch (SandboxManifestException $e) {
			return $none;
		}
		$row = SandboxInstall::forPlugin($plugin);
		if ($row === null || !$row->get('sbi_consent_time')) {
			return $none;
		}
		$approved = $row->consent();
		$out = $none;
		foreach (array('actions', 'signals', 'outbound_hosts') as $kind) {
			$out[$kind] = array_values(array_intersect($asked[$kind], $approved[$kind] ?? array()));
		}
		foreach ($asked['records'] as $model => $verbs) {
			$kept = array_values(array_intersect($verbs, $approved['records'][$model] ?? array()));
			if ($kept) {
				$out['records'][$model] = $kept;
			}
		}
		foreach ($asked['schedules'] as $name => $words) {
			if (isset($approved['schedules'][$name])) {
				$out['schedules'][$name] = $words;
			}
		}
		foreach ($asked['member_pages'] as $slug => $title) {
			if (($approved['member_pages'][$slug] ?? null) === $title) {
				$out['member_pages'][$slug] = $title;
			}
		}
		return $out;
	}

	/** What the manifest asks for beyond the approval, or everything with none. */
	public static function pending(string $plugin): array {
		$asked = self::grantsOf(SandboxPlugins::manifest($plugin));
		$row = SandboxInstall::forPlugin($plugin);
		if ($row === null || !$row->get('sbi_consent_time')) {
			return $asked;
		}
		return self::additions($asked, $row->consent());
	}

	/**
	 * Whether the plugin waits on an admin's approval before it may (keep)
	 * run(ning) as asked: none was given, the manifest asks for more, or the
	 * admin who gave it is gone (its credential acts as them).
	 */
	public static function needsApproval(string $plugin): bool {
		$row = SandboxInstall::forPlugin($plugin);
		if ($row === null || !$row->get('sbi_consent_time') || self::approver($row) === null) {
			return true;
		}
		return self::pending($plugin) !== array();
	}

	/** The permission an approval needs, and keeps needing (the approval page's own). */
	const APPROVER_PERMISSION = 10;

	/**
	 * The admin whose approval stands, or null when they can no longer give
	 * it: gone, disabled, or no longer a superadmin.
	 */
	public static function approver(SandboxInstall $row): ?User {
		if (!$row->get('sbi_usr_user_id')) {
			return null;
		}
		$user = new User((int)$row->get('sbi_usr_user_id'), TRUE);
		if (!$user->key || $user->get('usr_delete_time') || $user->get('usr_is_disabled')
				|| (int)$user->get('usr_permission') < self::APPROVER_PERMISSION) {
			return null;
		}
		return $user;
	}

	/**
	 * Record an admin's approval of everything the manifest asks for now.
	 * Its credential will act as them. $reviewed is reviewDigest() of the
	 * manifest the admin was shown, always: an approval is of something seen,
	 * and when it no longer matches, nothing is approved.
	 *
	 * @throws SandboxManifestException when the plugin changed since it was reviewed
	 */
	public static function approve(string $plugin, User $by, string $reviewed): SandboxInstall {
		$manifest = SandboxPlugins::manifest($plugin);
		if (!hash_equals(self::reviewDigest($manifest), $reviewed)) {
			throw new SandboxManifestException('The plugin changed since you reviewed it. Review what it asks for again.');
		}
		$row = SandboxInstall::forPlugin($plugin);
		if ($row === null) {
			$row = new SandboxInstall(NULL);
			$row->set('sbi_plugin', $plugin);
		}
		$row->set('sbi_consent', self::grantsOf($manifest));
		$row->set('sbi_consented_version', $manifest->version());
		$row->set('sbi_usr_user_id', (int)$by->key);
		$row->set('sbi_consent_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		return $row;
	}

	/**
	 * Grants in plain words, grouped as the install screen shows them (Q2):
	 * heading => lines. Records are grouped by the feature that owns the
	 * model, each model's verbs on one line.
	 *
	 * @return array<string,string[]>
	 */
	public static function describe(array $grants): array {
		$out = array();
		if (!empty($grants['records'])) {
			$groups = array();
			foreach ($grants['records'] as $model => $verbs) {
				$groups[self::featureOf($model)][] = self::modelWords($model) . ': ' . self::verbWords($verbs);
			}
			ksort($groups);
			foreach ($groups as $feature => $lines) {
				$out['Records it may use: ' . $feature] = $lines;
			}
		}
		if (!empty($grants['actions'])) {
			$catalog = ApiLogicEndpoint::actionCatalog();
			$lines = array();
			foreach ($grants['actions'] as $action) {
				$description = trim((string)($catalog[$action]['meta']['description'] ?? ''));
				$lines[] = $description !== '' ? $description . ' (' . $action . ')' : $action;
			}
			$out['Run these site actions'] = $lines;
		}
		if (!empty($grants['signals'])) {
			$catalog = SignalBus::signals();
			$lines = array();
			foreach ($grants['signals'] as $signal) {
				$label = (string)($catalog[$signal]['label'] ?? '');
				$line = $label !== '' ? $label . ' (' . $signal . ')' : $signal;
				// What personal data the plugin would be handed with it.
				$personal = trim((string)($catalog[$signal]['personal_data'] ?? ''));
				$lines[] = $personal !== '' ? $line . '. It carries: ' . lcfirst($personal) : $line;
			}
			$out['Be told when these happen'] = $lines;
		}
		if (!empty($grants['schedules'])) {
			$lines = array();
			foreach ($grants['schedules'] as $name => $words) {
				$lines[] = $name . ': ' . $words;
			}
			$out['Run on a schedule'] = $lines;
		}
		if (!empty($grants['outbound_hosts'])) {
			$out['Send data to these websites'] = $grants['outbound_hosts'];
		}
		if (!empty($grants['member_pages'])) {
			$out["Add these pages to members' menus"] = array_values($grants['member_pages']);
		}
		return $out;
	}

	private static function scheduleWords(array $s): string {
		switch ($s['frequency']) {
			case 'hourly': return 'every hour';
			case 'daily':  return 'every day at ' . $s['time'];
			case 'weekly':
				$days = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
				return 'every ' . $days[(int)$s['day_of_week']] . ' at ' . $s['time'];
		}
		return (string)$s['frequency'];
	}

	private static function verbWords(array $verbs): string {
		$words = array('read' => 'read', 'create' => 'add', 'update' => 'change', 'delete' => 'delete');
		$said = array();
		foreach (ApiKey::RECORD_VERBS as $verb) {
			if (in_array($verb, $verbs, true)) {
				$said[] = $words[$verb];
			}
		}
		if ($said === array('read')) {
			return 'read only';
		}
		$last = array_pop($said);
		return $said ? implode(', ', $said) . ' and ' . $last : $last;
	}

	/**
	 * 'EventRegistrant' => 'All event registrants': the credential acts as an
	 * admin, so a grant reaches every record of the model.
	 */
	private static function modelWords(string $model): string {
		if (ApiKey::is_model_class($model) && is_subclass_of($model, 'SandboxRecord')) {
			$model = substr($model, strrpos($model, '_') + 1);
		}
		$words = strtolower(preg_replace('~(?<!^)[A-Z]~', ' $0', $model));
		return 'All ' . $words . 's';
	}

	/** The feature a model belongs to: its plugin's title, or the site core. */
	private static function featureOf(string $model): string {
		if (!ApiKey::is_model_class($model)) {
			return 'Unknown';
		}
		// Another sandboxed plugin's model: its class is made at run time,
		// from no file of its own.
		if (is_subclass_of($model, 'SandboxRecord')) {
			$owner = $model::sandboxPlugin();
			try {
				return SandboxPlugins::manifest($owner)->title();
			} catch (SandboxManifestException $e) {
				return $owner;
			}
		}
		$file = (new ReflectionClass($model))->getFileName();
		if ($file && preg_match('~/plugins/([^/]+)/data/~', $file, $m)) {
			$meta = Plugin::get_by_plugin_name($m[1]);
			$info = $meta ? $meta->get_plugin_metadata() : null;
			return (string)($info['name'] ?? $m[1]);
		}
		return 'Site core';
	}
}
