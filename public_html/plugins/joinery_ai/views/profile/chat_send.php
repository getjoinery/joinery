<?php
/**
 * Member chat send — /profile/joinery_ai/chat_send (POST, JSON).
 * Reuses the admin implementation verbatim; member safety (owner-scoped reads,
 * no action surface) is enforced downstream in ChatTurnContext.
 */
require(PathHelper::getIncludePath('plugins/joinery_ai/views/admin/chat_send.php'));
