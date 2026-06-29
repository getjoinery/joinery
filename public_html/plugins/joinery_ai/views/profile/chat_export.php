<?php
/**
 * Member chat export — /profile/joinery_ai/chat_export (GET, JSON). Reuses the
 * admin implementation verbatim (operates only on the caller's own conversation,
 * owner-checked).
 */
require(PathHelper::getIncludePath('plugins/joinery_ai/views/admin/chat_export.php'));
