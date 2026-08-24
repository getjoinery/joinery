<?php
/** @joinery-test
 * name: core_api_mechanical
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The core-API rules that stopped being gotchas.
 *
 * Each section here pins one thing a developer previously had to remember and
 * now does not: that a collection loads itself, that a filter named after a
 * column filters by it, that a class resolves without being required first,
 * that a link which changes data has to prove where it came from. The point of
 * pinning them is that every one of these fails silently when it regresses —
 * an unloaded collection is an empty loop, a dropped filter is more rows than
 * you asked for, a missing token is a working delete link.
 *
 * Run: php tests/unit/core_api_mechanical_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

if (session_id() === '') { @session_start(); }

function cam_threw(callable $fn, $class = 'Throwable') {
	try { $fn(); return false; } catch (Throwable $e) { return $e instanceof $class; }
}

// ---------------------------------------------------------------- autoloader

section('Classes resolve by name');

$map = ClassAutoloader::rebuild();
check(count($map) > 500, 'The class map covers the tree', count($map) . ' names');

// Every name in the map must resolve through the autoloader alone. Loading all
// of them in one process is what a full-map assertion means, and it also proves
// no two mapped files fight over a name.
$unresolved = array();
foreach (array_keys($map) as $name) {
	if (class_exists($name) || interface_exists($name) || trait_exists($name)) {
		continue;
	}
	$unresolved[] = $name;
}
check(count($unresolved) === 0,
	'Every mapped name resolves through the autoloader alone',
	$unresolved ? implode(', ', array_slice($unresolved, 0, 8)) : 'all ' . count($map));

// A model class is the case the 1:1 filename rule cannot answer: class Product
// lives in products_class.php.
check(isset($map['Product']) && strpos($map['Product'], 'products_class.php') !== false,
	'A model class maps to its plural _class.php file');

// A theme replaces PublicPage by shipping its own includes/PublicPage.php, so
// the autoloader has to go through the theme chain rather than straight to core.
$ref = new ReflectionClass('PublicPage');
check($ref->getFileName() !== '', 'PublicPage resolved from somewhere', $ref->getFileName());

// ------------------------------------------------------- constructor default

section('Constructing a model');

$implied = new Question();
check($implied->key === NULL, 'new Question() means a new, unsaved record');
check($implied instanceof Question, 'and it is a usable instance, not an error');

$explicit = new Question(NULL);
check($explicit->key === NULL, 'new Question(NULL) means the same thing');

// ------------------------------------------------------------- collections

section('Collections load themselves');

$lazy = new MultiUser(array('permission' => 10));
check($lazy->count() > 0, 'count() runs the query without an explicit load()');

$iterated = 0;
foreach (new MultiUser(array('permission' => 10)) as $u) { $iterated++; }
check($iterated === $lazy->count(), 'iterating runs it too, and agrees');

// A hand-built collection is already answered; the query must not replace it.
$built = new MultiUser(array('permission' => 10));
$built->add(new User(NULL));
check(count($built) === 1, 'add() marks a collection built by hand as loaded');

$explicit_load = new MultiUser(array('permission' => 10));
$explicit_load->load();
$before = count($explicit_load);
$explicit_load->load();
check(count($explicit_load) === $before, 'an explicit load() is still valid and idempotent');

section('Reading a property a collection does not have');

$multi = new MultiUser(array('permission' => 10));
check(cam_threw(function () use ($multi) { $x = $multi->results; }, 'SystemBaseException'),
	'->results throws rather than answering with nothing');

$message = '';
try { $x = $multi->nonsense; } catch (Throwable $e) { $message = $e->getMessage(); }
check(strpos($message, 'foreach') !== false, 'and the message names the fix', $message);

// Single models still carry dynamic properties on purpose — conversations and
// users both attach computed fields — so the same refusal must NOT be on
// SystemBase.
$user = new User(NULL);
$user->some_computed_thing = 'x';
check($user->some_computed_thing === 'x', 'a single model still accepts a dynamic property');

// -------------------------------------------------------- the deleted filter

section('Core answers the deleted filter');

// A model whose class no longer writes the filter out.
$live = new MultiProduct(array('deleted' => false));
$gone = new MultiProduct(array('deleted' => true));
check($live->count_all() >= 0 && $gone->count_all() >= 0, 'both directions run');
$all_products = new MultiProduct(array());
check($all_products->count_all() === $live->count_all() + $gone->count_all(),
	'live + deleted accounts for every row',
	$live->count_all() . ' + ' . $gone->count_all() . ' = ' . $all_products->count_all());

// Models that never had the filter at all now have it.
foreach (array('MultiEmailTemplateStore', 'MultiSubscriptionTier', 'MultiContentVersion', 'MultiDirectSpool') as $cls) {
	$ok = true;
	try { $m = new $cls(array('deleted' => false)); $m->count_all(); }
	catch (Throwable $e) { $ok = false; }
	check($ok, "$cls accepts the deleted filter");
}

// Omitting it changes nothing — the flip is deliberately not part of this.
$omitted = new MultiProduct(array());
check($omitted->count_all() === $all_products->count_all(),
	'omitting deleted still selects every row');

// --------------------------------------------------------- column filter keys

section('Filter keys that name a column');

$by_column = new MultiUser(array('usr_permission' => 10));
$by_suffix = new MultiUser(array('permission' => 10));
check($by_column->count_all() === $by_suffix->count_all(),
	'the full column name and the prefix-less form agree',
	$by_column->count_all() . ' both ways');

$everyone = new MultiUser(array());
check($by_column->count_all() < $everyone->count_all(),
	'and the filter actually narrows the result',
	$by_column->count_all() . ' of ' . $everyone->count_all());

check(cam_threw(function () { (new MultiUser(array('not_a_column_at_all' => 1)))->count_all(); },
	'UnknownMultiOptionException'),
	'a key that is neither implemented nor a column still throws');

// A collection over a join or a view is exempt: its model's columns are not the
// authority on what the query selects.
$join_ok = true;
try { $c = new MultiConversation(array('participant_user_id' => 1)); $c->count_all(); }
catch (UnknownMultiOptionException $e) { $join_ok = false; }
check($join_ok, 'a join collection still answers its own option vocabulary');

// ------------------------------------------------------- session assert pair

section('assert_can_write / assert_can_read');

$session = SessionControl::get_instance();
$staff = make_user('CoreApiStaff', 10);
$owner = make_user('CoreApiOwner', 0);

$_SESSION['loggedin']    = TRUE;
$_SESSION['usr_user_id'] = $staff->key;
$_SESSION['permission']  = 10;

$subject = new Question(NULL);
$subject->set('qst_question', 'core_api_mechanical ' . uniqid());
$subject->prepare();
$subject->save();

$allowed = true;
try { $subject->assert_can_write($session); } catch (Throwable $e) { $allowed = false; }
check($allowed, 'staff may write');

$_SESSION['usr_user_id'] = $owner->key;
$_SESSION['permission']  = 0;
check(cam_threw(function () use ($subject, $session) { $subject->assert_can_write($session); },
	'SystemAuthenticationError'),
	'a non-owner without staff permission may not');

$_SESSION['usr_user_id'] = $staff->key;
$_SESSION['permission']  = 10;
$subject->permanent_delete();

// --------------------------------------------------------------- GET actions

section('Actions a user triggers are POSTs, not links');

// An altlinks entry describing a `post` renders as a submit button in its own
// form. A link would be a GET, and a GET is something any other site can make
// the browser perform.
$page = new AdminPage();
$reflect = new ReflectionMethod('PublicPageBase', 'renderActionEntry');
$reflect->setAccessible(true);

$button = $reflect->invoke($page, 'Soft Delete', array(
	'post'   => '/admin/admin_post',
	'hidden' => array('action' => 'delete', 'pst_post_id' => 7),
), 'dropdown-item');

check(strpos($button, '<form method="POST" action="/admin/admin_post"') !== false,
	'an action entry renders as a POST form');
check(strpos($button, 'name="action" value="delete"') !== false
	&& strpos($button, 'name="pst_post_id" value="7"') !== false,
	'carrying its parameters as hidden fields');
check(strpos($button, '<button type="submit"') !== false && strpos($button, '<a href') === false,
	'and as a button, never a link');

$plain = $reflect->invoke($page, 'Edit', '/admin/admin_post_edit?pst_post_id=7', 'dropdown-item');
check(strpos($plain, '<a href="/admin/admin_post_edit?pst_post_id=7"') !== false,
	'a plain string entry is still a link — going somewhere is a GET');

$confirmed = $reflect->invoke($page, 'Permanent Delete', array(
	'post'    => '/admin/admin_post',
	'hidden'  => array('action' => 'permanent_delete'),
	'confirm' => 'Delete permanently?',
), 'dropdown-item');
check(strpos($confirmed, 'JoineryModal.confirm') !== false,
	'and a destructive one can demand confirmation first');

section('Only the server may write during a page view');

// The escape hatch from "a page view must not persist what a user asked for".
// Every caller is named here, and a new one fails this check — which is the
// point: reaching for it is nearly always the wrong fix for a refused save, and
// the right fix is to make the thing doing the saving a POST.
//
// Adding a name below is a deliberate act. Before doing it, the write has to be
// one the server initiated on its own: observation, reconciliation, a
// third-party redirect landing back here, or work a scheduled task would
// otherwise have done. A user clicking something is none of those.
$permitted = array(
	'data/api_keys_class.php'                                 => 'API key last-used tracking, on read requests',
	'data/general_errors_class.php'                           => 'error rows, recorded on whatever request failed',
	'includes/RequestLogger.php'                              => 'request log rows, including for reads',
	'includes/setup_steps/mail_send.php'                      => 'receiving-domain row reconciled from the stored From address on a wizard view — the Direct records cannot be listed without it',
	'includes/VaultAudit.php'                                 => 'vault window opened/closed, observed on whatever request noticed',
	'logic/oauth_callback_logic.php'                          => 'OAuth provider redirect — persisting the grant IS the request',
	'plugins/mailbox/includes/InboundEmailRouter.php'         => 'deferred report filing at unlock — lazy processing only the owner\'s in-window secret makes possible',
	'plugins/mailbox/includes/InboundEmailSetupCheck.php'     => 'relay status folded into the row on a setup view',
	'plugins/mailbox/includes/relay_admin.php'                => 'relay/shard reconciliation from node facts',
	'plugins/server_manager/includes/JobResultProcessor.php'  => 'job results processed lazily on first view',
	'plugins/store/logic/cart_charge_logic.php'               => 'payment gateway return — persisting the order IS the request',
);

$found = array();
$scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
	PathHelper::getBasePath(), FilesystemIterator::SKIP_DOTS));
foreach ($scan as $file) {
	if ($file->getExtension() !== 'php') continue;
	$rel = ltrim(str_replace(PathHelper::getBasePath(), '', $file->getPathname()), '/');
	if (strpos($rel, 'specs/') === 0 || strpos($rel, 'docs/') === 0) continue;
	if (strpos($rel, 'tests/') === 0 || strpos($rel, '/tests/') !== false) continue;
	if ($rel === 'includes/SystemBase.php') continue;   // where it is defined

	$src = file_get_contents($file->getPathname());
	if (strpos($src, 'server_initiated_write(') !== false
		|| strpos($src, '$allow_get_mutation') !== false) {
		$found[] = $rel;
	}
}
sort($found);

$unlisted = array_diff($found, array_keys($permitted));
check(count($unlisted) === 0,
	'no file writes during a page view without being listed here',
	$unlisted ? implode(', ', $unlisted) : count($found) . ' listed callers');

$stale = array_diff(array_keys($permitted), $found);
check(count($stale) === 0,
	'and every listed caller still needs to be',
	$stale ? 'no longer writes: ' . implode(', ', $stale) : 'all still in use');

section('Writes the server makes during a page view');

$side_effect_ran = false;
$flag_inside = null;
$result = SystemBase::server_initiated_write(function () use (&$side_effect_ran, &$flag_inside) {
	$side_effect_ran = true;
	$flag_inside = SystemBase::$allow_get_mutation;
	return 'returned';
});
check($side_effect_ran, 'server_initiated_write() runs the write');
check($flag_inside === true, 'with the GET-write tripwire lifted');
check($result === 'returned', 'and hands back what the write returned');
check(SystemBase::$allow_get_mutation === false, 'then puts the tripwire back');

try {
	SystemBase::server_initiated_write(function () { throw new RuntimeException('boom'); });
} catch (RuntimeException $e) { /* expected */ }
check(SystemBase::$allow_get_mutation === false, 'and restores it when the write throws');

// ------------------------------------------- the undelete bug that started it

section('Undeleting undeletes');

// The delete and undelete branches of the admin question handler were
// copy-paste twins, and the undelete one still called soft_delete(). Nothing
// failed: the record stayed deleted and the page redirected as though it had
// worked. Driving the real handler is the only way to catch that again.
$regression = new Question(NULL);
$regression->set('qst_question', 'core_api_mechanical undelete ' . uniqid());
$regression->prepare();
$regression->save();

$_SESSION['loggedin']    = TRUE;
$_SESSION['usr_user_id'] = $staff->key;
$_SESSION['permission']  = 10;

require_once(PathHelper::getIncludePath('adm/logic/admin_question_logic.php'));

$saved_method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
$_SERVER['REQUEST_METHOD'] = 'POST';

admin_question_logic(array('action' => 'delete', 'qst_question_id' => $regression->key));
$after_delete = new Question($regression->key, TRUE);
check($after_delete->get('qst_delete_time') !== NULL, 'the delete branch deletes');

admin_question_logic(array('action' => 'undelete', 'qst_question_id' => $regression->key));
$after_undelete = new Question($regression->key, TRUE);
check($after_undelete->get('qst_delete_time') === NULL,
	'and the undelete branch UNDELETES — it does not delete again',
	var_export($after_undelete->get('qst_delete_time'), true));

if ($saved_method === null) { unset($_SERVER['REQUEST_METHOD']); }
else { $_SERVER['REQUEST_METHOD'] = $saved_method; }
$regression->permanent_delete();

// --------------------------------------------- an unexposed action says so

section('An action without a descriptor fails loud');

// A logic function with no descriptor is not exposed. Answering "Unknown
// action" sends the caller hunting for a typo that is not there, so the error
// names the actual fix.
require_once(PathHelper::getIncludePath('includes/ApiLogicEndpoint.php'));

$resolve = new ReflectionMethod('ApiLogicEndpoint', 'resolveMeta');
$resolve->setAccessible(true);
check($resolve->invoke(null, 'no_such_action_at_all_zz') === null,
	'an action with no descriptor resolves to no metadata');

// Every exposed action must actually carry one — the codemod left no file
// declaring only the legacy companion, so a null here means a real gap.
$descriptorless = array();
foreach (glob(PathHelper::getIncludePath('logic') . '/*_logic.php') as $file) {
	$base = basename($file, '.php');
	$action = substr($base, 0, -6);
	$src = file_get_contents($file);
	// Only actions that declare a form builder are required to be exposed;
	// the rest are page logic and are deliberately not API actions.
	if (strpos($src, 'function ' . $base . '_form(') === false) continue;
	if (strpos($src, 'function ' . $base . '_descriptor(') === false) {
		$descriptorless[] = $action;
	}
}
check(count($descriptorless) === 0,
	'every action with a form builder carries a descriptor',
	$descriptorless ? implode(', ', $descriptorless) : 'all exposed');

// ------------------------------------------------- messages are spent on show

section('A message is spent when it is shown, not when it is read');

$_SESSION['saved_messages'] = array();
$_SERVER['REQUEST_URI'] = '/profile/account_edit';
$msg_page = new PublicPage();

$session->save_message(new DisplayMessage('Saved.', 'Done', NULL,
	DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox'));

// Reading is free. This is the whole point: a page that will not display a
// message must be able to ask what is pending without destroying it.
$read = $session->get_messages('/profile/account_edit');
check(count($read) === 1, 'a pending message is readable');
$session->clear_clearable_messages();
check(count($session->get_messages('/profile/account_edit')) === 1,
	'and reading it did NOT consume it');

// The regression the old design could not express: a page that renders some
// OTHER slot must leave this message alone.
$other = $msg_page->render_messages('addressbox');
check($other === '', 'rendering a different slot emits nothing');
$session->clear_clearable_messages();
check(count($session->get_messages('/profile/account_edit')) === 1,
	'and leaves the message pending for the page that does show it');

// Rendering its own slot shows it...
$html = $msg_page->render_messages('userbox');
check(strpos($html, 'Saved.') !== false, 'rendering its own slot emits the message', $html);

// ...and spends it.
$session->clear_clearable_messages();
check(count($session->get_messages('/profile/account_edit')) === 0,
	'and showing it is what clears it');

// A sticky message survives being shown.
$_SESSION['saved_messages'] = array();
$session->save_message(new DisplayMessage('Stays.', 'Notice', NULL,
	DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', FALSE));
$msg_page->render_messages('userbox');
$session->clear_clearable_messages();
check(count($session->get_messages('/profile/account_edit')) === 1,
	'a message declared not clearable survives being shown');

// No view still hand-rolls the loop the helper replaced.
$handrolled = array();
foreach (array('views', 'theme', 'plugins') as $dir) {
	$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
		PathHelper::getIncludePath($dir), FilesystemIterator::SKIP_DOTS));
	foreach ($walk as $file) {
		if ($file->getExtension() !== 'php') continue;
		if (strpos($file->getPathname(), '/views/') === false) continue;
		$src = file_get_contents($file->getPathname());
		if (preg_match('/foreach[^)]*display_messages[^)]*\)\s*\{\s*\R\s*if\s*\([^)]*->identifier/i', $src)) {
			$handrolled[] = basename($file->getPathname());
		}
	}
}
check(count($handrolled) === 0,
	'no view still filters display_messages by hand',
	$handrolled ? implode(', ', $handrolled) : 'all render through render_messages()');

$_SESSION['saved_messages'] = array();
unset($_SERVER['REQUEST_URI']);

// ----------------------------------------------------------------- get_local

section('Times render in the viewer timezone');

$_SESSION['timezone'] = 'America/Chicago';
$row = new Question(NULL);
$row->set('qst_question', 'core_api_mechanical time ' . uniqid());
$row->prepare();
$row->save();
$row = new Question($row->key, TRUE);

$utc = $row->get('qst_create_time');
check(!empty($utc), 'the saved row has a create time', (string)$utc);

$long  = $row->get_local('qst_create_time');
$short = $row->get_local('qst_create_time', 'Y-m-d');
check(is_string($long) && $long !== '', 'get_local() renders the field', $long);
check($short === substr($long_expected = LibraryFunctions::convert_time($utc, 'UTC', 'America/Chicago', 'Y-m-d'), 0, 10),
	'and honors the format argument', $short);
check($long === LibraryFunctions::convert_time($utc, 'UTC', 'America/Chicago', 'M j, Y g:i A T'),
	'and matches the conversion it replaces');

check($row->get_local('qst_delete_time') === FALSE,
	'an empty field renders as nothing, not as now');

$_SESSION['timezone'] = 'UTC';
check($row->get_local('qst_create_time', 'Y-m-d H:i') === LibraryFunctions::convert_time($utc, 'UTC', 'UTC', 'Y-m-d H:i'),
	'and follows the session timezone when it changes');

$row->permanent_delete();

// ------------------------------------------------------------ Setting::put()

section('Writing a setting programmatically');

check(cam_threw(function () { Setting::put('core_api_mechanical_undeclared_name', '1'); },
	'InvalidArgumentException'),
	'put() refuses a name no declaration mentions');

check(cam_threw(function () { Setting::put('', '1'); }, 'InvalidArgumentException'),
	'and refuses an empty name');

$settings = Globalvars::get_instance();
$declared = 'protocol_observed_scheme';
$before = $settings->get_setting($declared);
Setting::put($declared, $before === 'https' ? 'https' : (string)$before);
check(true, 'put() accepts a declared name');

// --------------------------------------------------- descriptors, one spelling

section('One metadata companion');

$stray = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
	PathHelper::getIncludePath('logic'), FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	if ($file->getExtension() !== 'php') continue;
	if (strpos(file_get_contents($file->getPathname()), '_logic_api(') !== false) {
		$stray[] = $file->getFilename();
	}
}
check(count($stray) === 0, 'no logic file still declares the legacy companion',
	$stray ? implode(', ', $stray) : 'none');

// ------------------------------------------------ edit forms know their record

section('An edit form knows which record it edits');

$edited = new Question(NULL);
$edited->set('qst_question', 'core_api_mechanical form ' . uniqid());
$edited->prepare();
$edited->save();

/** The markup one form emits, however the writer chooses to deliver it. */
function cam_form_html($form_id, array $options) {
	ob_start();
	$fw = new FormWriterV2HTML5($form_id, $options);
	$out = (string)$fw->begin_form();
	$fw->textinput('qst_question', 'Question');
	$out .= (string)$fw->end_form();
	return ob_get_clean() . $out;
}

$html = cam_form_html('cam_edit_form', array('model' => $edited));
check(strpos($html, 'name="edit_primary_key_value" value="' . $edited->key . '"') !== false,
	'a form handed a saved record emits its key');

$fresh_html = cam_form_html('cam_new_form', array('model' => new Question(NULL)));
check(strpos($fresh_html, 'edit_primary_key_value') === false,
	'a form handed a new record emits none');

$optout_html = cam_form_html('cam_dup_form',
	array('model' => $edited, 'edit_primary_key_value' => null));
check(strpos($optout_html, 'edit_primary_key_value') === false,
	'and an explicit null opts a duplicate-record form out');

$edited->permanent_delete();

harness_finish();
