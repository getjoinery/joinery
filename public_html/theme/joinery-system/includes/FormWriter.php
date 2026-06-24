<?php
// Load the HTML5 FormWriter v2 implementation (the single core HTML renderer)
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

// joinery-system uses the core HTML5 FormWriter; its CSS styles the emitted classes
class FormWriter extends FormWriterV2HTML5 {
    // Override render methods here if the admin theme ever needs to
}
?>
