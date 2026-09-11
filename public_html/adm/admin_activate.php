<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_activate_logic.php'));

process_logic(admin_activate_logic(array_merge($_GET, $_POST)));

?>
