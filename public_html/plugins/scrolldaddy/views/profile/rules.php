<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('rules_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars = process_logic(rules_logic($_GET, $_POST));
	$context = $page_vars['context'];
	$block_id = $page_vars['block_id'];
	$tier = $page_vars['tier'];
	$device = $page_vars['device'];
	$rules = $page_vars['rules'];
	$user = $page_vars['user'];
	$session = SessionControl::get_instance();

	$is_block_context = ($context == 'block');
	$block = $is_block_context ? $page_vars['block'] : null;

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Custom Rules',
		'breadcrumbs' => array (
			'Devices' => '/profile/scrolldaddy/devices',
			'Custom Rules' => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Custom Rules', $hoptions);

	$name = 'New Device';
	if($device->get('sdd_device_name')){
		$name = $device->get_readable_name();
	}

	if($is_block_context){
		$block_name = htmlspecialchars($block->get('sdb_name') ?: 'Unnamed');
		echo '<h5>Custom Rules for scheduled block: '.$block_name.' ('.$name.')</h5>';
	}
	else{
		echo '<h5>Custom Rules for device '.$name.'</h5>';
	}

	// Determine the hostname field name based on context
	$hostname_field = $is_block_context ? 'sbr_hostname' : 'sdr_hostname';
	$action_field = $is_block_context ? 'sbr_action' : 'sdr_action';

	echo '		  <table class="sd-table"><thead>
			<tr>
			  <th scope="col">Site</th>
			  <th scope="col">Rule</th>
			  <th scope="col">Action</th>
			</tr>
		  </thead>

		  <tbody>';

	foreach($rules as $rule){

			echo '<tr>
			  <td>'.htmlspecialchars($rule->get($hostname_field)).'</td>';
			  if($rule->get($action_field) == 0){
					echo '<td>Block</td>';
			  }
			  else{
				  echo '<td>Allow</td>';
			  }

			  $hidden_fields = '<input type="hidden" name="action" value="delete" />
		<input type="hidden" name="rule_id" value="'.$rule->key.'" />
		<input type="hidden" name="device_id" value="'.$device->key.'" />';
			  if($is_block_context){
				  $hidden_fields .= '<input type="hidden" name="block_id" value="'.$block_id.'" />';
			  }

			  $delform = '<form id="form'.$rule->key.'" method="POST" action="/profile/scrolldaddy/rules">
		'.$hidden_fields.'
		<button type="submit" class="btn-delete">Delete</button>
		</form>';
		echo '<td>'.$delform.'</td>';
			echo '</tr>';

	}

	if(SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_custom_rules', false)){
		if($device->are_filters_editable()){
			$formwriter = $page->getFormWriter('rules_form', ['action' => '/profile/scrolldaddy/rules', 'method' => 'POST']);
			echo $formwriter->begin_form();
			$formwriter->hiddeninput('device_id', '', ['value' => $device->key]);
			if($is_block_context){
				$formwriter->hiddeninput('block_id', '', ['value' => $block_id]);
			}
			echo '<tr class="sd-add-row"><td>';
			echo $formwriter->textinput('sdr_hostname', 'Add Site', ['maxlength' => 255]);

			$optionvals = [
				0 => 'Block',
				1 => 'Allow',
			];
			echo '</td><td>';

			echo $formwriter->dropinput('sdr_action', '', ['options' => $optionvals]);
			echo '</td><td>';
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
