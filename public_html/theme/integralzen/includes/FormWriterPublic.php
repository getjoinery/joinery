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

	function checkboxinput($label, $id, $class, $align, $value, $truevalue, $hint){
		
		if($value == $truevalue){
			$checked = 'checked="checked"'; 
		}
		else{
			$checked = '';
		}

		return '<div class="uk-margin errorplacement">
                <div id="'.$id.'_container" class="uk-margin uk-grid-small uk-child-width-auto uk-grid">
					<label for="'.$id.'">'.$label.'</label><br>
                    <input class="uk-checkbox" type="checkbox" id="'.$id.'" name="'.$id.'" value="'.$truevalue.'" '.$checked.' '.$this->_get_next_tab_index().' />
					       
				</div>
               </div>';

	}



}
?>
