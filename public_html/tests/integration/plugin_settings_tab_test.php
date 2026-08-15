<?php
/** @joinery-test
 * name: plugin_settings_tab
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Plugin Settings tab — the admin page where plugin-owned settings are
 * administered (specs/plugin_settings_tab.md).
 *
 * The invariants worth guarding:
 *
 *   A. Discovery. Only an ACTIVE plugin that DECLARES a renderable setting gets
 *      a section, and the tab itself disappears when nothing is behind it.
 *
 *   B. One tab list. Four pages render the settings tab strip; they must all
 *      render the SAME set, which only holds while they share one definition.
 *
 *   C. Rendered is declared. Every field the shared renderer emits comes from a
 *      manifest, so a field cannot render, accept typing and silently fail to
 *      save. This is the check done by rendering rather than by grepping, which
 *      is why it is trustworthy where a source sweep is not.
 *
 *   D. Write scope. A save writes ONLY the submitting plugin's declared
 *      settings: not a core row, not a sibling plugin's row, and not a row of a
 *      plugin that named itself but ships no form.
 *
 *   E. The vault gate survives the move. All four vault-gated setting names are
 *      mailbox's, and mailbox's fields live on this page, so this logic is now
 *      the only path that can change them.
 *
 * Run: php tests/integration/plugin_settings_tab_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
require_once(PathHelper::getIncludePath('includes/VaultGatedSettings.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_settings_plugins_logic.php'));

$db = DbConnector::get_instance()->get_db_link();

/**
 * Snapshot a settings row and defer a full restore — value, update time and
 * acting user, so a test that writes leaves no trace in the audit columns
 * either. Runs on a crash exit too (harness_defer contract).
 */
$protect = function ($name) use ($db) {
	$read = $db->prepare("SELECT stg_value, stg_update_time, stg_usr_user_id FROM stg_settings WHERE stg_name = ?");
	$read->execute(array($name));
	$row = $read->fetch(PDO::FETCH_ASSOC);
	if (!$row) {
		harness_defer(function () use ($db, $name) {
			$db->prepare("DELETE FROM stg_settings WHERE stg_name = ?")->execute(array($name));
		});
		return null;
	}
	harness_defer(function () use ($db, $name, $row) {
		$db->prepare("UPDATE stg_settings SET stg_value = ?, stg_update_time = ?, stg_usr_user_id = ? WHERE stg_name = ?")
		   ->execute(array($row['stg_value'], $row['stg_update_time'], $row['stg_usr_user_id'], $name));
	});
	return $row;
};

$value_of = function ($name) use ($db) {
	$read = $db->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
	$read->execute(array($name));
	$v = $read->fetchColumn();
	return $v === false ? null : $v;
};

$stamp_of = function ($name) use ($db) {
	$read = $db->prepare("SELECT stg_update_time FROM stg_settings WHERE stg_name = ?");
	$read->execute(array($name));
	$v = $read->fetchColumn();
	return $v === false ? null : $v;
};


// =========================================================================
section('A. Discovery — active plugins that declare a renderable setting');
// =========================================================================

$sources = SettingsDeclarations::renderableSources();

check(!empty($sources), 'at least one active plugin declares settings', implode(', ', $sources));

$all_active = true;
foreach ($sources as $plugin) {
	if (!PluginHelper::isPluginActive($plugin)) $all_active = false;
}
check($all_active, 'every discovered plugin is active');

$sorted = $sources;
sort($sorted);
check($sources === $sorted, 'sections come back in a stable (sorted) order', implode(', ', $sources));

// An inactive plugin must not contribute a section even though its manifest is
// on disk. Assert against the filesystem: every plugin declaring settings is
// either active-and-listed or inactive-and-absent.
$leaked = array();
foreach (glob(PathHelper::getIncludePath('plugins') . '/*/plugin.json') as $path) {
	$plugin = basename(dirname($path));
	if (!PluginHelper::isPluginActive($plugin) && in_array($plugin, $sources, true)) {
		$leaked[] = $plugin;
	}
}
check(empty($leaked), 'no inactive plugin contributes a section', implode(', ', $leaked));

// A plugin whose every declaration is `managed` has nothing to administer and
// must not get an empty section.
$empty_sections = array();
foreach ($sources as $plugin) {
	if (empty(SettingsFieldRenderer::renderSourceNames($plugin))) $empty_sections[] = $plugin;
}
check(empty($empty_sections), 'no section renders zero fields', implode(', ', $empty_sections));


// =========================================================================
section('B. One tab list, shared by every settings tab page');
// =========================================================================

$strip = AdminPage::settings_tab_menu('Plugin Settings');

check(strpos($strip, 'General Settings') !== false, 'the strip offers General Settings');
check(strpos($strip, 'Email Settings') !== false,   'the strip offers Email Settings');
check(strpos($strip, '/admin/admin_settings_plugins') === false
	|| strpos($strip, 'Plugin Settings') !== false,
	'the strip offers Plugin Settings when a plugin declares one');
check(!empty($sources) === (strpos($strip, 'Plugin Settings') !== false),
	'the Plugin Settings tab appears exactly when a plugin declares a setting');

// The current tab is marked and does not link to itself.
check(strpos($strip, 'active') !== false, 'the calling page\'s tab is marked active');
check(strpos($strip, 'href="/admin/admin_settings_plugins"') === false,
	'the current tab does not link back to itself');

// Payment Settings is store-conditional, exactly as before the move.
check(PluginHelper::isPluginActive('store') === (strpos($strip, 'Payment Settings') !== false),
	'Payment Settings appears exactly when the store plugin is active');

// Drift guard: every settings tab page must render the SAME strip. Proven on
// the pages' real output — fetch each one as a signed-in admin and compare the
// rendered tab set against the helper's own — so a page that rebuilds its own
// list is caught the moment its set diverges, however it was built.
$canonical = AdminPage::settings_tab_menu(NULL);
preg_match_all('/href="([^"#]+)"/', $canonical, $m);
$tab_urls = $m[1];
check(count($tab_urls) >= 3, 'the helper names the tab pages to sweep', implode(', ', $tab_urls));

require_once(__DIR__ . '/../lib/http.php');
$tab_admin = make_user('TabStrip', 10);
$tab_jar = harness_jar_new('pst');
$tab_csrf = harness_web_login($tab_jar, $tab_admin->get('usr_email'), 'TestPassword_TabStrip');
check($tab_csrf !== null, 'admin web login for the tab sweep succeeded');

foreach (($tab_csrf !== null) ? $tab_urls : array() as $url) {
	$page = harness_request('GET', $url, array('jar' => $tab_jar, 'accept' => null));
	$found = preg_match('/<ul class="nav-tabs">.*?<\/ul>/s', $page['body'], $mm);
	check($found === 1, "$url renders the shared tab strip", 'status ' . $page['status']);
	if ($found !== 1) { continue; }
	preg_match_all('/href="([^"#]+)"/', $mm[0], $hh);
	$got = $hh[1];
	sort($got);
	$expect = array_values(array_diff($tab_urls, array($url)));
	sort($expect);
	check($got === $expect, "$url links every other tab and not itself", implode(', ', $hh[1]));
}


// =========================================================================
section('C. Rendered is declared');
// =========================================================================

// Driven by the renderer rather than by reading page source. The set of names a
// settings page emits is not literal in its source — the inbound-provider
// fields, for one, are chosen at render time — so a grep-based sweep reports
// clean and is wrong.
foreach ($sources as $plugin) {
	$names = SettingsFieldRenderer::renderSourceNames($plugin);
	check(!empty($names), "$plugin renders at least one field", count($names) . ' fields');

	$undeclared = array();
	$managed = array();
	foreach ($names as $field) {
		if (!SettingsDeclarations::isDeclared($field)) $undeclared[] = $field;
		if (SettingsDeclarations::isManaged($field))   $managed[] = $field;
	}
	check(empty($undeclared), "every field $plugin renders is declared", implode(', ', $undeclared));
	check(empty($managed), "$plugin renders no machine-written setting", implode(', ', $managed));
}

// The plugin sections are siblings, not nested forms: the page owns begin_form,
// end_form and the submit button, and the renderer emits fields only. Proven on
// the renderer's real output — draw one plugin's section into a bare form and
// inspect what it actually wrote.
if (empty($sources)) {
	harness_skip('the renderer never opens a form', 'no plugin declares a renderable setting here');
} else {
	require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
	$probe_form = new FormWriterV2HTML5('pst_probe_form');
	ob_start();
	SettingsFieldRenderer::renderSource($probe_form, $sources[0]);
	$emitted = ob_get_clean();
	check(stripos($emitted, '<input') !== false || stripos($emitted, '<select') !== false
		|| stripos($emitted, '<textarea') !== false,
		'the renderer emits real field markup to inspect', strlen($emitted) . ' bytes');
	check(stripos($emitted, '<form') === false, 'the renderer never opens a form');
	check(stripos($emitted, '</form') === false && stripos($emitted, '<button') === false
		&& stripos($emitted, 'type="submit"') === false,
		'the renderer never closes one or adds a submit button');
}


// =========================================================================
section('D. General Settings no longer carries plugin fields');
// =========================================================================

// Proven on the rendered page: fetch General Settings as the signed-in admin
// and assert no plugin-declared field is offered for input there.
if ($tab_csrf === null) {
	harness_skip('the General page renders no plugin-declared field', 'admin web login unavailable');
} else {
	$general = harness_request('GET', '/admin/admin_settings', array('jar' => $tab_jar, 'accept' => null));
	check($general['status'] == 200, 'the General page renders for the sweep', 'status ' . $general['status']);
	$plugin_fields_on_general = array();
	foreach ($sources as $plugin) {
		foreach (SettingsFieldRenderer::renderSourceNames($plugin) as $field) {
			// A plugin page may MIRROR a core group (settingsMirrorGroups), so a
			// core-owned field showing up in its render list is by design. Only
			// a field the plugin itself declares is out of place on General.
			$decl = SettingsDeclarations::get($field);
			if (($decl['_source'] ?? '') !== $plugin) { continue; }
			if (strpos($general['body'], 'name="' . $field . '"') !== false) {
				$plugin_fields_on_general[] = $field;
			}
		}
	}
	check(empty($plugin_fields_on_general), 'the General page renders no plugin-declared field',
		implode(', ', $plugin_fields_on_general));
}
check(!file_exists(PathHelper::getIncludePath('plugins/mailbox/settings_form.php')),
	'settings_form.php files are gone — a plugin declares fields, it does not draw them');


// =========================================================================
section('E. A save writes only the submitting plugin\'s declared settings');
// =========================================================================

// Act as a superadmin with no vault, so the vault gate is inert for this
// section and any refusal here would be a scoping bug rather than a gate.
$no_vault_uid = (int)$db->query(
	"SELECT usr_user_id FROM usr_users u
	  WHERE usr_permission >= 8 AND usr_delete_time IS NULL
	    AND NOT EXISTS (SELECT 1 FROM uev_user_encryption_vaults
	                     WHERE uev_usr_user_id = u.usr_user_id AND uev_scope = 'user')
	  ORDER BY usr_user_id LIMIT 1"
)->fetchColumn();

if (!$no_vault_uid) {
	harness_skip('no vault-less admin account to act as');
} else {

	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION = array();
	$_SESSION['usr_user_id'] = $no_vault_uid;
	$_SESSION['loggedin']    = true;
	$_SESSION['permission']  = 10;

	// The submitting plugin's own row — must change.
	$own = 'dns_filtering_dns_server_ip';
	// A sibling active plugin's row — must not.
	$sibling = 'joinery_ai_local_timeout_seconds';
	// A core row — must not.
	$core = 'request_log_retention_days';

	$protect($own);
	$protect($sibling);
	$protect($core);

	$sibling_before = $value_of($sibling);
	$core_before    = $value_of($core);
	$probe          = '203.0.113.' . random_int(10, 250);

	$result = admin_settings_plugins_logic(array(
		'plugin_settings_target' => 'dns_filtering',
		$own                     => $probe,
		$sibling                 => '999',
		$core                    => '4321',
	));

	check($result->redirect === '/admin/admin_settings_plugins?plugin=dns_filtering',
		'a valid save redirects back to the plugin\'s subtab');
	check($value_of($own) === $probe, 'the submitting plugin\'s declared setting is written');
	check($value_of($sibling) === $sibling_before,
		'a sibling plugin\'s setting in the same POST is ignored', 'was ' . var_export($sibling_before, true));
	check($value_of($core) === $core_before,
		'a core setting in the same POST is ignored', 'was ' . var_export($core_before, true));

	// Re-submitting the same value must not re-stamp the audit column.
	$stamp_before = $stamp_of($own);
	admin_settings_plugins_logic(array(
		'plugin_settings_target' => 'dns_filtering',
		$own                     => $probe,
	));
	check($stamp_of($own) === $stamp_before,
		'an unchanged value is not a write (stg_update_time survives)');

	// A save that names no plugin, an unknown plugin, or a plugin with no form
	// is refused outright — nothing is written.
	$before = $value_of($own);

	$missing = admin_settings_plugins_logic(array($own => '198.51.100.7'));
	check($missing->error !== null, 'a save naming no plugin is refused');

	$unknown = admin_settings_plugins_logic(array(
		'plugin_settings_target' => 'no_such_plugin',
		$own                     => '198.51.100.7',
	));
	check($unknown->error !== null, 'a save naming an unknown plugin is refused');

	// An active plugin that declares NO renderable setting is not addressable
	// either. Every active plugin now declares something, so this only runs
	// where one genuinely does not.
	$formless = null;
	foreach (PluginHelper::getActivePlugins() as $plugin_name => $plugin_obj) {
		if (!in_array($plugin_name, $sources, true)) { $formless = $plugin_name; break; }
	}
	if ($formless !== null) {
		$no_form = admin_settings_plugins_logic(array(
			'plugin_settings_target' => $formless,
			$own                     => '198.51.100.7',
		));
		check($no_form->error !== null,
			"an active plugin with no declared settings is not addressable ($formless)");
	}

	check($value_of($own) === $before, 'no refused save wrote anything');
}


// =========================================================================
section('F. The vault gate came across with the mailbox fields');
// =========================================================================

// The gated names are plugin-declared. If mailbox stops declaring them the gate
// silently empties, so assert the list is live before trusting the behaviour.
check(VaultGatedSettings::isGated('mailbox_forwarding_smtp_host'),
	'mailbox_forwarding_smtp_host is still a declared vault-gated setting');
check(in_array('mailbox', $sources, true),
	'the mailbox fields are on this page, making this logic one of their write paths');

// A vault-holding account with no open unlock window must be refused. In CLI
// there is no session id, so VaultUnlock::isOpen() is false by construction.
$vault_uid = (int)$db->query(
	"SELECT uev_usr_user_id FROM uev_user_encryption_vaults
	  WHERE uev_scope = 'user' ORDER BY uev_usr_user_id LIMIT 1"
)->fetchColumn();

if (!$vault_uid) {
	harness_skip('no account with a user-scope vault to act as');
} elseif (!in_array('mailbox', $sources, true)) {
	harness_skip('mailbox plugin inactive, so its gated settings are not on the page');
} else {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION = array();
	$_SESSION['usr_user_id'] = $vault_uid;
	$_SESSION['loggedin']    = true;
	$_SESSION['permission']  = 10;

	check(UserEncryptionVault::loadForUser($vault_uid) !== null,
		'the acting account holds a vault');
	check(VaultUnlock::isOpen($vault_uid) === false,
		'the acting account holds no open unlock window');

	$gated   = 'mailbox_forwarding_smtp_host';
	$ungated = 'mailbox_log_retention_days';
	$protect($gated);
	$protect($ungated);

	$gated_before   = $value_of($gated);
	$ungated_before = $value_of($ungated);
	$ungated_probe  = (string)random_int(30, 400);

	admin_settings_plugins_logic(array(
		'plugin_settings_target' => 'mailbox',
		$gated                   => 'relay.attacker.example',
		$ungated                 => $ungated_probe,
	));

	check($value_of($gated) === $gated_before,
		'a gated change is refused without an open unlock window');
	check($value_of($ungated) === $ungated_probe,
		'the rest of the section still saves', 'was ' . var_export($ungated_before, true));
}

harness_finish();
