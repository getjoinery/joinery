<?php

	// SessionControl is now guaranteed available - line removed
	// LibraryFunctions is now guaranteed available - line removed
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	require_once(PathHelper::getIncludePath('data/points_class.php'));

	echo 'turned off';
	exit;

	$settings = Globalvars::get_instance();
	$node_dir = $settings->get_setting('node_dir');

	if($_REQUEST['point']){
		if(!FormWriter::honeypot_check($_REQUEST)){
			throw new SystemDisplayableError(
				'Please leave the "Extra email" field blank.');
		}

		if(!FormWriter::antispam_question_check($_REQUEST)){
			throw new SystemDisplayableError(
				'Please type the correct value into the anti-spam field.');
		}

		$captcha_success = FormWriter::captcha_check($_REQUEST);
		if (!$captcha_success) {
			$errormsg = 'Sorry, you must click the CAPTCHA to submit the form.';
			throw new SystemDisplayableError($errormsg);
		}

	}

	// Validate Urbit point input (numeric 0-4294967295 or @p format)
	$raw_input = trim($_REQUEST['point'] ?? '');
	$query_name = NULL;
	$query_number = NULL;
	$safe_name = NULL;
	$safe_number = NULL;

	if ($raw_input !== '') {
		if (is_numeric($raw_input)) {
			$num = (int)$raw_input;
			if ($num < 0 || $num > 4294967295) {
				throw new SystemDisplayableError('Invalid point number. Must be 0-4294967295.');
			}
			$query_number = (string)$num;
			$safe_number = $query_number;
			$results = json_decode(exec('node '.$node_dir.'/number-to-name.js '.$safe_number));
			$query_name = $results;
			$safe_name = escapeshellarg($query_name);
		} elseif (preg_match('/^~[a-z]{3}$/', $raw_input) ||
		          preg_match('/^~[a-z]{6}$/', $raw_input) ||
		          preg_match('/^~[a-z]{6}-[a-z]{6}$/', $raw_input)) {
			$query_name = $raw_input;
			$safe_name = escapeshellarg($raw_input);
			$results = json_decode(exec('node '.$node_dir.'/name-to-number.js '.$safe_name));
			$query_number = (int)$results;
			$safe_number = (string)$query_number;
		} else {
			throw new SystemDisplayableError('Invalid point format. Use numeric ID or @p name (e.g., ~zod, ~marzod, ~sampel-palnet).');
		}
	}

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Urbit Explorer',
	);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('Urbit Explorer');

	echo '<div class="section padding-top-20">
			<div class="container">';

	if($query_number){
		$point = Point::get_by_id($query_number);

		echo '<h3>Info for point '. htmlspecialchars($query_name, ENT_QUOTES, 'UTF-8') . ' (' . (int)$query_number .')</h3>';

		if($point->get('pnt_clan')){
			if($point->get('pnt_clan') == 1){
				$type = 'galaxy';
			}
			else if($point->get('pnt_clan') == 2){
				$type = 'star';
			}
			else{
				$type = 'planet';
			}
		}
		else{
			$type= json_decode(exec('node '.$node_dir.'/point-type.js '.$safe_name));
			if($type == 'galaxy'){
				$point->set('pnt_clan', 1);
			}
			else if($type == 'star'){
				$point->set('pnt_clan', 2);
			}
			else if($type == 'planet'){
				$point->set('pnt_clan', 3);
			}
		}
		echo 'Type: '.$type.'<br>';

		$results= json_decode(exec('node '.$node_dir.'/point-info.js '.$safe_number));

		if($point && $point->get('pnt_is_active') === NULL){
			echo 'Active: '. ($results->active ? 'yes' : 'no').'<br>';
			$point->set('pnt_is_active', $results->active);
		}
		else if($point->get('pnt_is_active') !== NULL){
			echo 'Active: '. ($point->get('pnt_is_active') ? 'yes' : 'no').'<br>';
		}
		else{
			echo 'Active: '. ($results->active ? 'yes' : 'no').'<br>';
		}

		echo 'Owner: '. htmlspecialchars($results->owner, ENT_QUOTES, 'UTF-8') .'<br>';
		$sponsor= json_decode(exec('node '.$node_dir.'/number-to-name.js '. (int)$results->sponsor));
		echo 'Sponsor: '. htmlspecialchars($sponsor, ENT_QUOTES, 'UTF-8') . '('. (int)$results->sponsor .')<br>';
		if($type == 'star'){
			$point->set('pnt_sein', $results->sponsor);
		}

		if($point){
			$results= json_decode(exec('node '.$node_dir.'/get-spawn-count.js '.$safe_number));
			echo '# points spawned: '. (int)$results .'<br>';
		}

		if($point && $point->get('pnt_is_booted') === NULL){
			$results= json_decode(exec('node '.$node_dir.'/has-been-linked.js '.$safe_number));
			echo 'Has been booted: '. ($results ? 'yes' : 'no').'<br>';
			$point->set('pnt_is_booted', $results);

		}
		else if($point->get('pnt_is_booted') !== NULL){
			echo 'Has been booted: '. ($point->get('pnt_is_booted') ? 'yes' : 'no').'<br>';
		}
		else{
			$results= json_decode(exec('node '.$node_dir.'/has-been-linked.js '.$safe_number));
			echo 'Has been booted: '. ($results ? 'yes' : 'no').'<br>';
		}

		if($point && $point->get('pnt_is_live') === NULL){
			$results= json_decode(exec('node '.$node_dir.'/is-live.js '.$safe_number));
			echo 'Is Live: '. ($results ? 'yes' : 'no').'<br>';
			$point->set('pnt_is_live', $results);
		}
		else if($point->get('pnt_is_live') !== NULL){
			echo 'Is Live: '. ($point->get('pnt_is_live') ? 'yes' : 'no').'<br>';
		}
		else{
			$results= json_decode(exec('node '.$node_dir.'/is-live.js '.$safe_number));
			echo 'Is Live: '. ($results ? 'yes' : 'no').'<br>';
			$point->set('pnt_is_live', $results);
		}
		$point->save();
	}

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/explorer',
		'method' => 'GET'
	]);

	$formwriter->antispam_question_validate([]);

	$formwriter->begin_form();
	echo '<fieldset class="inlineLabels">';
	$formwriter->textinput("point", "Point name or number (with tilde)", [
		'maxlength' => 32
	]);

	$formwriter->antispam_question_input();
	$formwriter->honeypot_hidden_input();

	$formwriter->captcha_hidden_input();
	$formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);
	$formwriter->end_form();

	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>
