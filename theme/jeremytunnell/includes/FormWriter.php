<?php
// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
// Use PathHelper for other includes
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

// jeremytunnell theme uses FormWriterV2Bootstrap for form generation
class FormWriter extends FormWriterV2Bootstrap {
	// All form methods are now inherited from FormWriterV2Bootstrap
	// The theme can override specific methods here if needed for custom styling
}
?>
