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
 * Grants (grantsOf()) are what a plugin can reach beyond itself: API actions
 * and other plugins' or core records (door 1), signals (door 2), scheduled
 * runs (door 3), outbound hosts (door 5), sending mail (door 6), and pages it adds to members' menus
 * (door 4: a member page's form asks members for whatever it likes, and the
 * answers go to the plugin). Its own models, admin pages and settings are its
 * own, shown but not separately granted.
 *
 * An approval stands while the admin who gave it can: a superadmin, not
 * disabled or deleted. Once they cannot, the plugin's credential is switched
 * off (User::save()) and the plugin waits on another admin's approval.
 *
 * @version 1.14 - blockEntries(): a prevent between the plugin's own models is a block when a site record's delete
 *                 reaches it through permanent_delete rules (reviewer1 R6)
 * @version 1.13 - a model's reach says the levels that result (read through the API, delete, permanent delete), so a
 *                 change of its class or a level is a new entry (reviewer1 R2); table words from the name alone
 * @version 1.12 - its models (spec sandboxed_plugin_model_files section 7): model_reach, each way a model is open beyond
 *                 the plugin (read through the API, a read or delete level below its class's, each member's own rows),
 *                 and blocks, each prevent on a site table, are grants; effectiveModels() reads them from the stored
 *                 declarations, never the manifest; the digest covers the models shown
 * @version 1.11 - public pages (spec sandboxed_plugin_public_pages): each is a grant, its own kind, described as
 *                 pages anyone can open, signed in or not
 * @version 1.10 - the File grant line says Drive files are read only; the uploads line no longer says "never other files"
 *                 (reviewer2 B1, B3)
 * @version 1.9 - a records grant on File says what it reaches: every file, read, renamed, trashed
 * @version 1.8 - uploads (door 7): accept_uploads is a grant, described on the approval screen; the warning names
 *                the files people upload beside personal records (spec sandboxed_plugin_uploads)
 * @version 1.7 - email (door 6): send_email is a grant, described on the approval screen; the warning names it
 *                beside outside websites, as another way out for personal records (spec sandboxed_plugin_email)
 * @version 1.6 - an own-rows line reads "each member's own notifications", not "own All notifications"
 * @version 1.5 - framed pages: a framed member page is its own ask; a framed admin page is a grant (design 12)
 * @version 1.4 - own_records, its own kind in grantsOf(), additions() and effective(); the approval screen warns
 *                when personal records and outside websites go together (design 8)
 * @version 1.3 - a member page drawn by the plugin's view says so on the approval screen
 * @version 1.2 - a record grant on a native plugin's model also names that plugin's program code, which the
 *                fence lets the plugin read (reviewer2 G5)
 * @version 1.1 - member pages are a grant; approve() takes the digest of what the admin was shown;
 *                an approval lapses when its admin is no longer a superadmin
 * @version 1.0
 */

class SandboxConsent {

	/** The kinds of page grant: a page by its slug, approved by its title and words. */
	const PAGE_KINDS = array('member_pages', 'public_pages', 'framed_admin_pages');

	/**
	 * What a manifest asks for, in a canonical shape two manifests can be
	 * compared in.
	 *
	 * @return array{actions:string[], records:array<string,string[]>, signals:string[],
	 *               schedules:array<string,string>, outbound_hosts:string[], email:string[], uploads:string[]}
	 */
	public static function grantsOf(SandboxManifest $m, ?array $models = null): array {
		$models = $models ?? SandboxModels::declarations($m->name());
		$actions = $m->permittedActions();
		sort($actions);
		$records = array();
		foreach ($m->permittedRecords() as $model => $verbs) {
			$verbs = array_values(array_unique($verbs));
			sort($verbs);
			$records[$model] = $verbs;
		}
		ksort($records);
		$own_records = array();
		foreach ($m->permittedOwnRecords() as $model => $verbs) {
			$verbs = array_values(array_unique($verbs));
			sort($verbs);
			$own_records[$model] = $verbs;
		}
		ksort($own_records);
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
			$member_pages[$page['slug']] = $page['title'] . self::pageWords($page, 'members');
		}
		ksort($member_pages);
		// A page anyone can open, signed in or not: a grant, described as a
		// member page is, since it shows and asks the same things of anyone.
		$public_pages = array();
		foreach ($m->publicPages() as $page) {
			$public_pages[$page['slug']] = $page['title'] . self::pageWords($page, 'visitors');
		}
		ksort($public_pages);
		// A framed admin page runs the plugin's script in an admin's browser:
		// a grant, unlike a plain admin page (design 12).
		$framed_admin_pages = array();
		foreach ($m->adminPages() as $page) {
			if (!empty($page['frame'])) {
				$framed_admin_pages[$page['slug']] = $page['title'];
			}
		}
		ksort($framed_admin_pages);
		return array(
			'actions'        => $actions,
			'records'        => $records,
			'own_records'    => $own_records,
			'signals'        => $signals,
			'schedules'      => $schedules,
			'outbound_hosts' => $hosts,
			// A list, so it compares like the others: ['send'] or nothing.
			'email'          => $m->sendsEmail() ? array('send') : array(),
			'uploads'        => $m->acceptsUploads() ? array('accept') : array(),
			'member_pages'   => $member_pages,
			'public_pages'   => $public_pages,
			'framed_admin_pages' => $framed_admin_pages,
		) + self::modelGrants($m->name(), $models);
	}

	/** The list kinds every approval is compared in, a value each. */
	const LIST_KINDS = array('actions', 'signals', 'outbound_hosts', 'email', 'uploads', 'model_reach', 'blocks');

	/**
	 * What a plugin's models open beyond the plugin, from their checked
	 * declarations: model_reach, each way a model is open (readable through
	 * the API, a read or delete level below its class's, each member their
	 * own rows), and blocks, each prevent on a table that is not the
	 * plugin's own. cascade, null and set_value change only the plugin's own
	 * rows and need no consent. Each entry is the words the approval page
	 * shows, so one that changes is new.
	 *
	 * @return array{model_reach:string[], blocks:string[]}
	 */
	public static function modelGrants(string $plugin, array $models): array {
		$reach = array();
		$blocks = array();
		foreach ($models as $name => $declaration) {
			foreach (array_keys(self::modelReachOf((string)$name, $declaration)) as $entry) {
				$reach[] = $entry;
			}
		}
		foreach (self::blockEntries($plugin, $models) as $columns) {
			foreach ($columns as $entries) {
				$blocks = array_merge($blocks, $entries);
			}
		}
		$blocks = array_values(array_unique($blocks));
		sort($reach);
		sort($blocks);
		return array('model_reach' => $reach, 'blocks' => $blocks);
	}

	/**
	 * Each way one model is open beyond its plugin: entry (the words) =>
	 * array(kind, value), kind one of api, read_permission, delete_permission,
	 * permanent_delete_permission, owner.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public static function modelReachOf(string $name, array $declaration): array {
		$words = SandboxModelDeclarations::words($name);
		$out = array();
		// Each entry says the level that results, from the model's class and
		// its own statics together: a change to either (personal to content,
		// say) is a new entry, so it waits on an approval (reviewer1 R2).
		// Reading reaches past the plugin only through the API, so the read
		// level and an owner column open something only there; deleting is
		// open to staff whatever the API flag (section 8).
		$readable = ($declaration['api_readable'] ?? false) === true;
		if ($readable) {
			$out["Its $words records can be read through the site's API by staff at level " . self::levelOf($declaration, 'read_permission') . ' and above']
				= array('api', true);
			$owner = self::ownerOf($declaration);
			if ($owner !== null) {
				$out["Each member can read their own $words records through the API (the member named in $owner)"] = array('owner', $owner);
			}
		}
		foreach (array('delete_permission' => 'deleted', 'permanent_delete_permission' => 'permanently deleted') as $static => $verb) {
			$out["Its $words records can be $verb by staff at level " . self::levelOf($declaration, $static) . ' and above']
				= array($static, self::levelOf($declaration, $static));
		}
		return $out;
	}

	/** The level a model needs for a verb: its own static, else its class's. */
	public static function levelOf(array $declaration, string $static): int {
		$level = $declaration[$static] ?? null;
		return is_int($level) ? $level : self::classLevel($declaration, $static);
	}

	private static function classLevel(array $declaration, string $static): int {
		$verb = array_search($static, SystemBase::ACCESS_EXCEPTIONS, true);
		return Access::level((string)($declaration['access_class'] ?? 'keys') . ':' . $verb);
	}

	/** The column that names a row's member, as SystemBase::access_owner_column() finds it, or null. */
	public static function ownerOf(array $declaration): ?string {
		$owner = $declaration['owner_column'] ?? null;
		if ($owner === false) {
			return null;
		}
		if (is_string($owner)) {
			return $owner;
		}
		$conventional = $declaration['prefix'] . '_usr_user_id';
		return isset($declaration['field_specifications'][$conventional]) ? $conventional : null;
	}

	/**
	 * Each prevent of the plugin's that can stop a site record being
	 * permanently deleted, in words: model => column => entries.
	 *
	 * A prevent on a site table blocks that table's record. A prevent on one
	 * of the plugin's own tables blocks a site record too when that table's
	 * rows are themselves removed with the site record: a permanent_delete
	 * rule from the site table, directly or through a chain of the plugin's
	 * own permanent_delete rules, deletes each row with its own rules, and
	 * the prevent refuses (reviewer1 R6). A cascade, null or set_value runs
	 * as SQL and consults no rule of the rows it touches, so it cannot carry
	 * a block. A prevent that no site record's delete can reach is the
	 * plugin's own business and needs no consent.
	 *
	 * @return array<string,array<string,string[]>>
	 */
	public static function blockEntries(string $plugin, array $models): array {
		$own = array();
		foreach ($models as $name => $declaration) {
			$own[(string)($declaration['table'] ?? '')] = (string)$name;
		}
		// own table => the site tables whose delete removes its rows
		$removed = array();
		for ($pass = 0; $pass <= count($models); $pass++) {
			$grew = false;
			foreach ($models as $declaration) {
				$table = (string)($declaration['table'] ?? '');
				foreach ((array)($declaration['foreign_key_actions'] ?? array()) as $rule) {
					if (($rule['action'] ?? null) !== 'permanent_delete') {
						continue;
					}
					$source = (string)($rule['source_table'] ?? '');
					$sites = isset($own[$source]) ? array_keys($removed[$source] ?? array()) : array($source);
					foreach ($sites as $site) {
						if (!isset($removed[$table][$site])) {
							$removed[$table][$site] = true;
							$grew = true;
						}
					}
				}
			}
			if (!$grew) {
				break;
			}
		}
		$out = array();
		foreach ($models as $name => $declaration) {
			$words = SandboxModelDeclarations::words((string)$name);
			foreach ((array)($declaration['foreign_key_actions'] ?? array()) as $column => $rule) {
				if (!is_array($rule) || ($rule['action'] ?? null) !== 'prevent') {
					continue;
				}
				$source = (string)($rule['source_table'] ?? '');
				if (!isset($own[$source])) {
					$out[(string)$name][(string)$column][] = 'A ' . self::tableWords($source) . ' cannot be permanently deleted while it holds '
						. $words . " records for them ($column)";
					continue;
				}
				foreach (array_keys($removed[$source] ?? array()) as $site) {
					$out[(string)$name][(string)$column][] = 'A ' . self::tableWords($site) . ' cannot be permanently deleted while its '
						. SandboxModelDeclarations::words($own[$source]) . " records hold $words records ($column)";
				}
			}
		}
		return $out;
	}

	/** Whether every block a rule carries is approved (one that carries none needs nothing). */
	public static function ruleApproved(array $entries, array $approved_blocks): bool {
		foreach ($entries as $entry) {
			if (!in_array($entry, $approved_blocks, true)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One line a model for the approval page: who may read it and what it is
	 * linked to, from its declaration.
	 *
	 * @return string[]
	 */
	public static function modelLines(string $plugin, array $models): array {
		$own = array();
		foreach ($models as $name => $declaration) {
			$own[(string)($declaration['table'] ?? '')] = SandboxModelDeclarations::words((string)$name);
		}
		$lines = array();
		foreach ($models as $name => $declaration) {
			$words = SandboxModelDeclarations::words((string)$name);
			$read = array('staff (level ' . self::levelOf($declaration, 'read_permission') . ' and above)');
			if (($declaration['api_readable'] ?? false) === true) {
				if (self::ownerOf($declaration) !== null) {
					$read[] = 'each member their own';
				}
				$read[] = 'also through the API';
			}
			$links = array();
			foreach ((array)($declaration['foreign_key_actions'] ?? array()) as $rule) {
				$table = (string)($rule['source_table'] ?? '');
				$target = $own[$table] ?? self::tableWords($table);
				switch ($rule['action'] ?? '') {
					case 'cascade':
					case 'permanent_delete':
						$links[] = "removed with its $target";
						break;
					case 'null':
						$links[] = "kept when its $target is deleted";
						break;
					case 'set_value':
						$links[] = "kept, pointed elsewhere, when its $target is deleted";
						break;
					case 'prevent':
						$links[] = "can stop its $target being permanently deleted";
						break;
				}
			}
			$lines[] = ucfirst($words) . ' records: read by ' . implode(', ', $read) . '.'
				. ($links ? ' ' . ucfirst(implode('; ', array_unique($links))) . '.' : '');
		}
		return $lines;
	}

	/**
	 * 'usr_users' => 'user': a site table's record, in words, from its name
	 * alone. Never the model registry: this runs while a sandboxed class is
	 * being filled, which the registry's own discovery does.
	 */
	private static function tableWords(string $table): string {
		if (!preg_match('~^[a-z0-9]+_([a-z0-9_]+)$~D', $table, $m)) {
			return 'record in ' . $table;
		}
		$stem = $m[1];
		if (substr($stem, -3) === 'ies') {
			$stem = substr($stem, 0, -3) . 'y';
		} elseif (substr($stem, -1) === 's' && substr($stem, -2) !== 'ss') {
			$stem = substr($stem, 0, -1);
		}
		return str_replace('_', ' ', $stem);
	}

	/**
	 * What the plugin's models may open now: their reach and blocks that the
	 * approval covers. Read from the stored declarations and the approval
	 * alone, never the manifest, so a rule an admin approved stays while the
	 * plugin's files are unreadable.
	 *
	 * @return array{model_reach:string[], blocks:string[]}
	 */
	public static function effectiveModels(string $plugin): array {
		$none = array('model_reach' => array(), 'blocks' => array());
		$row = SandboxInstall::forPlugin($plugin);
		if ($row === null || !$row->get('sbi_consent_time')) {
			return $none;
		}
		$asked = self::modelGrants($plugin, SandboxModels::declarations($plugin));
		$approved = $row->consent();
		return array(
			'model_reach' => array_values(array_intersect($asked['model_reach'], $approved['model_reach'] ?? array())),
			'blocks'      => array_values(array_intersect($asked['blocks'], $approved['blocks'] ?? array())),
		);
	}

	/**
	 * A fingerprint of what the approval page shows for this manifest, which
	 * the page posts back: an approval is of what the admin saw, so a
	 * plugin replaced between showing and approving is not approved unseen.
	 */
	public static function reviewDigest(SandboxManifest $m, ?array $models = null): string {
		$models = $models ?? SandboxModels::declarations($m->name());
		return hash('sha256', json_encode(array($m->version(), self::grantsOf($m, $models), $m->adminPages(), $m->settings(), $models)));
	}

	/**
	 * What $asked holds that $approved does not, in the same shape, with
	 * empty kinds left out. Empty when the approval covers everything.
	 */
	public static function additions(array $asked, array $approved): array {
		$out = array();
		foreach (self::LIST_KINDS as $kind) {
			$more = array_values(array_diff($asked[$kind] ?? array(), $approved[$kind] ?? array()));
			if ($more) {
				$out[$kind] = $more;
			}
		}
		foreach (array('records', 'own_records') as $kind) {
			foreach ($asked[$kind] ?? array() as $model => $verbs) {
				$more = array_values(array_diff($verbs, $approved[$kind][$model] ?? array()));
				if ($more) {
					$out[$kind][$model] = $more;
				}
			}
		}
		foreach ($asked['schedules'] ?? array() as $name => $words) {
			// A schedule that runs at another time is the same reach; a new
			// schedule is more.
			if (!isset($approved['schedules'][$name])) {
				$out['schedules'][$name] = $words;
			}
		}
		foreach (self::PAGE_KINDS as $kind) {
			foreach ($asked[$kind] ?? array() as $slug => $title) {
				// A page that gains a form, or becomes framed, asks for more than it did.
				if (($approved[$kind][$slug] ?? null) !== $title) {
					$out[$kind][$slug] = $title;
				}
			}
		}
		return $out;
	}

	/**
	 * What the plugin may do now: what its manifest asks for that the
	 * approval covers. Nothing at all with no approval.
	 */
	public static function effective(string $plugin): array {
		$none = array('actions' => array(), 'records' => array(), 'own_records' => array(), 'signals' => array(), 'schedules' => array(), 'outbound_hosts' => array(), 'email' => array(), 'uploads' => array(), 'member_pages' => array(), 'public_pages' => array(), 'framed_admin_pages' => array(), 'model_reach' => array(), 'blocks' => array());
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
		foreach (self::LIST_KINDS as $kind) {
			$out[$kind] = array_values(array_intersect($asked[$kind], $approved[$kind] ?? array()));
		}
		foreach (array('records', 'own_records') as $kind) {
			foreach ($asked[$kind] ?? array() as $model => $verbs) {
				$kept = array_values(array_intersect($verbs, $approved[$kind][$model] ?? array()));
				if ($kept) {
					$out[$kind][$model] = $kept;
				}
			}
		}
		foreach ($asked['schedules'] as $name => $words) {
			if (isset($approved['schedules'][$name])) {
				$out['schedules'][$name] = $words;
			}
		}
		foreach (self::PAGE_KINDS as $kind) {
			foreach ($asked[$kind] ?? array() as $slug => $title) {
				if (($approved[$kind][$slug] ?? null) === $title) {
					$out[$kind][$slug] = $title;
				}
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
				|| !Access::allows(self::APPROVER_PERMISSION, (int)$user->get('usr_permission'))) {
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
		// The model files as they are now (a POST): what the admin was shown.
		$models = SandboxModels::refresh($plugin);
		if (!hash_equals(self::reviewDigest($manifest, $models), $reviewed)) {
			throw new SandboxManifestException('The plugin changed since you reviewed it. Review what it asks for again.');
		}
		$row = SandboxInstall::forPlugin($plugin);
		if ($row === null) {
			$row = new SandboxInstall(NULL);
			$row->set('sbi_plugin', $plugin);
		}
		$row->set('sbi_consent', self::grantsOf($manifest, $models));
		$row->set('sbi_consented_version', $manifest->version());
		$row->set('sbi_usr_user_id', (int)$by->key);
		$row->set('sbi_consent_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		// What was approved is what the site now uses.
		SandboxModels::storePending($plugin);
		SandboxModels::reset();
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
				if ($model === 'File') {
					// What a File grant is (SandboxRelay::fileReach()): every file, and a change is a new title.
					$words = array_values(array_intersect_key(array('read' => 'read their contents', 'update' => 'rename them',
						'delete' => 'put them in the trash'), array_flip($verbs)));
					$groups[self::featureOf($model)][] = "Every file on the site, every member's (Drive files and mail attachments among them): "
						. implode(', ', $words) . (array_intersect($verbs, array('update', 'delete')) ? ' (Drive files are read only: they change only through Drive)' : '');
					continue;
				}
				$groups[self::featureOf($model)][] = self::modelWords($model) . ': ' . self::verbWords($verbs);
			}
			ksort($groups);
			foreach ($groups as $feature => $lines) {
				$out['Records it may use: ' . $feature] = $lines;
			}
			// Its code needs the model classes of each native plugin it reads
			// through, so it can read that plugin's program code too.
			$code = array();
			foreach (array_keys($grants['records']) as $model) {
				$owner = class_exists('SandboxRunner') ? SandboxRunner::nativeOwnerOf((string)$model) : null;
				if ($owner !== null) {
					$code[$owner] = 'The program code of the ' . self::featureOf((string)$model) . ' plugin (its includes and data folders)';
				}
			}
			if ($code) {
				ksort($code);
				$out['Read this program code'] = array_values($code);
			}
		}
		if (!empty($grants['own_records'])) {
			$lines = array();
			foreach ($grants['own_records'] as $model => $verbs) {
				$lines[] = "Each member's own " . self::pluralWords((string)$model) . ': ' . self::verbWords($verbs)
					. ' (only the rows of the member viewing the page)';
			}
			$out["Each member's own records"] = $lines;
		}
		// The combination to say out loud (design 8): personal records it may
		// read, and a way to send them off the site (websites, or mail).
		$personal = false;
		foreach (array_keys($grants['records'] ?? array()) as $model) {
			$personal = $personal || (class_exists((string)$model) && is_subclass_of((string)$model, 'SystemBase') && !$model::$api_public_read);
		}
		$ways_out = array();
		if (!empty($grants['outbound_hosts'])) {
			$ways_out[] = 'send data to outside websites';
		}
		if (!empty($grants['email'])) {
			$ways_out[] = 'send email to any address';
		}
		$what = array();
		if ($personal || !empty($grants['own_records'])) {
			$what[] = 'personal records';
		}
		if (!empty($grants['uploads'])) {
			$what[] = 'the files people upload to it';
		}
		if ($what && $ways_out) {
			$out['Warning'] = array('It can read ' . implode(' and ', $what) . ' and ' . implode(' and ', $ways_out) . ', so it could send them off the site.');
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
		if (!empty($grants['email'])) {
			// What is true: the site's mail service sends it as native code's
			// mail, so any sender address that service accepts, except a
			// protected mailbox domain; and it fills the site's stored
			// templates (reviewer2 P1, P7c).
			$out['Send email'] = array("To any address, through the site's mail service, from any sender address that service accepts (never a protected mailbox domain), using the site's email templates");
		}
		if (!empty($grants['uploads'])) {
			// What is true: the file is the viewer's, private; its reach is the
			// files it kept (spec sandboxed_plugin_uploads, deviations 1 and 2).
			$out['Accept uploaded files'] = array("On its own pages, from the person viewing. Each file is kept in that person's account, private to them and admins. It can read, rename and remove the files it kept");
		}
		if (!empty($grants['model_reach'])) {
			$out['Open its own records beyond the plugin'] = $grants['model_reach'];
		}
		if (!empty($grants['blocks'])) {
			$out["Stop the site's records being deleted while it holds rows for them (an admin can see and remove those rows)"] = $grants['blocks'];
		}
		if (!empty($grants['framed_admin_pages'])) {
			$out["Run its own script in admins' browsers, on these framed admin pages"] = array_values($grants['framed_admin_pages']);
		}
		if (!empty($grants['member_pages'])) {
			$out["Add these pages to members' menus"] = array_values($grants['member_pages']);
		}
		if (!empty($grants['public_pages'])) {
			$out['Pages anyone can open, signed in or not. What they show and do runs with the access approved here']
				= array_values($grants['public_pages']);
		}
		return $out;
	}

	/**
	 * What a page is, after its title on the approval screen: framed, drawn
	 * by the plugin, or with a form $who fill in. The words are part of the
	 * grant, so a page that changes kind asks again.
	 */
	private static function pageWords(array $page, string $who): string {
		if (!empty($page['frame'])) {
			return ' (a framed page running the plugin\'s own script, walled off from the site)';
		}
		if (!isset($page['sections'])) {
			// Drawn by its own code: it may show what it reads and ask anything.
			return ' (drawn by the plugin, which may show what it can read and ask ' . $who . ' for anything)';
		}
		foreach ($page['sections'] as $section) {
			if ($section['type'] === 'form') {
				return ' (with a form ' . $who . ' fill in)';
			}
		}
		return '';
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
		return 'All ' . self::pluralWords($model);
	}

	/** A model class's name as plain plural words: 'EventRegistration' => 'event registrations'. */
	private static function pluralWords(string $model): string {
		return strtolower(preg_replace('~(?<!^)[A-Z]~', ' $0', $model)) . 's';
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
