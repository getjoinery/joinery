<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublicTW.php');
	require_once(LibraryFunctions::get_logic_file_path('password_edit_logic.php'));

	if ($has_old_password) {
		$page_title = 'Change Password';
	} else {
		$page_title = 'Set Password';
	}

	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'title' => $page_title
	));

	$options=array();
	$options['subtitle'] = '<a href="/profile/profile">Back to my profile</a>';
	echo PublicPage::BeginPage($page_title, $options);
	echo '<div class="section padding-top-20">
			<div class="container">';

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
	
	if($message){
		echo $message;
	}
	else{
		
		$formwriter = new FormWriterPublicTW("form1");
					
		$validation_rules = array();
		if ($has_old_password) {
			$validation_rules['usr_old_password']['required']['value'] = 'true';
		}
		$validation_rules['usr_password']['required']['value'] = 'true';
		$validation_rules['usr_password']['minlength']['value'] = 5;
		$validation_rules['usr_password_again']['required']['value'] = 'true';
		$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
		$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
		$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";
		echo $formwriter->set_validate($validation_rules);					
					
		echo $formwriter->begin_form("", "post", "/profile/password_edit");

		if ($has_old_password) {
			echo $formwriter->passwordinput("Old Password", "usr_old_password", "ctrlHolder", 20, NULL , '',255, "");
		}
		echo $formwriter->passwordinput("New Password", "usr_password", "ctrlHolder", 20, NULL , 'Must be at least 5 characters.',255, "");
		echo $formwriter->passwordinput("Retype New Password", "usr_password_again", "ctrlHolder", 20, "" , "", 255,"");
		echo '<a href="/profile/account_edit">Cancel</a> ';
		echo $formwriter->new_form_button('Submit');

		echo $formwriter->end_form();		
	}	
	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
