<?php
/**
 * Member chat cancel — /profile/joinery_ai/chat_cancel (POST, JSON). Reuses the
 * admin implementation verbatim (operates only on the caller's own turn,
 * owner-checked).
 */
require(PathHelper::getIncludePath('plugins/joinery_ai/views/admin/chat_cancel.php'));
