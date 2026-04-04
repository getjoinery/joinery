<?php
// PathHelper is already loaded by the time this file is included
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

// FormWriter class for ScrollDaddy HTML5 plugin theme
// Extends the base HTML5 FormWriter v2 with ScrollDaddy-specific styling

class FormWriter extends FormWriterV2HTML5 {

	// ScrollDaddy button styling (custom theme classes)
	protected $button_primary_class = 'th-btn';
	protected $button_secondary_class = 'th-btn style2';

	// Inherits all HTML5 form styling from FormWriterV2HTML5
}
?>
