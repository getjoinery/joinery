<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_user_login_as_logic.php'));

process_logic(admin_user_login_as_logic($_GET, $_POST));

?>
