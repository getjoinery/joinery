<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublicTW.php');
	require_once(LibraryFunctions::get_logic_file_path('address_edit_logic.php'));
	
		

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Edit Address'
		);
	$page->public_header($hoptions);

	$options=array();
	$options['subtitle'] = '<a href="/profile/profile">Back to my profile</a>';
	echo PublicPage::BeginPage('Edit Address', $options);

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
	
	$formwriter = new FormWriterPublicTW("form1");
	
	$validation_rules = array();
	$validation_rules['usa_type']['required']['value'] = 'true';
	$validation_rules['usa_city']['required']['value'] = 'true';
	$validation_rules['usa_state']['required']['value'] = 'true';
	$validation_rules['usa_zip_code_id']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);					
	
	echo $formwriter->begin_form("", "post", "/profile/address_edit");

	/*
	if ($address->key) {
		// Don't put the existing address if we are adding a new one
		echo $formwriter->hiddeninput("a", LibraryFunctions::encode($address->key));
	}
	*/

	echo '<div id="newaddressblock">';
	Address::PlainForm($formwriter, $address);
	echo '</div>';

	echo '<a href="/profile/account_edit">Cancel</a> ';
	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();

	$page->endtable();

	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
