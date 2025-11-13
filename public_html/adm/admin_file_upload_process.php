<?php

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('includes/UploadHandler.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_file_upload_process_logic.php'));

	$page_vars = process_logic(admin_file_upload_process_logic($_GET, $_POST));

	if(isset($page_vars['show_fallback']) && $page_vars['show_fallback']){
		$file = $page_vars['file'];
		require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
		$page = new AdminPage();
		$page->admin_header(2);

		echo '<h3>File upload</h3>';
		echo '<p>'.$file->get('fil_name'). ' uploaded successfully.</p>';

		$page->admin_footer();
	}
	exit();

?>
