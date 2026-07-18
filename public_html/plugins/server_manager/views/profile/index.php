<?php
/**
 * /profile/server_manager/ — namespace index.
 *
 * Admins land on the Server Manager dashboard; members land on the Connect
 * page (the plugin's one member-facing page).
 *
 * @version 1.0
 */

$session = SessionControl::get_instance();
if ((int)$session->get_permission() >= 5) {
	LibraryFunctions::Redirect('/admin/server_manager');
} else {
	LibraryFunctions::Redirect('/profile/server_manager/connect_cloud');
}
?>
