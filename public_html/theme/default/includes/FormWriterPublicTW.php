<?php
require_once( __DIR__ . '/../../../includes/Globalvars.php');
require_once( __DIR__ . '/../../../includes/DbConnector.php');
require_once( __DIR__ . '/../../../includes/FormWriterMasterTW.php');

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriterPublicTW extends FormWriterMasterTW {

	/*
	protected $validate_style_info = 'errorElement: "span",
							errorClass: "text-red-500",
							highlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								$("#"+name+"_container").addClass("border-red-500");
							  },
							  unhighlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								  $("#"+name+"_container").removeClass("border-red-500");
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
	*/


}
?>
