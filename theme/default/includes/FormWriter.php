<?php
// PathHelper, Globalvars, DbConnector and other core files are always available - no need to require them
PathHelper::requireOnce('includes/FormWriterHTML5.php');


// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriter extends FormWriterHTML5 {

	public $validate_style_info = 'errorElement: "p",
							errorClass: "form-container",
							highlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								$("#"+name+"_container").addClass("bg-danger-light");
							  },
							  unhighlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								  $("#"+name+"_container").removeClass("bg-danger-light");
							  },
							errorPlacement: function(error, element) {
								error.appendTo(element.parents(".errorplacement").eq(0));
							}';

	function begin_form($class, $method, $action, $charset = 'UTF-8', $onsubmit = NULL){
		$output = '<form id="'. $this->formid.'" class="'.$class.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'">';
		return $output;
	}

	function end_form(){
		return '</form>';
	}



}
?>
