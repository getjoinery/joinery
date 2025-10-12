<?php
// PathHelper is already loaded by the time this file is included
// We just need the dependencies
if (!class_exists('FormWriterBootstrap')) {
    require_once(PathHelper::getIncludePath('includes/FormWriterBootstrap.php'));
}

// FormWriter class for ControlD plugin theme
// Extends the base Bootstrap FormWriter with ControlD-specific styling

class FormWriter extends FormWriterBootstrap { 

	public $validate_style_info = '
							ignore: ":hidden:not(input[type=\'checkbox\'], input[type=\'radio\'])",
							errorElement: "p",
							errorClass: "text-danger",
							highlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								$("#"+name+"").addClass("is-invalid");
							  },
							  unhighlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								  $("#"+name+"").removeClass("is-invalid");
							  },
							errorPlacement: function(error, element) {
								error.appendTo(element.parents(".errorplacement").eq(0));
							}';
	
	//FORM STYLING - ControlD Theme styling
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
	// Removed: protected $checkboxinput_input_class = '';
	// Now inherits 'form-check-input' from FormWriterBootstrap for proper validation styling

	protected $checkboxList_container_class = ' form-group';
	protected $checkboxList_wrapper_class = '';
	protected $checkboxList_input_class_checkbox = '';	
	protected $checkboxList_input_class_radio = '';	
	
	protected $timeinput_container_class = ' form-group';
	protected $timeinput_input_class = 'timepicker';
	
	protected $dropinput_container_class = ' form-group';
	protected $dropinput_select_class = 'form-select';
	
	// ControlD button styling
	protected $button_primary_class = 'th-btn';
	protected $button_secondary_class = 'th-btn style2';
	
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
		
		$output = '
			<div class="form-check">
				<input '.$checked.' type="checkbox" class="form-check-input" id="'.$id.'" name="'.$id.'" value="'.$truevalue.'">
				<label class="form-check-label" for="'.$id.'">'.$label.'</label>
			</div>
		';
		if($hint){
			$output .= '<small class="form-text text-muted">'.$hint.'</small>';
		}
		
		return $output;		
	}

	// All button methods are inherited from FormWriterBootstrap
	// This provides a complete FormWriter implementation for ControlD theme
}
?>