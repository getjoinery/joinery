<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublicTW.php');
	require_once(LibraryFunctions::get_logic_file_path('account_edit_logic.php'));	
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/FormWriterPublic.php');	

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Edit Account', 
	);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('Account Edit');
	
	echo '<div class="section padding-top-20">
			<div class="container">';

	foreach($display_messages AS $display_message) {
		if($display_message->identifier == 'userbox') {			
			echo '<div class="'.$display_message->get_message_class().'">'.$display_message->message.'</div>';
		}
	}			
	?>
	
	
	
	
	<script language="javascript">
	 $(document).ready(function() {	
		$('#tab_select').change(function() { 
			<?php
			foreach($tab_menus as $name => $link){
				?>
				if($('#tab_select').val() == "<?php echo $name; ?>"){
					$(location).attr("href","<?php echo $link; ?>");
				}
				<?php
			}
			?>
		});	
	});
	</script>	
<div>
  <div class="sm:hidden">
    <label for="tabs" class="sr-only">Categories</label>
    <!-- Use an "onChange" listener to redirect the user to the selected tab URL. -->
    <select id="tab_select" name="tab_select" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
			<?php
			foreach($tab_menus as $name => $link){
				if($name == $_REQUEST['menu_item']){
				  echo '<option selected>'.$name.'</option>';					
				}
				else{
				  echo '<option>'.$name.'</option>';						
				}
			}
			?>

    </select>
  </div>
  <div class="hidden sm:block">
    <div class="border-b border-gray-200">
      <nav class="-mb-px flex space-x-8" aria-label="Tabs">
        <!-- Current: "border-indigo-500 text-indigo-600", Default: "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300" -->
			<?php
			foreach($tab_menus as $name => $link){
				if($name == $_REQUEST['menu_item']){
				  echo '<a class="border-indigo-500 text-indigo-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" aria-current="page" href="'.$link.'">'.$name.'</a>';					
				}
				else{
				  echo '<a class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" href="'.$link.'">'.$name.'</a>';						
				}
			}
			?>
      </nav>
    </div>
  </div>
</div>		
	

	<?php
	
	$formwriter = new FormWriterPublicTW("form1");
	echo $formwriter->begin_form("", "post", "/logic/users_edit_logic");

	//$optionvals = array(""=>'', "Male"=>0, "Female"=>1);
	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 20, $user->get('usr_first_name'), "",255, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 20, $user->get('usr_last_name'), "" , 255, "");
	echo $formwriter->textinput("Dharma Name", "usr_nickname", "ctrlHolder", 20, $user->get('usr_nickname'), "" , 255, "");
	//echo $formwriter->dropinput("Gender (optional)", "usr_gender", "ctrlHolder", $optionvals, $user->get('usr_gender'), '', FALSE);
	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Your Time Zone", "usr_timezone", "ctrlHolder", $optionvals, $user->get('usr_timezone'), '', FALSE);
	//TODO ALLOW THE USER TO CHANGE EMAILS
	//echo $formwriter->textinput("Email", "usr_email_new", "ctrlHolder", 20, $user->get('usr_email'), "" , 255, "");

	echo $formwriter->new_form_button('Submit');


	echo $formwriter->end_form();

	

	foreach($display_messages AS $display_message) {
		if($display_message->identifier == 'addressbox') {			
			echo '<div class="'.$display_message->get_message_class().'">'.$display_message->message.'</div>';
		}
	}			

	$page->tableheader(array('Address'));
	foreach($addresses as $address){
		$rowvalues = array();
		array_push($rowvalues, $address->get_address_string(', ') . ' (<a href="/profile/address_edit?usa_address_id=' . $address->key . '" >edit</a>)');
		$page->disprow($rowvalues);
	}
		
	$page->endtable();
	if(!$numaddressrecords){
		echo '<a class="add-address" href="/profile/address_edit" title="Add New Address">Add New Address</a>';
	}
	
	
	
	$page->tableheader(array('Phone Number'));
	foreach($phone_numbers as $phone_number){
		$rowvalues = array();
		array_push($rowvalues, $phone_number->get('phn_is_verified') ? $phone_number->get_phone_string() : $phone_number->get_phone_string() . ' (<a href="/profile/phone_numbers_edit.php?phn_phone_number_id='.$phone_number->key.'">edit</a>)');
		$page->disprow($rowvalues);
	}
		
	$page->endtable();
	if(!$numphonerecords){
		echo $formwriter->new_button('Add New Phone Number', '/profile/phone_numbers_edit', 'secondary');
	}
		
	echo '</div></div>';
	echo PublicPage::EndPage();	
	$page->public_footer($foptions=array('track'=>TRUE));
?>
