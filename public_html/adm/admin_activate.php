<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_activate_logic.php'));

process_logic(admin_activate_logic($_GET, $_POST));

?>
