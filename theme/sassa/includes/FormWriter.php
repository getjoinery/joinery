<?php
require_once( __DIR__ . '/../../../includes/Globalvars.php');
require_once( __DIR__ . '/../../../includes/DbConnector.php');
require_once( __DIR__ . '/../../../includes/FormWriterMaster.php');

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriter extends FormWriterMaster { 

	public $validate_style_info = 'errorElement: "p",
							errorClass: "formerror",
							highlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								$("#"+name+"_container").addClass("formerror");
							  },
							  unhighlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								  $("#"+name+"_container").removeClass("formerror");
							  },
							errorPlacement: function(error, element) {
								error.appendTo(element.parents(".errorplacement").eq(0));
							}';
	
	//FORM STYLING
	protected $fileinput_container_class = ' form-group';
	protected $fileinput_input_class = '';
	
	protected $text_container_class = ' form-group';
	protected $text_label_class = '';
	
	protected $textintput_container_class_horizontal = 'row';
	protected $textintput_label_class_horizontal = ' form-group';
	protected $textintput_container_class = ' form-group';
	protected $textintput_label_class = '';
	protected $textintput_input_class = '';
	
	protected $textbox_container_class = ' form-group';
	protected $textbox_textarea_class = '';

	protected $checkboxinput_container_class = ' form-group';
	protected $checkboxinput_input_class = '';
	
	protected $checkboxList_container_class = ' form-group';
	protected $checkboxList_wrapper_class = '';
	protected $checkboxList_input_class_checkbox = '';	
	protected $checkboxList_input_class_radio = '';	
	
	protected $timeinput_container_class = ' form-group';
	protected $timeinput_input_class = 'timepicker';
	
	protected $dropinput_container_class = ' form-group';
	protected $dropinput_select_class = 'form-select';
	//protected $dropinput_select_class = 'form-select nice-select';

	function begin_form($class, $method, $action, $charset = 'UTF-8', $onsubmit = NULL){
		$output = '<form class="'.$class.'" id="'. $this->formid.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'">';
		return $output;
	}

	function end_form(){
		return '</form>';
	}
	

	function toggleinput($label, $id, $class, $value, $truevalue, $hint){
		
		if($value == $truevalue){
			$checked = 'checked="checked"'; 
		}
		else{
			$checked = '';
		}
		
		return '<div id="'.$id.'_container" class="switch-area2 errorplacement col-md-6 form-group">
			<label for="'.$id.'" class="toggler toggler--is-active ms-0" >'.$label.'</label>
			<div class="toggle">
				<input id="'.$id.'" name="'.$id.'" type="checkbox" id="switcher" class="check" value="'.$truevalue.'" '.$checked.'>
				<b class="b switch"></b>
			</div>
		</div>';
		
	}
	 
	//STYLE IS 'primary' or 'secondary'
	//WIDTH IS 'standard' or 'full'
	function new_button($label='Submit', $link, $style='primary', $width='standard', $class='th-btn', $id=NULL) {
		
		if($style == 'primary'){
			$class = $this->button_primary_class . ' ' . $class;
		}
		else{
			$class = $this->button_secondary_class . ' ' . $class;
		}
		
		if($width == 'full'){
			$class = 'btn-fw '. $class;
		}
		
		
		$output = '<a href="'.$link.'"><button type="button" class="'.$class.'"';
		if($id != '' && !is_null($id)){
			$output .= ' id="'.$id.'"';
		}
		$output .= '>';
		$output .= $label.'</button></a>';
		return $output;
	}
	
	
	function new_form_button($label='Submit', $style='primary', $width='standard', $class='th-btn', $id=NULL) {
		
		if($style == 'primary'){
			$class = $this->button_primary_class . ' ' . $class;
		}
		else{
			$class = $this->button_secondary_class . ' ' . $class;
		}

		if($width == 'full'){
			$class = 'btn-fw '. $class;
		}		
		
		$output = '<button type="submit" class="'.$class.'"';
		if($id != '' && !is_null($id)){
			$output .= ' id="'.$id.'"';
		}
		$output .= '>';
		$output .= $label.'</button>';
		return $output;
	}
	
}
?>
