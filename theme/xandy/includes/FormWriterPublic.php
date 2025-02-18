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

	//FORM STYLING
	protected $fileinput_container_class = '';
	protected $fileinput_input_class = '';
	
	protected $text_container_class = '';
	protected $text_label_class = '';
	
	protected $textintput_container_class_horizontal = '';
	protected $textintput_label_class_horizontal = '';
	protected $textintput_container_class = '';
	protected $textintput_label_class = '';
	protected $textintput_input_class = '';
	
	protected $textbox_container_class = '';
	protected $textbox_textarea_class = '';

	protected $checkboxinput_container_class = '';
	protected $checkboxinput_input_class = '';
	
	protected $checkboxList_container_class = '';
	protected $checkboxList_wrapper_class = '';
	protected $checkboxList_input_class_checkbox = '';	
	protected $checkboxList_input_class_radio = '';	
	
	protected $timeinput_container_class = '';
	protected $timeinput_input_class = 'timepicker';
	
	protected $dropinput_container_class = '';
	protected $dropinput_select_class = '';

	function begin_form($class, $method, $action, $charset = 'UTF-8', $onsubmit = NULL){
		$output = '<form id="'. $this->formid.'" class="'.$class.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'">';
		return $output;
	}

	function end_form(){
		return '</form>';
	}



}
?>
