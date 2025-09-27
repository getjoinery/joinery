<?php
// PathHelper is always available - no need to require it
// Load the Bootstrap FormWriter implementation
require_once(PathHelper::getIncludePath('includes/FormWriterBootstrap.php'));

// Falcon theme uses Bootstrap FormWriter directly
class FormWriter extends FormWriterBootstrap {
	// Falcon theme can override specific methods here if needed
	// But by default, it uses all Bootstrap implementations
}
?>
