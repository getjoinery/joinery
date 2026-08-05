<?php
/** @joinery-test
 * name: box_variants
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The begin_box 'variant' option — nested and focus panels.
 *
 * A box inside another box used to be indistinguishable from the next section
 * of the page. The variant option says what a panel IS, and the two failure
 * modes it can have are both structural rather than cosmetic:
 *
 *   1. Unbalanced markup. The wrappers open in begin_box and close in end_box,
 *      and end_box is called with NO arguments almost everywhere — so the
 *      variant is carried on a stack, not re-read from what closes the box. A
 *      mismatched stack leaks a <div> and collapses the rest of the page.
 *   2. Leaking onto plain boxes. Every existing admin page calls begin_box
 *      without a variant and must render byte-for-byte what it did before.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PublicPageJoinerySystem.php'));
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));

/**
 * PublicPageBase is abstract, so the base renderers are exercised through the
 * thinnest possible concrete subclass — one that supplies the single abstract
 * method and overrides nothing else.
 */
class BvBasePage extends PublicPageBase {
	protected function getTableClasses() { return 'table'; }
}

/**
 * A page object without its constructor: begin_box/end_box need none of the
 * session, theme or request state the real page assembles, and building all of
 * that would test the bootstrap rather than the box.
 */
function bv_page(string $class) {
	return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

/** Render a closure's boxes and hand back the markup. */
function bv_render($page, callable $body): string {
	ob_start();
	$body($page);
	return (string)ob_get_clean();
}

/** Every <div> opened is closed, and nothing else is. */
function bv_balanced(string $html): bool {
	return substr_count($html, '<div') === substr_count($html, '</div>');
}

// The admin theme and the base theme render different box markup (.card-header
// vs .content-box-header), so both are exercised — the variant has to wrap
// whatever the theme produces without knowing what that is. They also disagree
// about what a box with no `card` option even is: the admin theme always renders
// a header, the base theme renders a bare <div> and skips the title entirely.
// Each theme is therefore given the options that make it produce a real box.
$themes = array(
	'PublicPageJoinerySystem' => array('header' => 'card-header',        'card' => false),
	'BvBasePage'              => array('header' => 'content-box-header', 'card' => true),
);

/** The options a box takes on this theme, plus whatever the check adds. */
function bv_opts(array $theme, array $extra): array {
	return $extra + array('card' => $theme['card']);
}

// ---------------------------------------------------------------------------
section('A box with no variant renders exactly what it always did');
// ---------------------------------------------------------------------------

foreach ($themes as $class => $theme) {
	$plain = bv_render(bv_page($class), function ($p) use ($theme) {
		$p->begin_box(bv_opts($theme, array('title' => 'Plain')));
		echo 'BODY';
		$p->end_box();
	});
	check(strpos($plain, 'jy-box') === false,
		$class . ': a box with no variant carries no variant markup', $plain);
	check(bv_balanced($plain), $class . ': and its divs balance');

	// An unrecognised value is not a class name to paste into the page: it is a
	// typo, and rendering `class="jy-box jy-box-focsu"` would silently produce a
	// plain box that looks like it took the option.
	$typo = bv_render(bv_page($class), function ($p) use ($theme) {
		$p->begin_box(bv_opts($theme, array('title' => 'Plain', 'variant' => 'focsu')));
		echo 'BODY';
		$p->end_box();
	});
	check(strpos($typo, 'jy-box') === false,
		$class . ': an unrecognised variant is ignored rather than pasted in', $typo);
	check($typo === $plain,
		$class . ': and renders byte for byte what a call without the option does');
}

// ---------------------------------------------------------------------------
section('A card box closes its card whether or not end_box is told about it');
// ---------------------------------------------------------------------------

// 'card' opens three elements and closes one, so a bare end_box() reading only
// its own empty arguments used to leave the card element open — every later
// section of the page swallowed into it. Table boxes hand their opening options
// back to end_box and so were never affected; nothing else was so lucky.
foreach ($themes as $class => $theme) {
	$bare = bv_render(bv_page($class), function ($p) {
		$p->begin_box(array('title' => 'Card', 'card' => true));
		echo 'BODY';
		$p->end_box();
	});
	check(bv_balanced($bare), $class . ': a card box closed with no options still balances',
		substr_count($bare, '<div') . ' open / ' . substr_count($bare, '</div>') . ' close');

	// The table path, unchanged: it says 'card' on the way out too, and saying
	// so must not close the card twice.
	$told = bv_render(bv_page($class), function ($p) {
		$opts = array('title' => 'Card', 'card' => true);
		$p->begin_box($opts);
		echo 'BODY';
		$p->end_box($opts);
	});
	check(bv_balanced($told), $class . ': and so does one that is told, without closing twice');
	check($bare === $told, $class . ': both spell the same markup');
}

// ---------------------------------------------------------------------------
section('A focus box is a panel on a stage; a nested box is one wrapper');
// ---------------------------------------------------------------------------

foreach ($themes as $class => $theme) {
	$focus = bv_render(bv_page($class), function ($p) use ($theme) {
		$p->begin_box(bv_opts($theme, array('title' => 'Sending identity', 'variant' => 'focus')));
		echo 'BODY';
		$p->end_box();
	});
	check(strpos($focus, '<div class="jy-box-stage"><div class="jy-box jy-box-focus">') === 0,
		$class . ': focus opens the stage, then the panel', substr($focus, 0, 90));
	check(substr($focus, -12) === '</div></div>',
		$class . ': and closes both at the end', substr($focus, -40));
	// The wrapper adds to the theme's own markup; it never stands in for it, or
	// the CSS that targets the header inside a focus panel would have nothing
	// to match.
	check(strpos($focus, $theme['header']) !== false,
		$class . ': the theme still renders its own header inside');
	check(bv_balanced($focus), $class . ': focus markup balances');

	$nested = bv_render(bv_page($class), function ($p) use ($theme) {
		$p->begin_box(bv_opts($theme, array('title' => 'Publish these', 'variant' => 'nested')));
		$p->end_box();
	});
	check(strpos($nested, '<div class="jy-box jy-box-nested">') === 0,
		$class . ': nested is a single wrapper, with no stage', substr($nested, 0, 60));
	check(strpos($nested, 'jy-box-stage') === false,
		$class . ': a nested box never gets a stage');
	check(bv_balanced($nested), $class . ': nested markup balances');
}

// ---------------------------------------------------------------------------
section('Nesting is what the option is for, so nesting has to survive it');
// ---------------------------------------------------------------------------

// THE REGRESSION THIS SECTION EXISTS FOR: end_box() takes no arguments, so if
// the variant were remembered as a single field rather than a stack, closing
// the inner box would consume the outer box's variant and the outer wrappers
// would never close.
foreach ($themes as $class => $theme) {
	$html = bv_render(bv_page($class), function ($p) use ($theme) {
		$p->begin_box(bv_opts($theme, array('title' => 'Outer', 'variant' => 'focus')));
		$p->begin_box(bv_opts($theme, array('title' => 'Inner', 'variant' => 'nested')));
		echo 'INNER';
		$p->end_box();
		$p->begin_box(bv_opts($theme, array('title' => 'Plain inner')));
		$p->end_box();
		$p->end_box();
		echo 'AFTER';
		$p->begin_box(bv_opts($theme, array('title' => 'Next section')));
		$p->end_box();
	});
	check(bv_balanced($html), $class . ': three boxes deep, every div still closes',
		substr_count($html, '<div') . ' open / ' . substr_count($html, '</div>') . ' close');
	check(substr_count($html, 'jy-box-stage') === 1 && substr_count($html, 'jy-box-nested') === 1,
		$class . ': one stage and one nested wrapper, not one per box');
	// Everything after the focus box must be outside it — a leaked div would
	// swallow the rest of the page into the panel. The base theme drops titles
	// from non-card boxes, so the marker is echoed rather than read off one.
	$after = substr($html, strpos($html, 'AFTER'));
	check(strpos($after, 'jy-box') === false,
		$class . ': what follows the focus box is outside it', $after);
}

// ---------------------------------------------------------------------------
section('The variant reaches the DNS publish box, which renders inside panels');
// ---------------------------------------------------------------------------

require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));

class BvBoxProbe {
	public $options = array();
	public function begin_box($o) { $this->options[] = $o; }
	public function end_box() {}
	public function getFormWriter($id, $o = array()) { return new BvNullForm(); }
}
class BvNullForm {
	public function begin_form() { return ''; }
	public function end_form() { return ''; }
	public function __call($m, $a) { return ''; }
}

$plan = new DnsRecordPlan('example.com', 'test');
$plan->addRecord('TXT', 'example.com', 'v=spf1 -all');
$vars = array('plan' => $plan, 'state' => DnsPublishBox::STATE_DIFF,
	'domain' => 'example.com', 'return_url' => '/x', 'provider_key' => 'manual',
	'provider_label' => 'Manual', 'provider_class' => null, 'provider_options' => array(),
	'show_chooser' => false, 'rows' => array(), 'counts' => array(), 'accounts' => array(),
	'live_ns' => array(), 'detected_key' => '', 'detected_label' => '', 'prerequisite' => '',
	'credential_fields' => array(), 'credential_guide' => null);

$probe = new BvBoxProbe();
ob_start();
dns_publish_box_render($probe, $vars, 'Publish the send-protection records', 'nested');
ob_get_clean();
check(!empty($probe->options) && ($probe->options[0]['variant'] ?? '') === 'nested',
	'a publish box rendered inside a panel asks for the nested variant',
	json_encode($probe->options[0]['variant'] ?? null));

$probe = new BvBoxProbe();
ob_start();
dns_publish_box_render($probe, $vars);
ob_get_clean();
check(!empty($probe->options) && ($probe->options[0]['variant'] ?? '') === '',
	'and a top-level one still asks for no variant at all');

// ---------------------------------------------------------------------------
section('The Setup tab asks for the shapes it says it does');
// ---------------------------------------------------------------------------

// The Sending identity panel is a separate undertaking sitting in the middle of
// a linear setup page, and reading as the next step is the defect. Pin the two
// call sites so a later edit cannot quietly flatten it back.
$setup = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/admin/admin_mailbox_setup.php'));
check(preg_match("/'title'\s*=>\s*'Sending identity[^\n]*\n\s*'variant'\s*=>\s*'focus'/", $setup) === 1,
	'the Sending identity panel is rendered as a focus box');
// Asserted on the variant argument, not the heading: what matters here is that
// the box renders as part of the panel. The heading is the ceremony's business
// and protect_optin pins its wording.
check(preg_match("/dns_publish_box_render\(\\\$page, \\\$protect_dns_box,\s*\n?[^;]*'nested'\)/", $setup) === 1,
	'and the publish box inside it is nested, not another top-level offer');

// The presentation has to exist, or every variant above is a class name that
// styles nothing.
$css = file_get_contents(PathHelper::getIncludePath('assets/css/joinery-styles.css'));
foreach (array('.jy-box-stage', '.jy-box-focus', '.jy-box-nested') as $sel) {
	check(strpos($css, $sel . ' {') !== false || strpos($css, $sel . ',') !== false,
		'the shared kit stylesheet styles ' . $sel);
}

harness_finish();
?>
