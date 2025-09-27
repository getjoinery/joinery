<?php

	// SessionControl is now guaranteed available - line removed
	// LibraryFunctions is now guaranteed available - line removed
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
	
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

	if(is_numeric($_REQUEST['point'])){
		$query_number = (int)$_REQUEST['point'];
		$results= json_decode(exec('node '.$node_dir.'/number-to-name.js '.$query_number));
		$query_name = $results;
	}
	else if ($_REQUEST['point']) {
		$query_name = $_REQUEST['point'];
		$results= json_decode(exec('node '.$node_dir.'/name-to-number.js '.$query_name));
		$query_number = $results;		
	}
	else{
		$query_name = NULL;
		$query_number = NULL;
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
		
		echo '<h3>Info for point '. $query_name . ' (' . $query_number .')</h3>';
		
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
			$type= json_decode(exec('node '.$node_dir.'/point-type.js '.$query_name));
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
		
		$results= json_decode(exec('node '.$node_dir.'/point-info.js '.$query_number));
		
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
		
		echo 'Owner: '. $results->owner.'<br>';
		$sponsor= json_decode(exec('node '.$node_dir.'/number-to-name.js '.$results->sponsor));
		echo 'Sponsor: '. $sponsor . '('.$results->sponsor.')<br>';
		if($type == 'star'){
			$point->set('pnt_sein', $results->sponsor);
		}

		if($point){
			$results= json_decode(exec('node '.$node_dir.'/get-spawn-count.js '.$query_number));
			echo '# points spawned: '. $results.'<br>';
		}

		if($point && $point->get('pnt_is_booted') === NULL){
			$results= json_decode(exec('node '.$node_dir.'/has-been-linked.js '.$query_number));
			echo 'Has been booted: '. ($results ? 'yes' : 'no').'<br>';
			$point->set('pnt_is_booted', $results);		

		}
		else if($point->get('pnt_is_booted') !== NULL){
			echo 'Has been booted: '. ($point->get('pnt_is_booted') ? 'yes' : 'no').'<br>';
		}
		else{
			$results= json_decode(exec('node '.$node_dir.'/has-been-linked.js '.$query_number));
			echo 'Has been booted: '. ($results ? 'yes' : 'no').'<br>';
		}

		if($point && $point->get('pnt_is_live') === NULL){
			$results= json_decode(exec('node '.$node_dir.'/is-live.js '.$query_number));
			echo 'Is Live: '. ($results ? 'yes' : 'no').'<br>';
			$point->set('pnt_is_live', $results);
		}
		else if($point->get('pnt_is_live') !== NULL){
			echo 'Is Live: '. ($point->get('pnt_is_live') ? 'yes' : 'no').'<br>';
		}	
		else{
			$results= json_decode(exec('node '.$node_dir.'is-live.js '.$query_number));
			echo 'Is Live: '. ($results ? 'yes' : 'no').'<br>';
			$point->set('pnt_is_live', $results);
		}	
		$point->save();
	}

	$formwriter = new FormWriter("form1", TRUE);
	
	$validation_rules = array();
	$validation_rules['point']['required']['value'] = 'true';
	$validation_rules = FormWriter::antispam_question_validate($validation_rules);
	echo $formwriter->set_validate($validation_rules);		
	
	echo $formwriter->begin_form("", "get", "/explorer");
	echo '<fieldset class="inlineLabels">';
	echo $formwriter->textinput("Point name or number (with tilde)", "point", "ctrlHolder", 30, '', "", 32, "");
	
	echo $formwriter->antispam_question_input();
	echo $formwriter->honeypot_hidden_input();
	
	echo $formwriter->captcha_hidden_input();
	echo $formwriter->new_form_button('Submit', 'button button-lg button-dark', 'submit1');
	echo $formwriter->end_form();

	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>