<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/PathHelper.php');
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('rules_logic.php', 'logic', 'system', null, 'controld'));

	$page_vars = rules_logic($_GET, $_POST);
	$profile_choice = $page_vars['profile_choice'];
	$profile =  $page_vars['profile'];
	$account = $page_vars['account'];
	$device = $page_vars['device'];
	$rules = $page_vars['rules'];
	$user = $page_vars['user'];
	$session = SessionControl::get_instance();

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Custom Rules', 
		'breadcrumbs' => array (
			'Devices' => '/profile/devices',
			'Custom Rules' => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Custom Rules', $hoptions);
	
	$name = 'New Device';
	if($device->get('cdd_device_name')){
		$name = $device->get_readable_name();
	}

	
	
	
	

	echo '<h5>Custom Rules for device '.$name.'</h5>';
	echo '		  <table class="table"><thead>
			<tr>
			  <th scope="col">Site</th>
			  <th scope="col">Rule</th>
			  <th scope="col">Action</th>
			</tr>
		  </thead>

		  <tbody>';
		  
	foreach($rules as $rule){

			echo '<tr>
			  <td>'.$rule->get('cdr_rule_hostname').'</td>';
			  if($rule->get('cdr_rule_action') == 0){
					echo '<td>Block</td>';
			  }
			  else{
				  echo '<td>Allow</td>';
			  }
			  
			  $delform = '<form id="form'.$rule->key.'" class="form'.$rule->key.'" name="form'.$rule->key.'" method="POST" action="/profile/rules">
		<input type="hidden" class="hidden" name="action" id="action" value="delete" />
		<input type="hidden" class="hidden" name="profile_choice" id="profile_choice" value="'.$profile_choice.'" />
		<input type="hidden" class="hidden" name="rule_id" id="rule_id" value="'.$rule->key.'" />
		<input type="hidden" class="hidden" name="device_id" id="device_id" value="'.$device->key.'" />
		<button type="submit">Delete</button>
		</form>';
		echo '<td>'.$delform.'</td>';
			echo '</tr>';

	}

	if($account->get('cda_plan') == CtldAccount::PRO_PLAN){
		if($device->are_filters_editable()){
			$formwriter = $page->getFormWriter();
			$validation_rules = array();
			$validation_rules['cdr_rule_hostname']['required']['value'] = 'true';
			$validation_rules['cdr_rule_action']['required']['value'] = 'true';	
			echo $formwriter->set_validate($validation_rules);	
			echo $formwriter->begin_form('contact-form style2', 'POST', '/profile/rules', true);
			echo $formwriter->hiddeninput('device_id', $device->key);
			echo $formwriter->hiddeninput('profile_choice', $profile_choice);
			echo '<tr><td>';
			echo $formwriter->textinput('Add Site', 'cdr_rule_hostname', NULL, 100, '', '', 255, '');	

			$optionvals = [
				'Block' => 0,
				'Allow' => 1,
			];
			echo '</td><td>';
			
			echo $formwriter->dropinput("&nbsp;", "cdr_rule_action", "", $optionvals, NULL, '', FALSE);	
			echo '</td><td>';
			echo '<br>';
			echo $formwriter->new_form_button('New Rule', 'th-btn');
			
			echo '</td></tr>';
			echo $formwriter->end_form(true);	
		}
		else{
			echo '<div class="alert alert-warning" role="alert">
			  Since you have chosen to allow edits only on Sunday.  Edits are disabled, except for ad and malware blocking.
			</div>';
		}		
	}		
	
	echo '		  </tbody>
		</table>	';	
	

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
