<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriterPublic extends FormWriterMaster {

	protected $validate_style_info = '	errorElement: "p",						
							errorClass: "errorField",
							highlight: function(element, errorClass) {
								$("#"+element.name+"_container").addClass("error");

							  },
							  unhighlight: function(element, errorClass) {
								  $("#"+element.name+"_container").removeClass("error");

							  },
							errorPlacement: function(error, element) {
								error.prependTo(element.parents(".errorplacement").eq(0));
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
