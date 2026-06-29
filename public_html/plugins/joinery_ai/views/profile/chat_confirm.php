<?php
/**
 * Member chat confirm — /profile/joinery_ai/chat_confirm (POST, JSON).
 * Reuses the admin implementation verbatim; member safety is enforced
 * downstream in ChatTurnContext.
 */
require(PathHelper::getIncludePath('plugins/joinery_ai/views/admin/chat_confirm.php'));
