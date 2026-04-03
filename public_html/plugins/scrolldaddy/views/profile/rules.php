<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('rules_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars = process_logic(rules_logic($_GET, $_POST));
	$profile_choice = $page_vars['profile_choice'];
	$profile =  $page_vars['profile'];
	$tier = $page_vars['tier'];
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
	if($device->get('sdd_device_name')){
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
			  <td>'.$rule->get('sdr_hostname').'</td>';
			  if($rule->get('sdr_action') == 0){
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

	if(SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_custom_rules', false)){
		if($device->are_filters_editable()){
			$formwriter = $page->getFormWriter('rules_form', ['action' => '/profile/rules', 'method' => 'POST']);
			echo $formwriter->begin_form();
			$formwriter->hiddeninput('device_id', '', ['value' => $device->key]);
			$formwriter->hiddeninput('profile_choice', '', ['value' => $profile_choice]);
			echo '<tr><td>';
			echo $formwriter->textinput('sdr_hostname', 'Add Site', ['maxlength' => 255]);

			$optionvals = [
				'Block' => 0,
				'Allow' => 1,
			];
			echo '</td><td>';

			echo $formwriter->dropinput('sdr_action', '&nbsp;', ['options' => $optionvals]);
			echo '</td><td>';
			echo '<br>';
			echo $formwriter->submitbutton('btn_submit', 'New Rule', ['class' => 'btn btn-primary']);
			
			echo '</td></tr>';
			echo $formwriter->end_form();
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
