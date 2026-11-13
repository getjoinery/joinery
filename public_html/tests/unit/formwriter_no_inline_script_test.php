<?php
/** @joinery-test
 * name: formwriter_no_inline_script
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * PURPOSE: FormWriter writes no script the strict script policy would refuse
 * (specs/strict_script_policy.md WP3). One form with every field that has
 * behaviour (validation, visibility_rules on each trigger kind, a repeater, a
 * time input, an AJAX select, a colour picker, an image selector, a confirm
 * button, a navigating select) and the bulk uploader is rendered, and its
 * markup must carry:
 *   - no on*= attribute and no javascript: URL;
 *   - no <script> without src, except JSON data blocks;
 *   - the field scripts as files, each once.
 * And a field handed a script option (onclick, onchange, custom_script)
 * throws, rather than losing it quietly.
 *
 * What the fields do in a browser is tests/unit/formwriter_behaviour_test.php.
 *
 * Run: php tests/run.php --only=tests/unit/formwriter_no_inline_script_test.php
 *
 * @version 1.2 - no confirm button carries formaction/formmethod/formenctype/formtarget
 * @version 1.1 - the tree may not hand FormWriter a removed script option in any form (reviewer2 B1, B2)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$fw = new FormWriterV2HTML5('fwn_form', array('action' => '/fwn-nowhere', 'csrf' => false));
ob_start();
$fw->begin_form();
$fw->textinput('fwn_email', 'Email', array('validation' => array('required' => true, 'email' => true, 'pattern' => '/^[a-z@.]+$/i')));
$fw->dropinput('fwn_mode', 'Mode', array('options' => array('a' => 'A', 'b' => 'B'),
	'visibility_rules' => array('a' => array('show' => array('fwn_x')), 'b' => array('hide' => array('fwn_x')))));
$fw->checkboxinput('fwn_flag', 'Flag', array('visibility_rules' => array('checked' => array('show' => array('fwn_x')))));
$fw->radioinput('fwn_kind', 'Kind', array('options' => array('p' => 'P', 'q' => 'Q'),
	'visibility_rules' => array('q' => array('show' => array('fwn_x')))));
$fw->checkboxList('fwn_plan', 'Plan', array('type' => 'radio', 'options' => array('m' => 'M', 'y' => 'Y'),
	'visibility_rules' => array('y' => array('show' => array('fwn_x')))));
$fw->textinput('fwn_x', 'X');
$fw->repeater('fwn_rows', 'Rows', array('fields' => array(
	array('name' => 'title', 'label' => 'Title', 'type' => 'textinput'),
	array('name' => 'at', 'label' => 'At', 'type' => 'timeinput'),
)));
$fw->timeinput('fwn_time', 'Time');
$fw->dropinput('fwn_search', 'Search', array('options' => array(), 'ajaxendpoint' => '/api/v1/action/x?a=1&b=2'));
$fw->dropinput('fwn_go', 'Go to', array('options' => array('/a' => 'A', '/b' => 'B'), 'navigate_on_change' => true));
$fw->colorpicker('fwn_colour', 'Colour');
$fw->imageselector('fwn_image', 'Image', array('placeholder' => 'Find a "photo"'));
$fw->submitbutton('fwn_delete', 'Delete', array('confirm' => "Delete O'Brien's row?"));
$fw->end_form();
$html = (string)ob_get_clean() . $fw->file_upload_full(array('evs_event_session_id' => '7"><b>'));

section('No inline handler and no inline script');
check(strpos($html, '<form') !== false && strpos($html, 'fwn_colour') !== false && strpos($html, 'file-drop-zone') !== false,
	'the form and the uploader rendered', strlen($html) . ' bytes');
check(!preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html, $m), 'no on*= attribute anywhere', $m[0] ?? '');
check(!preg_match('/(?:href|src|action|formaction)\s*=\s*["\']?\s*javascript:/i', $html, $m), 'no javascript: URL', $m[0] ?? '');
$inline = array();
if (preg_match_all('/<script\b([^>]*)>/i', $html, $tags, PREG_SET_ORDER)) {
	foreach ($tags as $t) {
		if (preg_match('/\ssrc\s*=/i', $t[1])) continue;
		if (preg_match('/\stype\s*=\s*["\']application\/json["\']/i', $t[1])) continue;
		$inline[] = $t[0];
	}
}
check($inline === array(), 'no <script> without src other than JSON data', implode(' ', $inline));

section('The field scripts are files, each once');
foreach (array('joinery-forms.js', 'joinery-image-selector.js', 'joinery-file-upload.js') as $file) {
	$n = preg_match_all('#<script src="/assets/js/' . preg_quote($file, '#') . '\?v=\d+"></script>#', $html);
	check($n === 1, "$file is loaded once", "$n times");
}
$repeater_at = strpos($html, 'class="repeater');
$forms_at = strpos($html, '/assets/js/joinery-forms.js');
check($forms_at !== false && $repeater_at !== false && $forms_at < strpos($html, '<template class="repeater-template">'),
	'joinery-forms.js loads before the repeater\'s <template>, where a script tag would never load');

section('What the scripts read is data on the page');
check(preg_match('/<script type="application\/json" data-jy-validate>(.*?)<\/script>/s', $html, $v) === 1, 'the validation rules are a JSON block');
$cfg = json_decode($v[1] ?? '', true);
check(($cfg['form'] ?? '') === 'fwn_form' && !empty($cfg['rules']['fwn_email']['required']) && !empty($cfg['rules']['fwn_email']['email']),
	'naming the form and its rules', $v[1] ?? '');
$blocks = preg_match_all('/<script type="application\/json" data-jy-visibility>(.*?)<\/script>/s', $html, $vis);
check($blocks === 4, 'one visibility block per trigger', (string)$blocks);
$types = array();
foreach ($vis[1] as $raw) {
	$c = json_decode($raw, true);
	$types[] = ($c['type'] ?? '?') . ':' . ($c['group'] ?? '');
}
check($types === array('select:fwn_mode', 'checkbox:fwn_flag', 'radio:fwn_kind', 'radio:fwn_plan[]'), 'each with its kind and group', implode(', ', $types));
check(strpos($html, 'data-jy-ajax-select="/api/v1/action/x?a=1&amp;b=2"') !== false, 'the AJAX select carries its endpoint, & and all');
check(strpos($html, 'data-jy-navigate') !== false, 'navigate_on_change marks its select');
check(strpos($html, 'data-jy-confirm="Delete O&#039;Brien&#039;s row?"') !== false, "a submit button's confirm is a kit attribute");
check(strpos($html, 'data-placeholder="Find a &quot;photo&quot;"') !== false, "the image selector's search prompt is on its field");
check(preg_match('/data-max-size="\d+"/', $html) === 1 && strpos($html, 'data-allowed-extensions="') !== false, "the uploader's limits are on its drop zone");
check(strpos($html, 'value="7&quot;&gt;&lt;b&gt;"') !== false && strpos($html, '"><b>') === false, "the uploader's hidden values are escaped");

section('A JSON block cannot be closed by the data inside it');
$fw2 = new FormWriterV2HTML5('fwn_form2', array('csrf' => false));
ob_start();
$fw2->begin_form();
$fw2->textinput('fwn_t', 'T', array('validation' => array('required' => true, 'messages' => array('required' => '</script><script>alert(1)</script>'))));
$fw2->end_form();
$html2 = (string)ob_get_clean();
check(substr_count(strtolower($html2), '</script>') === substr_count(strtolower($html2), '<script'), 'every script tag closes where FormWriter closed it');
check(strpos($html2, 'alert(1)') !== false && stripos($html2, '<script>alert') === false, 'the message is in the block, escaped');

section('A script option throws, for every writer');
foreach (array('onclick' => 'submitbutton', 'onchange' => 'dropinput', 'custom_script' => 'dropinput') as $opt => $method) {
	foreach (array('FormWriterV2HTML5', 'FormWriterV2JSON') as $class) {
		$err = null;
		try {
			$w = new $class('fwn_t_' . $opt);
			if ($method === 'submitbutton') {
				$w->submitbutton('b', 'B', array($opt => 'x()'));
			} else {
				$w->dropinput('d', 'D', array('options' => array('a' => 'A'), $opt => 'x()'));
			}
		} catch (Throwable $e) {
			$err = $e->getMessage();
		}
		check($err !== null && strpos($err, $opt) !== false, "$class $method with '$opt' throws, naming it", (string)$err);
	}
}

section('No caller in the tree hands FormWriter a removed script option');
// A removed option now throws, so a caller still passing one is a page that
// dies. reviewer2 B1: the Server Manager copy tab set $btn_opts['onclick'] =
// ..., which no key-literal scan saw. Any file that calls a FormWriter field
// method may not name onclick, onchange or custom_script as an option key,
// whether as 'key' => or ['key'] =, and nothing may call the removed methods.
$root = rtrim(PathHelper::getIncludePath(''), '/');
$fw_call = '/->(?:textinput|numberinput|passwordinput|dropinput|checkboxinput|radioinput|checkboxList|dateinput|timeinput|datetimeinput|fileinput|hiddeninput|textbox|imageinput|imageselector|colorpicker|textarea|submitbutton|repeater)\s*\(/';
$key = '(?:onclick|onchange|custom_script)';
$offenders = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	$path = $file->getPathname();
	if (substr($path, -4) !== '.php') continue;
	$rel = substr($path, strlen($root) + 1);
	if (preg_match('#^(?:tests|specs|vendor|docs)/|/vendor/#', $rel) || $rel === 'includes/FormWriterV2Base.php') continue;
	$src = (string)@file_get_contents($path);
	if (preg_match('/->(?:addReadyScript|generateFieldScript|outputReadyScripts)\s*\(/', $src, $m)) {
		$offenders[] = "$rel calls a removed method ({$m[0]})";
	}
	if (!preg_match($fw_call, $src)) continue;
	if (preg_match_all('/(?:["\']' . $key . '["\']\s*=>|\[\s*["\']' . $key . '["\']\s*\]\s*=(?![=>]))/', $src, $mm, PREG_OFFSET_CAPTURE)) {
		foreach ($mm[0] as $hit) {
			$offenders[] = $rel . ':' . (substr_count(substr($src, 0, $hit[1]), "\n") + 1) . ' ' . trim($hit[0]);
		}
	}
}
check($offenders === array(), 'no file that writes a form names a removed script option, in any form', implode(' | ', $offenders));

// A confirm button (data-jy-submit-self) is submitted by base.js with its name
// and value carried in a hidden input, not as the submitter, so a formaction,
// formmethod, formenctype or formtarget on it would be ignored (reviewer2,
// re-check of B15). None may carry one.
$ignored = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	$path = $file->getPathname();
	if (!preg_match('/\.(php|js|html)$/', $path)) continue;
	$rel = substr($path, strlen($root) + 1);
	if (preg_match('#^(?:tests|specs|vendor|docs)/|/vendor/#', $rel)) continue;
	$src = (string)@file_get_contents($path);
	if (strpos($src, 'data-jy-submit-self') === false) continue;
	if (preg_match_all('/<button\b[^>]*data-jy-submit-self[^>]*>/s', $src, $tags)) {
		foreach ($tags[0] as $t) {
			if (preg_match('/\sform(?:action|method|enctype|target)\s*=/i', $t)) $ignored[] = $rel . ': ' . substr(preg_replace('/\s+/', ' ', $t), 0, 120);
		}
	}
}
check($ignored === array(), 'no confirm button carries a form* override that base.js would ignore', implode(' | ', $ignored));

harness_finish();
