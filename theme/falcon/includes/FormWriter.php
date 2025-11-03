<?php
// PathHelper is always available - no need to require it
// Load the Bootstrap FormWriter v2 implementation
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

// Falcon theme uses Bootstrap FormWriter v2 directly
class FormWriter extends FormWriterV2Bootstrap {
	// Falcon theme can override specific methods here if needed
	// But by default, it uses all Bootstrap implementations
}
?>
