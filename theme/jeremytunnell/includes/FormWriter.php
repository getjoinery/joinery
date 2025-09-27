<?php
// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
// Use PathHelper for other includes
require_once(PathHelper::getIncludePath('includes/FormWriterHTML5.php'));

// jeremytunnell theme uses FormWriterHTML5 for plain HTML forms
class FormWriter extends FormWriterHTML5 {
	// All form methods are now inherited from FormWriterHTML5
	// The theme can override specific methods here if needed for custom styling
}
?>
